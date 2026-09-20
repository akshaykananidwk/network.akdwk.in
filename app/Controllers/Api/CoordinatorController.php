<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Logger;
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
            'virtual_ip' => $device['virtual_ip'],
            // Only the peers the ACL already allows. The coordinator never
            // learns about devices this one may not talk to, so it cannot leak
            // their addresses even by accident.
            'peers'      => $peers,
        ]);
    }

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

        $fields = [];
        if (isset($update['endpoint']) && is_string($update['endpoint'])) {
            $fields['last_endpoint'] = $update['endpoint'];
        }
        if (isset($update['lan_endpoint']) && is_string($update['lan_endpoint'])) {
            $fields['last_lan_endpoint'] = $update['lan_endpoint'];
        }

        if ($fields === []) {
            return false;
        }

        $tenantId = (int) $device['tenant_id'];
        $deviceId = (int) $device['id'];

        TenantScope::asTenant($tenantId, static function () use ($deviceId, $fields): void {
            Device::update($deviceId, $fields);
        });

        return true;
    }
}
