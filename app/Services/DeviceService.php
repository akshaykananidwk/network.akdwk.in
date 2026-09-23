<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Logger;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Relay;
use App\Models\NetworkRoute;

/**
 * Device enrolment, approval and configuration hand-off.
 *
 * The invariant this class exists to protect (R4): an enrolling agent receives
 * nothing usable until an administrator approves it. enroll() returns a device
 * uid and "pending" — no address, no token, no peer list.
 */
final class DeviceService
{
    /**
     * Step 1 of enrolment. Called by the agent with no prior identity.
     *
     * @param array<string,mixed> $input
     * @return array{device_uid:string,status:string,network_uid:string,poll_after:int}
     */
    public static function enroll(array $input): array
    {
        $code = strtoupper(trim((string) ($input['join_code'] ?? '')));
        $publicKey = (string) ($input['public_key'] ?? '');

        if (!Crypto::isValidCurve25519PublicKey($publicKey)) {
            throw new ValidationException(['public_key' => 'Must be a base64-encoded 32-byte Curve25519 public key.']);
        }

        // Looked at before it is used. A device re-presenting a code it has
        // already redeemed is the upgrade case — the installer run again over
        // an existing install — and burning a use for it would make a
        // single-use code refuse the very machine it had just admitted.
        $joinCode = JoinCode::peek($code);

        // A spent code still identifies its network, and that is enough *if*
        // this key is already on it. The public-key check below is what makes
        // this safe: a spent code admits nobody new.
        if ($joinCode === null) {
            $spent = JoinCode::peekIncludingSpent($code);
            if ($spent !== null && self::keyBelongsToTenant($publicKey, (int) $spent['tenant_id'])) {
                $joinCode = $spent;
            }
        }

        if ($joinCode === null) {
            // Deliberately vague: a valid-looking code should not be
            // distinguishable from an expired one by an enumerating client.
            throw new ValidationException(['join_code' => 'That join code is not valid or has expired.']);
        }

        $tenantId = (int) $joinCode['tenant_id'];
        $networkId = (int) $joinCode['network_id'];
        $preApproved = (int) ($joinCode['pre_approved'] ?? 0) === 1;

        return TenantScope::asTenant($tenantId, static function () use (
            $input,
            $code,
            $publicKey,
            $tenantId,
            $networkId,
            $preApproved
        ): array {
            $network = Network::find($networkId);
            if ($network === null || $network['status'] !== 'active') {
                throw new ValidationException(['join_code' => 'That network is no longer accepting devices.']);
            }

            // Re-enrolment with the same key updates the existing row rather
            // than creating a duplicate — an agent reinstalled on the same
            // machine keeps its identity and its address.
            $existing = Device::findByPublicKey($publicKey);
            if ($existing !== null) {
                if ((int) $existing['tenant_id'] !== $tenantId) {
                    Logger::warning('security', 'Enrolment key reuse across tenants blocked', [
                        'public_key_prefix' => substr($publicKey, 0, 8),
                    ]);
                    throw new ValidationException(['public_key' => 'That device key is already registered elsewhere.']);
                }

                Device::update((int) $existing['id'], [
                    'hostname'      => $input['hostname'] ?? $existing['hostname'],
                    'os'            => $input['os'] ?? $existing['os'],
                    'os_version'    => $input['os_version'] ?? $existing['os_version'],
                    'arch'          => $input['arch'] ?? $existing['arch'],
                    'agent_version' => $input['agent_version'] ?? $existing['agent_version'],
                ]);

                // No use consumed: this machine is already on the network and
                // is re-presenting the code it joined with.
                return [
                    'device_uid'  => (string) $existing['device_uid'],
                    'status'      => (string) $existing['status'],
                    'network_uid' => (string) $network['network_uid'],
                    'poll_after'  => 10,
                ];
            }

            // A new device, so the use is claimed now — atomically, which is
            // what stops fifty machines enrolling together from all taking the
            // same last use.
            if (JoinCode::redeem($code) === null) {
                throw new ValidationException(['join_code' => 'That join code is not valid or has expired.']);
            }

            BillingService::assertCanAddDevice($tenantId);

            $hostname = substr((string) ($input['hostname'] ?? 'device'), 0, 190);
            $deviceId = Device::create([
                'tenant_id'      => $tenantId,
                'network_id'     => $networkId,
                'device_uid'     => Device::generateUid(),
                'name'           => $hostname,
                'hostname'       => $hostname,
                'os'             => substr((string) ($input['os'] ?? 'unknown'), 0, 32),
                'os_version'     => substr((string) ($input['os_version'] ?? ''), 0, 64),
                'arch'           => substr((string) ($input['arch'] ?? ''), 0, 16),
                'agent_version'  => substr((string) ($input['agent_version'] ?? ''), 0, 32),
                'public_key'     => $publicKey,
                'hw_fingerprint' => isset($input['hw_fingerprint']) ? substr((string) $input['hw_fingerprint'], 0, 64) : null,
                // R4: pending, with no address and no token.
                'status'         => 'pending',
            ]);

            AuditService::log('device.enroll', 'device', $deviceId, null, [
                'hostname' => $hostname,
                'os'       => $input['os'] ?? null,
            ]);

            // Two ways a device can be admitted without a click, and both are
            // an administrator's explicit decision recorded before the device
            // existed — which is what keeps R4 true. Neither is a default.
            //
            //   - a pre-approved join code: this code, single- or
            //     limited-use, short-lived and revocable, issued by a named
            //     administrator who chose the option;
            //   - the network's auto-approve setting, off by default.
            if ($preApproved || (int) $network['auto_approve_devices'] === 1) {
                AuditService::log('device.pre_approved', 'device', $deviceId, null, [
                    'reason' => $preApproved
                        ? 'the join code was issued pre-approved'
                        : 'the network approves devices automatically',
                ]);

                self::approve($deviceId, null);

                return [
                    'device_uid'  => (string) Device::findOrFail($deviceId)['device_uid'],
                    'status'      => 'authorized',
                    'network_uid' => (string) $network['network_uid'],
                    'poll_after'  => 2,
                ];
            }

            return [
                'device_uid'  => (string) Device::findOrFail($deviceId)['device_uid'],
                'status'      => 'pending',
                'network_uid' => (string) $network['network_uid'],
                'poll_after'  => 10,
            ];
        });
    }

