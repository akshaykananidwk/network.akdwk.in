<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Logger;
use App\Middleware\RateLimitMiddleware;
use App\Core\Request;
use App\Core\Response;
use App\Models\AgentRelease;
use App\Models\Device;
use App\Models\Network;
use App\Models\UsageCounter;
use App\Services\DeviceService;

/**
 * Endpoints the agent calls. No user session is involved.
 *
 * /enroll is the only unauthenticated one; everything else requires the device
 * token issued at approval. The split matters: enrolment must work before a
 * device has any credential, and must therefore hand back nothing useful.
 */
final class AgentController
{
    /**
     * Enrol a new device (R4: the result is always "pending" unless the
     * network explicitly opted into auto-approval).
     */
    public function enroll(Request $request): Response
    {
        $input = $request->all();

        // A failed enrolment is the thing worth throttling: it means a join
        // code that does not exist, has expired or is used up, which is the
        // only shape enumeration can take. A successful one required a code an
        // administrator issued, and the code carries its own use limit.
        try {
            $result = DeviceService::enroll([
                'join_code'      => $input['join_code'] ?? '',
                'public_key'     => $input['public_key'] ?? '',
                'hostname'       => $input['hostname'] ?? null,
                'os'             => $input['os'] ?? null,
                'os_version'     => $input['os_version'] ?? null,
                'arch'           => $input['arch'] ?? null,
                'agent_version'  => $input['agent_version'] ?? null,
                'hw_fingerprint' => $input['hw_fingerprint'] ?? null,
            ]);
        } catch (\Throwable $e) {
            RateLimitMiddleware::recordEnrolmentFailure($request->ip());

            throw $e;
        }

        Logger::info('agent', 'Device enrolled', [
            'device_uid' => $result['device_uid'],
            'status'     => $result['status'],
            'ip'         => $request->ip(),
        ]);

        return Response::api($result, ['poll_after' => $result['poll_after']], 201);
    }

    /**
     * Poll for approval and collect a device token.
     *
     * An agent that is still pending gets nothing but "keep waiting". Once
     * approved, the token is returned once and the agent stores it; asking
     * again rotates it, which is the intended recovery path for a lost token.
     */
    public function claim(Request $request): Response
    {
        $input = $request->all();
        $deviceUid = (string) ($input['device_uid'] ?? '');
        $publicKey = (string) ($input['public_key'] ?? '');

        if ($deviceUid === '' || $publicKey === '') {
            return Response::apiError('device_uid and public_key are required.', 400);
        }

        // The public key is the proof of identity here: only the machine
        // holding the matching private key could have generated it.
        $device = Device::findByPublicKey($publicKey);

        if ($device === null || (string) $device['device_uid'] !== $deviceUid) {
            // A claim for a key nobody enrolled is a probe, not a poll.
            RateLimitMiddleware::recordEnrolmentFailure($request->ip());

            return Response::apiError('No matching enrolment.', 404, 'not_enrolled');
        }

        if ($device['status'] !== 'authorized') {
            return Response::api([
                'status'     => $device['status'],
                'authorized' => false,
            ], ['poll_after' => 15]);
        }

        $token = Device::issueToken((int) $device['id']);

        Logger::info('agent', 'Device token issued', ['device_uid' => $deviceUid]);

        return Response::api([
            'status'       => 'authorized',
            'authorized'   => true,
            'device_token' => $token,
            'virtual_ip'   => $device['virtual_ip'],
        ]);
    }

    /**
     * Full configuration for an authorized device.
     *
     * The agent sends its last known revision; when it matches, we answer
     * "unchanged" rather than re-sending a peer list. At 10k devices that is
     * the difference between a trivial query and a serious one.
     */
    public function config(Request $request): Response
    {
        $device = $request->deviceContext();
        if ($device === null) {
            return Response::apiError('Device context missing.', 401);
        }

        $networkId = $device['network_id'] !== null ? (int) $device['network_id'] : null;
        if ($networkId === null) {
            return Response::apiError('This device is not attached to a network.', 409, 'no_network');
        }

        $network = Network::find($networkId);
        if ($network === null) {
            return Response::apiError('Network not found.', 404);
        }

        $knownRevision = (int) ($request->query('revision', '0') ?? 0);
        $currentRevision = (int) $network['config_revision'];

        if ($knownRevision > 0 && $knownRevision === $currentRevision) {
            return Response::api([
                'changed'  => false,
                'revision' => $currentRevision,
            ], ['poll_after' => 10]);
        }

        $config = DeviceService::buildAgentConfig($device);
        $config['changed'] = true;

        // The agent caches this and keeps running on it if we become
        // unreachable (R6). Signing it means a spoofed controller cannot
        // inject peers into that cache.
        $config['signature'] = $this->signConfig($config);

        Device::update((int) $device['id'], ['config_revision' => $currentRevision]);

        return Response::api($config, ['poll_after' => 10]);
    }

