<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Middleware\TenantScope;
use App\Models\AclRule;
use App\Models\Device;
use App\Models\IpAllocation;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Tenant;
use App\Models\NetworkRoute;
use App\Models\RouteHost;

/**
 * Network lifecycle. Everything that changes what an agent should do ends with
 * a revision bump, which is how peers learn to re-read their config.
 */
final class NetworkService
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> the created network row
     */
    public static function create(array $input): array
    {
        // A super administrator has no tenant of their own — that is what makes
        // them one — so they have to say which customer a network belongs to.
        // Until 1.9.1 they could not: the form had no such field, and the
        // failure arrived as "please correct the highlighted fields" with
        // nothing highlighted.
        $tenantId = Auth::tenantId();
        if ($tenantId === null) {
            $chosen = (int) ($input['tenant_id'] ?? 0);
            if ($chosen <= 0) {
                throw new ValidationException([
                    'tenant_id' => 'Choose which customer this network belongs to.',
                ]);
            }

            // The tenants table is platform-level and not tenant-scoped, so
            // this needs no cross-tenant block; reaching this code at all
            // already required a platform super admin.
            $tenant = Tenant::find($chosen);
            if ($tenant === null) {
                throw new ValidationException(['tenant_id' => 'That customer no longer exists.']);
            }

            $tenantId = (int) $tenant['id'];
        }

        BillingService::assertCanAddNetwork($tenantId);

        $cidr = (string) ($input['cidr'] ?? Config::get('network.default_cidr', '10.50.0.0/16'));
        $range = IpamService::parseCidr($cidr);

        self::assertCidrNotOverlapping($tenantId, $range, null);

        // The write happens in the chosen customer's scope. A super admin has
        // no scope of their own, so without this the inserts below would run
        // unfiltered.
        $networkId = TenantScope::asTenant($tenantId, static fn (): int => DB::transaction(
            static function () use ($tenantId, $input, $range): int {
                $id = Network::create([
                    'tenant_id'            => $tenantId,
                    'name'                 => (string) $input['name'],
                    'network_uid'          => Network::generateUid(),
                    'description'          => $input['description'] ?? null,
                    'cidr'                 => $range['cidr'],
                    'dns_json'             => $input['dns'] ?? Config::get('network.default_dns', []),
                    // Always a zone, because §18's names need one and a network
                    // created without a search domain would silently have no
                    // way to be addressed by name.
                    'search_domain'        => self::zoneFor($input),
                    'mapped_pool'          => isset($input['mapped_pool']) && trim((string) $input['mapped_pool']) !== ''
                        ? SubnetMapper::validatePool((string) $input['mapped_pool'], $range['cidr'])
                        : null,
                    'mtu'                  => (int) ($input['mtu'] ?? Config::get('network.default_mtu', 1280)),
                    'keepalive_seconds'    => (int) ($input['keepalive_seconds'] ?? Config::get('network.default_keepalive', 25)),
                    'auto_assign_ip'       => (int) (bool) ($input['auto_assign_ip'] ?? true),
                    'auto_approve_devices' => (int) (bool) ($input['auto_approve_devices'] ?? false),
                    'private'              => (int) (bool) ($input['private'] ?? true),
                    'acl_default_action'   => ($input['acl_default_action'] ?? 'allow') === 'deny' ? 'deny' : 'allow',
                    'status'               => 'active',
                    'created_by'           => Auth::id(),
                ]);

                IpamService::createPool($tenantId, $id, $range['cidr']);

                return $id;
            }
        ));

        $network = TenantScope::asTenant(
            $tenantId,
            static fn (): array => Network::findOrFail($networkId)
        );
        AuditService::log('network.create', 'network', $networkId, null, [
            'name' => $network['name'],
            'cidr' => $network['cidr'],
        ]);

        return $network;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> the updated row
     */
    public static function update(int $networkId, array $input): array
    {
        $before = Network::findOrFail($networkId);
        $tenantId = (int) $before['tenant_id'];

        $changes = [];
        foreach (['name', 'description', 'search_domain', 'mtu', 'keepalive_seconds'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $input[$field];
            }
        }

        if (array_key_exists('mapped_pool', $input)) {
            $pool = trim((string) $input['mapped_pool']);
            // Empty clears it back to the configured default rather than
            // storing an empty string, so "leave the box blank" means what an
            // operator expects.
            $changes['mapped_pool'] = $pool === ''
                ? null
                : SubnetMapper::validatePool($pool, (string) ($input['cidr'] ?? $before['cidr']));

            // Routes already allocated keep the prefixes they have. Changing
            // the pool decides where the *next* one comes from; re-allocating
            // the existing ones would move addresses out from under every
            // agent that has them, which is a bigger surprise than the problem
            // being fixed.
            if ($changes['mapped_pool'] !== $before['mapped_pool']) {
                Logger::notice('network', 'Mapping pool changed; existing routes keep their prefixes', [
                    'network_id' => $networkId,
                    'from'       => $before['mapped_pool'],
                    'to'         => $changes['mapped_pool'],
                ]);
            }
        }

        // Validated on the way in, not only on create. A network edited to a
        // zone outside .internal would make every one of its agents
        // authoritative for a domain somebody else owns.
        if (array_key_exists('search_domain', $changes)) {
            $changes['search_domain'] = DnsZone::validate((string) $changes['search_domain']);
        }
        foreach (['auto_assign_ip', 'auto_approve_devices', 'private'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = (int) (bool) $input[$field];
            }
        }
        if (array_key_exists('dns', $input)) {
            $changes['dns_json'] = $input['dns'];
        }
        if (array_key_exists('acl_default_action', $input)) {
            $changes['acl_default_action'] = $input['acl_default_action'] === 'deny' ? 'deny' : 'allow';
        }
        if (array_key_exists('status', $input) && in_array($input['status'], ['active', 'paused', 'archived'], true)) {
            $changes['status'] = $input['status'];
        }

        // Widening the range grows the pool; narrowing is refused by resizePool.
        if (!empty($input['cidr']) && $input['cidr'] !== $before['cidr']) {
            $range = IpamService::parseCidr((string) $input['cidr']);
            self::assertCidrNotOverlapping($tenantId, $range, $networkId);
            IpamService::resizePool($tenantId, $networkId, (string) $before['cidr'], $range['cidr']);
            $changes['cidr'] = $range['cidr'];
        }

        if ($changes !== []) {
            Network::update($networkId, $changes);
            Network::bumpRevision($networkId);
        }

        $after = Network::findOrFail($networkId);
        AuditService::logChange('network.update', 'network', $networkId, $before, $after);

        return $after;
    }

    /**
     * Archive a network.
     *
     * Devices are detached and their addresses released rather than being left
     * pointing at a network that no longer exists.
     */
    public static function delete(int $networkId): void
    {
        $network = Network::findOrFail($networkId);

        DB::transaction(static function () use ($networkId): void {
            foreach (Device::where(['network_id' => $networkId]) as $device) {
                IpamService::release((int) $device['id']);
                Device::update((int) $device['id'], ['status' => 'revoked', 'network_id' => null]);
                Device::clearToken((int) $device['id']);
            }
            JoinCode::revokeAllForNetwork($networkId);
            Network::delete($networkId);
        });

        AuditService::log('network.delete', 'network', $networkId, ['name' => $network['name'], 'cidr' => $network['cidr']], null);
    }

    /**
     * Everything the network detail page needs, in one call.
     *
     * @return array<string,mixed>
     */
    public static function detail(int $networkId): array
    {
        $network = Network::findOrFail($networkId);

        return [
            'network'   => $network,
            'devices'   => Device::where(['network_id' => $networkId], 'name', 'ASC'),
            'pool'      => IpAllocation::poolStats($networkId),
            'routes'    => NetworkRoute::forNetwork($networkId, false),
            // Keyed by route, so the view can list a route's machines under it
            // without a query per row.
            'hosts'     => self::hostsByRoute($networkId),
            'zone'      => DnsZone::forNetwork($network),
            'gateways'  => array_values(array_filter(
                Device::where(['network_id' => $networkId], 'name', 'ASC'),
                static fn (array $d): bool => $d['status'] === 'authorized'
            )),
            'acl'       => AclRule::forNetwork($networkId, false),
            'join_code' => JoinCode::activeForNetwork($networkId),
            'capacity'  => IpamService::describeCapacity((string) $network['cidr']),
        ];
    }

    /**
     * Named machines, grouped by the route they sit behind.
     *
     * @return array<int, list<array<string,mixed>>>
     */
    private static function hostsByRoute(int $networkId): array
    {
        $out = [];
        foreach (RouteHost::forNetwork($networkId) as $host) {
            $out[(int) $host['route_id']][] = $host;
        }

        return $out;
    }

    /**
     * Two networks in the same tenant must not overlap, or a device on one
     * could be handed an address that routes into the other.
     *
     * @param array{network:int,broadcast:int} $range
     */
    private static function assertCidrNotOverlapping(int $tenantId, array $range, ?int $exceptNetworkId): void
    {
        $existing = Network::where(['tenant_id' => $tenantId]);

        foreach ($existing as $network) {
            if ($exceptNetworkId !== null && (int) $network['id'] === $exceptNetworkId) {
                continue;
            }
            if ($network['status'] === 'archived') {
                continue;
            }

            $other = IpamService::parseCidr((string) $network['cidr']);
            $overlaps = $range['network'] <= $other['broadcast'] && $other['network'] <= $range['broadcast'];
            if ($overlaps) {
                throw new ValidationException([
                    'cidr' => sprintf(
                        'This range overlaps "%s" (%s). Ranges within one customer must not overlap.',
                        $network['name'],
                        $network['cidr']
                    ),
                ]);
            }
        }
    }

    /**
     * What to actually type, per platform.
     *
     * This used to print a PowerShell one-liner that fetched `install.ps1` and
     * ran a verb called `join`. There is no install.ps1 — the URL was a 404 —
     * the binary is `akconnect-agent`, not the brand slug, and the verb is
     * `enroll`. Three separate inventions in one line, shown to every
     * administrator on the network page, and the first person to try it in the
     * field got a 404.
     *
     * Windows is not given a command at all, because on Windows the answer is
     * not a command: it is akconnect-setup.exe, double-clicked, asking for the
     * join code and nothing else (§33). Printing a command there would be
     * offering the hard way as if it were the way.
     */
    public static function installCommand(string $joinCode, string $os = 'windows'): string
    {
        $url = rtrim((string) Config::get('app.url', ''), '/');

        return match ($os) {
            'linux', 'darwin' => sprintf(
                'sudo akconnect-agent enroll -panel %s -join-code %s',
                $url,
                $joinCode
            ),
            default => sprintf(
                'akconnect-setup.exe          (double-click; it asks for the code)   %s',
                $joinCode
            ),
        };
    }

    /**
     * The DNS zone for a new network.
     *
     * Validated when an operator typed one, derived from the name when they
     * did not. Always under .internal — see DnsZone for why that is not
     * negotiable.
     *
     * @param array<string,mixed> $input
     */
    private static function zoneFor(array $input): string
    {
        $typed = trim((string) ($input['search_domain'] ?? ''));
        if ($typed !== '') {
            return DnsZone::validate($typed);
        }

        $slug = DnsZone::slug((string) ($input['name'] ?? ''));
        if ($slug === '') {
            $slug = 'net-' . bin2hex(random_bytes(3));
        }

        return $slug . DnsZone::SUFFIX;
    }
}