    /**
     * Is this public key already a device of this customer?
     *
     * Asked across tenants because an enrolling agent has no identity yet, and
     * answered as a plain yes or no: nothing about the device is returned
     * here, and a key belonging to another customer answers no.
     */
    private static function keyBelongsToTenant(string $publicKey, int $tenantId): bool
    {
        $device = TenantScope::acrossAllTenants(
            're-enrolment identity check',
            static fn (): ?array => Device::findByPublicKey($publicKey)
        );

        return $device !== null && (int) $device['tenant_id'] === $tenantId;
    }

    /**
     * Approve a pending device: assign an address, issue a token, publish it
     * to its peers.
     *
     * @return array{device:array<string,mixed>,token:string,virtual_ip:string}
     */
    public static function approve(int $deviceId, ?string $preferredIp = null): array
    {
        $device = Device::findOrFail($deviceId);

        if ($device['status'] === 'authorized' && $device['virtual_ip'] !== null) {
            // Idempotent, but a fresh token is issued so a re-approval after a
            // suspected compromise actually rotates the credential.
            $token = Device::issueToken($deviceId);

            return ['device' => Device::findOrFail($deviceId), 'token' => $token, 'virtual_ip' => (string) $device['virtual_ip']];
        }

        if ($device['network_id'] === null) {
            throw new ValidationException(['network_id' => 'Assign this device to a network before approving it.']);
        }

        $tenantId = (int) $device['tenant_id'];
        BillingService::assertCanAddDevice($tenantId);

        $result = DB::transaction(static function () use ($device, $deviceId, $preferredIp): array {
            $ip = IpamService::assign((int) $device['network_id'], $deviceId, $preferredIp);

            Device::update($deviceId, [
                'status'      => 'authorized',
                'approved_by' => Auth::id(),
                'approved_at' => gmdate('Y-m-d H:i:s'),
                'revoked_at'  => null,
            ]);

            $token = Device::issueToken($deviceId);

            return ['token' => $token, 'ip' => $ip];
        });

        Network::bumpRevision((int) $device['network_id']);

        AuditService::log('device.approve', 'device', $deviceId, ['status' => $device['status']], [
            'status'     => 'authorized',
            'virtual_ip' => $result['ip'],
        ]);

        return [
            'device'     => Device::findOrFail($deviceId),
            'token'      => $result['token'],
            'virtual_ip' => $result['ip'],
        ];
    }

