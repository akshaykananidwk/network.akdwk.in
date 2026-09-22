<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Logger;
use App\Models\UsageCounter;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Services\AclService;

/**
 * The panel's interface to the coordinator service (§7.3).
 *
 * The coordinator introduces peers to one another. To do that safely it has to
 * ask the panel two questions it cannot answer itself: is this device still
 * allowed on the network, and which peers is it allowed to be told about.
 * Keeping both answers here means the ACL is enforced in one place, and a
 * device revoked in the panel stops being introduced within one poll.
 */
final class CoordinatorController
{
    /**
     * Verify a device's token and return the peers it may learn about.
     *
     * The coordinator calls this when an agent says hello. A "no" here is what
     * makes revocation take effect on the data plane.
     */
    public function verifyDevice(Request $request): Response
    {
        $input = $request->all();
        $deviceUid = (string) ($input['device_uid'] ?? '');
        $token = (string) ($input['token'] ?? '');
        $publicKey = (string) ($input['public_key'] ?? '');

        if ($deviceUid === '' || $token === '' || $publicKey === '') {
            return Response::apiError('device_uid, token and public_key are required.', 400);
        }

        // Looked up across tenants because the coordinator serves all of them;
        // every answer below is then scoped to the tenant that owns the device.
        $device = TenantScope::acrossAllTenants(
            'coordinator device verification',
            static fn (): ?array => Device::findByToken($token)
        );

        if ($device === null || (string) $device['device_uid'] !== $deviceUid) {
            return $this->deny($deviceUid, 'no device matches that token');
        }

        // The public key in the request must be the one on file. Without this
        // check a stolen token could be used to register a different key and
        // hijack the device's place in the mesh.
        if (!hash_equals((string) $device['public_key'], $publicKey)) {
            return $this->deny($deviceUid, 'public key does not match the enrolment');
        }

        if ($device['status'] !== 'authorized') {
            return $this->deny($deviceUid, 'device status is ' . $device['status']);
        }

        return $this->describe($device);
    }

    /** @param array<string,mixed> $device */
    private function describe(array $device): Response
    {
        $tenantId = (int) $device['tenant_id'];
        $networkId = (int) $device['network_id'];
        $deviceId = (int) $device['id'];

        $peers = TenantScope::asTenant($tenantId, static function () use ($networkId, $deviceId): array {
            return array_map(
                static fn (array $p): array => [
                    'device_uid' => $p['uid'],
                    'public_key' => $p['public_key'],
                ],
                AclService::buildPeerSet($networkId, $deviceId)
            );
        });

        return Response::api([
            'authorized' => true,
            'device_uid' => $device['device_uid'],
            'network_id' => $networkId,
            'tenant_id'  => $tenantId,
            // The coordinator picks a relay near the device, not near itself.
            'region'     => (string) ($device['region'] ?? ''),
            'virtual_ip' => $device['virtual_ip'],
            // Only the peers the ACL already allows. The coordinator never
            // learns about devices this one may not talk to, so it cannot leak
            // their addresses even by accident.
            'peers'      => $peers,
        ]);
    }

    /**
     * Record what a relay carried, per tenant.
     *
     * This is the source of record for relayed-byte billing. It arrives from
     * the coordinator, which authenticates with the shared secret, and carries
     * figures the relay measured on our own hardware.
     *
     * The agents report the same traffic from their side under a separate
     * metric. The two are compared here rather than silently reconciled: a
     * disagreement beyond the tolerance means either a modified agent or a
     * fault in our own accounting, and both are worth an operator's attention.
     */
    public function reportRelayUsage(Request $request): Response
    {
        $input = $request->all();
        $relay = (string) ($input['relay'] ?? '');
        $tenants = $input['tenants'] ?? [];

        if ($relay === '' || !is_array($tenants)) {
            return Response::apiError('relay and tenants are required.', 400);
        }

        $recorded = 0;
        foreach ($tenants as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $tenantId = (int) ($entry['tenant_id'] ?? 0);
            $bytes = (int) ($entry['bytes'] ?? 0);
            if ($tenantId <= 0 || $bytes <= 0) {
                continue;
            }

            UsageCounter::increment($tenantId, UsageCounter::METRIC_RELAY_BYTES, $bytes);
            $recorded++;

            self::flagUsageDisagreement($tenantId, $relay);
        }

        Logger::info('billing', 'Relay usage recorded', ['relay' => $relay, 'tenants' => $recorded]);

        return Response::api(['ok' => true, 'tenants' => $recorded]);
    }

