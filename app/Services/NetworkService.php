<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;
use App\Models\AclRule;
use App\Models\Device;
use App\Models\IpAllocation;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\NetworkRoute;

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
        $tenantId = Auth::tenantId();
        if ($tenantId === null) {
            throw new ValidationException(['tenant_id' => 'A network must belong to a customer.']);
        }

        BillingService::assertCanAddNetwork($tenantId);

        $cidr = (string) ($input['cidr'] ?? Config::get('network.default_cidr', '10.50.0.0/16'));
        $range = IpamService::parseCidr($cidr);

        self::assertCidrNotOverlapping($tenantId, $range, null);

        $networkId = DB::transaction(static function () use ($tenantId, $input, $range): int {
            $id = Network::create([
                'tenant_id'            => $tenantId,
                'name'                 => (string) $input['name'],
                'network_uid'          => Network::generateUid(),
                'description'          => $input['description'] ?? null,
                'cidr'                 => $range['cidr'],
                'dns_json'             => $input['dns'] ?? Config::get('network.default_dns', []),
                'search_domain'        => $input['search_domain'] ?? null,
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
        });

        $network = Network::findOrFail($networkId);
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
            'acl'       => AclRule::forNetwork($networkId, false),
            'join_code' => JoinCode::activeForNetwork($networkId),
            'capacity'  => IpamService::describeCapacity((string) $network['cidr']),
        ];
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

    /** The one-line install command shown on the network page. */
    public static function installCommand(string $joinCode, string $os = 'windows'): string
    {
        $url = rtrim((string) Config::get('app.url', ''), '/');
        $brandSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) Config::get('brand.name', 'agent')));

        return match ($os) {
            'linux', 'darwin' => sprintf('curl -fsSL %s/install.sh | sudo sh -s -- --join %s', $url, $joinCode),
            default => sprintf(
                'powershell -c "irm %s/install.ps1 | iex; %s-agent join %s"',
                $url,
                $brandSlug,
                $joinCode
            ),
        };
    }
}