    /**
     * Revoke a device.
     *
     * The token is destroyed and the address released immediately; peers learn
     * within one poll interval because the network revision moves. The agent
     * itself is told "revoked" on its next request and tears down its tunnel.
     */
    public static function revoke(int $deviceId, string $reason = ''): void
    {
        $device = Device::findOrFail($deviceId);

        DB::transaction(static function () use ($deviceId): void {
            Device::clearToken($deviceId);
            IpamService::release($deviceId);
            Device::update($deviceId, [
                'status'          => 'revoked',
                'revoked_at'      => gmdate('Y-m-d H:i:s'),
                'connection_type' => 'offline',
            ]);
        });

        if ($device['network_id'] !== null) {
            Network::bumpRevision((int) $device['network_id']);
        }

        Logger::notice('security', 'Device revoked', ['device_id' => $deviceId, 'reason' => $reason]);
        AuditService::log('device.revoke', 'device', $deviceId, ['status' => $device['status']], [
            'status' => 'revoked',
            'reason' => $reason,
        ]);
    }

    /** Temporarily stop a device without giving up its address. */
    public static function disable(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);

        Device::clearToken($deviceId);
        Device::update($deviceId, ['status' => 'disabled', 'connection_type' => 'offline']);

        if ($device['network_id'] !== null) {
            Network::bumpRevision((int) $device['network_id']);
        }