    /**
     * Heartbeat: liveness, connection type and traffic counters.
     *
     * Byte counts are deltas, applied in place, so two heartbeats racing
     * cannot lose one another's increment.
     */
    public function heartbeat(Request $request): Response
    {
        $device = $request->deviceContext();
        if ($device === null) {
            return Response::apiError('Device context missing.', 401);
        }

        $input = $request->all();

        $connectionType = (string) ($input['connection_type'] ?? 'offline');
        if (!in_array($connectionType, ['direct', 'relay', 'offline'], true)) {
            $connectionType = 'offline';
        }

        $rxDelta = max(0, (int) ($input['rx_delta'] ?? 0));
        $txDelta = max(0, (int) ($input['tx_delta'] ?? 0));

        Device::heartbeat(
            (int) $device['id'],
            (string) ($input['endpoint'] ?? ''),
            isset($input['lan_endpoint']) ? (string) $input['lan_endpoint'] : null,
            $connectionType,
            isset($input['relay_id']) ? (int) $input['relay_id'] : null,
            isset($input['latency_ms']) ? (int) $input['latency_ms'] : null,
            $rxDelta,
            $txDelta,
            isset($input['agent_version']) ? (string) $input['agent_version'] : null
        );

        // Only relayed traffic is metered: direct peer-to-peer bytes never
        // touch our infrastructure, so billing for them would be dishonest.
        //
        // The agent's figure is recorded under its own metric and is *not*
        // what a customer is billed on — the relay reports that, because the
        // agent runs on hardware the customer owns and the one number they
        // must not be able to influence is their own invoice.
        if ($connectionType === 'relay' && ($rxDelta + $txDelta) > 0) {
            UsageCounter::increment((int) $device['tenant_id'], UsageCounter::METRIC_RELAY_BYTES_AGENT, $rxDelta + $txDelta);
        } elseif ($connectionType === 'direct' && ($rxDelta + $txDelta) > 0) {
            UsageCounter::increment((int) $device['tenant_id'], UsageCounter::METRIC_DIRECT_BYTES, $rxDelta + $txDelta);
        }

        $network = $device['network_id'] !== null ? Network::find((int) $device['network_id']) : null;
        $currentRevision = $network !== null ? (int) $network['config_revision'] : 0;

        return Response::api([
            'ok'            => true,
            'revision'      => $currentRevision,
            'config_stale'  => $currentRevision !== (int) $device['config_revision'],
            'server_time'   => gmdate('c'),
        ], ['poll_after' => 10]);
    }

    /**
     * Endpoint exchange for NAT traversal.
     *
     * The agent reports the reflexive address it learned from the coordinator's
     * STUN-like probe; we store it so peers can attempt a direct punch. The
     * actual hole punching happens in the Go coordinator — PHP cannot hold the
     * UDP socket that requires.
     */
    public function endpoint(Request $request): Response
    {
        $device = $request->deviceContext();
        if ($device === null) {
            return Response::apiError('Device context missing.', 401);
        }

        $input = $request->all();
        $endpoint = (string) ($input['endpoint'] ?? '');
        $lanEndpoint = isset($input['lan_endpoint']) ? (string) $input['lan_endpoint'] : null;

        if ($endpoint !== '' && !$this->looksLikeEndpoint($endpoint)) {
            return Response::apiError('endpoint must be host:port.', 400);
        }

        Device::update((int) $device['id'], []);
        Device::heartbeat(
            (int) $device['id'],
            $endpoint,
            $lanEndpoint,
            (string) $device['connection_type'],
            $device['relay_id'] !== null ? (int) $device['relay_id'] : null,
            $device['latency_ms'] !== null ? (int) $device['latency_ms'] : null,
            0,
            0,
            null
        );

        $peers = $device['network_id'] !== null
            ? Device::peersFor((int) $device['network_id'], (int) $device['id'])
            : [];

        return Response::api([
            'peers' => array_map(static fn (array $peer): array => [
                'uid'          => $peer['device_uid'],
                'public_key'   => $peer['public_key'],
                'endpoint'     => $peer['last_endpoint'],
                'lan_endpoint' => $peer['last_lan_endpoint'],
                'virtual_ip'   => $peer['virtual_ip'],
            ], $peers),
            'coordinator' => [
                'host' => Config::get('coordinator.public_host', Config::get('coordinator.host')),
                'port' => (int) Config::get('coordinator.port', 8443),
            ],
        ]);
    }

    /** The agent's own update channel (§14) — separate from the panel's. */
    public function version(Request $request): Response
    {
        $device = $request->deviceContext();
        if ($device === null) {
            return Response::apiError('Device context missing.', 401);
        }

        $channel = (string) ($request->query('channel', 'stable'));
        $platform = (string) ($request->query('platform', (string) $device['os']));
        $arch = (string) ($request->query('arch', (string) $device['arch']));

        $release = AgentRelease::latestFor(
            in_array($channel, ['stable', 'beta', 'dev'], true) ? $channel : 'stable',
            $platform,
            $arch,
            (string) $device['device_uid']
        );

        if ($release === null) {
            return Response::api(['update_available' => false]);
        }

        $current = (string) ($device['agent_version'] ?? '0.0.0');
        $available = version_compare($current, (string) $release['version'], '<');

        return Response::api([
            'update_available' => $available,
            'version'          => $release['version'],
            'url'              => $available ? url('agent/download/' . $release['id']) : null,
            // The agent verifies BOTH before swapping its binary. An unsigned
            // or mismatched download is refused and reported.
            'sha256'           => $release['sha256'],
            'signature'        => $release['signature'],
            'size'             => (int) $release['file_size'],
            'notes'            => $release['release_notes'],
        ]);
    }

    /**
     * Sign the config blob so the agent's offline cache cannot be poisoned.
     *
     * Without a controller signing key configured we return null rather than a
     * fake signature — an agent that requires signatures will then refuse the
     * config, which is the correct failure direction.
     */
    private function signConfig(array $config): ?string
    {
        $secret = (string) Config::get('coordinator.signing_key', '');
        if ($secret === '') {
            return null;
        }

        try {
            unset($config['signature']);

            return Crypto::sign((string) json_encode($config, JSON_UNESCAPED_SLASHES), $secret);
        } catch (\Throwable $e) {
            Logger::error('agent', 'Config signing failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function looksLikeEndpoint(string $endpoint): bool
    {
        return preg_match('/^\[?[0-9a-fA-F:.]+\]?:\d{1,5}$/', $endpoint) === 1;
    }
}