    /**
     * Compare the billed figure against the agents' own view of it.
     *
     * The tolerance is the one the lab measured the two counts agreeing
     * within, rounded up to a round number. Below it the difference is
     * sampling; above it something is wrong and saying so is more use than
     * picking a winner.
     */
    private static function flagUsageDisagreement(int $tenantId, string $relay): void
    {
        $billed = UsageCounter::valueFor($tenantId, UsageCounter::METRIC_RELAY_BYTES);
        $reported = UsageCounter::valueFor($tenantId, UsageCounter::METRIC_RELAY_BYTES_AGENT);

        // Nothing to compare until both sides have said something. Early in a
        // period one of them is always ahead.
        if ($billed < self::USAGE_COMPARE_FLOOR || $reported < self::USAGE_COMPARE_FLOOR) {
            return;
        }

        $spread = (int) round(abs($billed - $reported) * 100 / max($billed, 1));
        if ($spread <= self::USAGE_TOLERANCE_PERCENT) {
            return;
        }

        Logger::warning('billing', 'Relay and agent byte counts disagree', [
            'tenant_id'     => $tenantId,
            'relay'         => $relay,
            'billed_bytes'  => $billed,
            'agent_bytes'   => $reported,
            'spread_percent' => $spread,
            'note'          => $reported < $billed
                ? 'agents are reporting less than the relay carried'
                : 'agents are reporting more than the relay carried',
        ]);
    }

    /** Below this many bytes the comparison is noise, not evidence. */
    private const USAGE_COMPARE_FLOOR = 1048576;

    /** Measured agreement in the lab was 0%; this is the alarm threshold. */
    private const USAGE_TOLERANCE_PERCENT = 10;

    private function deny(string $deviceUid, string $why): Response
    {
        Logger::info('coordinator', 'Device verification denied', [
            'device_uid' => $deviceUid,
            'reason'     => $why,
        ]);

        // The coordinator is trusted infrastructure, so it gets the reason —
        // it needs to tell a stale agent to re-enrol rather than retry.
        return Response::api(['authorized' => false, 'reason' => $why]);
    }

    /**
     * Report the endpoints the coordinator has observed, so the panel can show
     * them in the device list and hand them to agents that ask for config.
     */
    public function reportEndpoints(Request $request): Response
    {
        $input = $request->all();
        $updates = $input['devices'] ?? [];

        if (!is_array($updates)) {
            return Response::apiError('devices must be an array.', 400);
        }

        $applied = 0;
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $applied += $this->applyEndpoint($update) ? 1 : 0;
        }

        return Response::api(['updated' => $applied]);
    }

    /** @param array<string,mixed> $update */
    private function applyEndpoint(array $update): bool
    {
        $uid = (string) ($update['device_uid'] ?? '');
        if ($uid === '') {
            return false;
        }

        $device = TenantScope::acrossAllTenants(
            'coordinator endpoint report',
            static fn (): ?array => Device::findByUid($uid)
        );

        if ($device === null) {
            return false;
        }

        $endpoint = isset($update['endpoint']) && is_string($update['endpoint'])
            ? $update['endpoint']
            : null;
        $lanEndpoint = isset($update['lan_endpoint']) && is_string($update['lan_endpoint'])
            ? $update['lan_endpoint']
            : null;

        // Whether the coordinator's replies are reaching this device.
        //
        // Only the coordinator can say this: it sees both halves — the
        // announcements arriving and the device re-announcing as though none
        // had been answered. The device itself does not know a reply was ever
        // sent, which is why it can only report "waiting" about the same
        // fault.
        $tenantId = (int) $device['tenant_id'];
        $deviceId = (int) $device['id'];

        if (($update['unanswered_known'] ?? false) === true) {
            $unanswered = ($update['unanswered'] ?? false) === true;

            TenantScope::asTenant($tenantId, static function () use ($deviceId, $unanswered): void {
                Device::recordUnanswered($deviceId, $unanswered);
            });
        }

        return (bool) TenantScope::asTenant(
            $tenantId,
            static fn (): bool => Device::recordEndpoint($deviceId, $endpoint, $lanEndpoint)
        );
    }
}