        AuditService::log('device.disable', 'device', $deviceId, ['status' => $device['status']], ['status' => 'disabled']);
    }

    /** @param array<string,mixed> $input */
    public static function updateMetadata(int $deviceId, array $input): array
    {
        $before = Device::findOrFail($deviceId);

        $changes = [];
        if (isset($input['name'])) {
            $changes['name'] = substr(trim((string) $input['name']), 0, 120);
        }
        if (array_key_exists('tags', $input)) {
            $tags = is_array($input['tags']) ? $input['tags'] : array_filter(array_map('trim', explode(',', (string) $input['tags'])));
            // Tags feed ACL matching, so keep them to a predictable shape.
            $changes['tags_json'] = array_values(array_filter(
                array_map(static fn ($t): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $t)), $tags),
                static fn (string $t): bool => $t !== ''
            ));
        }
        if (array_key_exists('is_gateway', $input)) {
            $changes['is_gateway'] = (int) (bool) $input['is_gateway'];
        }

        if ($changes === []) {
            return $before;
        }

        Device::update($deviceId, $changes);

        if ($before['network_id'] !== null) {
            Network::bumpRevision((int) $before['network_id']);
        }

        $after = Device::findOrFail($deviceId);
        AuditService::logChange('device.update', 'device', $deviceId, $before, $after);

        return $after;
    }

    /**
     * The configuration an authorized agent pulls.
     *
     * Rule R1 is visible here and enforced by test: allowed_ips contains the
     * network CIDR and explicit routes only. 0.0.0.0/0 is never emitted.
     *
     * @param array<string,mixed> $device an authenticated device row
     * @return array<string,mixed>
     */
    public static function buildAgentConfig(array $device): array
    {
        $networkId = $device['network_id'] !== null ? (int) $device['network_id'] : null;
        if ($networkId === null) {
            throw new NotFoundException('Device is not attached to a network.');
        }

        $network = Network::find($networkId);
        if ($network === null) {
            throw new NotFoundException('Network not found.');
        }

        $deviceId = (int) $device['id'];
        $peers = AclService::buildPeerSet($networkId, $deviceId);

        $config = [
            'revision'    => (int) $network['config_revision'],
            'device'      => [
                'uid'        => $device['device_uid'],
                'name'       => $device['name'],
                'virtual_ip' => $device['virtual_ip'],
                'status'     => $device['status'],
                // Gateway mode. The agent configures forwarding only for the
                // prefixes named here — the routes list below also carries
                // prefixes *other* gateways advertise, which this device
                // installs as routes rather than forwards for.
                'is_gateway' => (int) $device['is_gateway'] === 1,
                // Both prefixes for each LAN this device routes for. The
                // gateway is the only place that needs each: the real range to
                // forward into and NAT for, and the mapped range to recognise
                // arriving on the tunnel and to rewrite to.
                'advertises' => array_map(
                    static fn (array $r): array => [
                        'destination'      => (string) ($r['mapped_cidr'] ?: $r['destination_cidr']),
                        'real_destination' => (string) $r['destination_cidr'],
                    ],
                    NetworkRoute::servedByDevice($deviceId)
                ),
            ],
            'network'     => [
                'uid'           => $network['network_uid'],
                'name'          => $network['name'],
                'cidr'          => $network['cidr'],
                'mtu'           => (int) $network['mtu'],
                'keepalive'     => (int) $network['keepalive_seconds'],
                'search_domain' => $network['search_domain'],
            ],
            // Split DNS: these servers are used only for the search domain, so
            // the customer's normal name resolution is untouched (R1).
            'dns'         => [
                'servers'       => $network['dns_json'] ?? [],
                'search_domain' => DnsZone::forNetwork($network),
                // The zone the agent is authoritative for, and nothing else.
                // Its resolver refuses every name outside this — it never
                // forwards — so it cannot become the customer's resolver even
                // if something points at it (R1 applied to names).
                'zone'          => DnsZone::forNetwork($network),
                'records'       => DnsZone::records($network),
                'split_only'    => true,
            ],
            'peers'       => $peers,
            'routes'      => self::routesFor($networkId, $device),
            'relays'      => array_map(
                static fn (array $r): array => [
                    'name'       => $r['name'],
                    'region'     => $r['region'],
                    'host'       => $r['host'],
                    'port'       => (int) $r['port'],
                    'tcp_port'   => (int) $r['tcp_port'],
                    'public_key' => $r['public_key'],
                ],
                // Region orders the list; it never filters it. A device with
                // none — the normal case — still measures the whole fleet.
                Relay::availableFor((string) ($device['region'] ?? ''))
            ),
            'coordinator' => [
                'host' => CoordinatorSettings::agentHost(),
                'port' => (int) CoordinatorSettings::current()['port'],
                // The agent seals its announcements to this key, so only the
                // coordinator can read the device token inside them. Its
                // absence is why an agent refuses to announce rather than
                // falling back to sending one in the clear.
                'public_key' => (string) CoordinatorSettings::current()['public_key'],
            ],
            // Where the agent goes when the network it is on passes nothing
            // but the port a browser uses.
            //
            // Published to every device rather than only to the ones that need
            // it, because a device cannot know in advance which network it
            // will be plugged into tomorrow — the laptop that worked on a
            // phone hotspot all morning is the same laptop that fails on the
            // office wifi after lunch.
            'fallback'    => [
                'url' => (string) CoordinatorSettings::current()['fallback_url'],
            ],
            // The controller's ed25519 public half.
            //
            // Published so an agent can verify what it is given: the signature
            // on this configuration, and — the reason it is here now — the
            // signature on an agent binary before it replaces its own. Pushing
            // code to every customer PC on the strength of a panel being
            // reachable is not something to do; a panel compromise must not
            // also be a code-signing key.
            'controller'  => [
                'public_key' => (string) Config::get('security.controller_public_key', ''),
            ],
            'policy'      => [
                // Stated explicitly in the config the agent consumes so the
                // rule is auditable on the wire, not just in agent source.
                'split_tunnel_only'   => true,
                'allow_default_route' => (bool) Config::get('network.allow_default_route', false),
                'acl_default_action'  => $network['acl_default_action'],
            ],
            // An administrator pressed "Update now" on this device's page. The
            // agent otherwise asks every six hours, which is right for a
            // rollout and useless for somebody standing in front of a machine
            // trying to fix it.
            //
            // It is a request, not an instruction: the agent still asks the
            // panel what it is offered and still refuses a binary whose
            // signature does not verify against the controller key above.
            'update_requested' => Device::updateRequested((int) $device['id']),
            // Reachability tests somebody asked this device to run. Answered
            // on the next heartbeat, which it was going to send anyway.
            //
            // Sent in the configuration rather than pushed, because that is
            // the only direction that works: the panel cannot open a
            // connection to a machine behind a customer's router, and the one
            // thing this product must never need is an inbound port on a
            // shop PC.
            'probes'      => \App\Models\DeviceProbe::pendingFor((int) $device['id']),
            'issued_at'   => gmdate('c'),
        ];

        return self::assertSplitTunnel($config);
    }

    /**
     * The advertised LANs, as the agent needs to see them.
     *
     * Every destination here is a **mapped** prefix. The customer's real range
     * is carried alongside it for the agent that has to NAT between the two —
     * the gateway — and for anything that has to show a human which machine a
     * rule is about. It is never what a client routes on, because two
     * customers on 192.168.1.0/24 is the normal case and one routing table
     * cannot hold both.
     *
     * @param array<string,mixed> $device
     * @return list<array<string,mixed>>
     */
    private static function routesFor(int $networkId, array $device): array
    {
        $out = [];

        foreach (NetworkRoute::forNetwork($networkId) as $route) {
            $compiled = AclRouteFilters::compile(
                $networkId,
                $device,
                (string) $route['destination_cidr'],
                isset($route['mapped_cidr']) ? (string) $route['mapped_cidr'] : null
            );

            foreach ($compiled as $entry) {
                $out[] = [
                    'destination'      => $entry['destination'],
                    'real_destination' => $entry['real_destination'],
                    'via'              => $route['via_device_ip'] ?? null,
                    'metric'           => (int) $route['metric'],
                    // Rules about machines inside this prefix. They name the
                    // destination by LAN address, because that is how an
                    // operator thinks about an NVR: the rule is about the
                    // recorder, not about the PC that routes for it.
                    'filters'          => $entry['filters'],
                ];
            }
        }

        return $out;
    }

    /**
     * Final guard before config leaves the server.
     *
     * Cheap, and it converts R1 from "the code should not do that" into "the
     * server refuses to emit it". Anything that would capture the default
     * route is stripped and logged loudly.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function assertSplitTunnel(array $config): array
    {
        $forbidden = ['0.0.0.0/0', '::/0', '0.0.0.0/1', '128.0.0.0/1'];

        foreach ($config['peers'] as $i => $peer) {
            $allowed = $peer['allowed_ips'] ?? [];
            $clean = array_values(array_filter(
                $allowed,
                static fn (string $cidr): bool => !in_array(trim($cidr), $forbidden, true)
            ));
            if (count($clean) !== count($allowed)) {
                Logger::critical('security', 'Default route stripped from agent config', [
                    'peer' => $peer['uid'] ?? null,
                ]);
            }
            $config['peers'][$i]['allowed_ips'] = $clean;
        }

        $config['routes'] = array_values(array_filter(
            $config['routes'],
            static function (array $route) use ($forbidden): bool {
                if (in_array(trim((string) $route['destination']), $forbidden, true)) {
                    Logger::critical('security', 'Default route stripped from advertised routes', $route);

                    return false;
                }

                return true;
            }
        ));

        return $config;
    }
}
