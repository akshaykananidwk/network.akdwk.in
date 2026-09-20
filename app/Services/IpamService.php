<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\DB;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Models\Device;
use App\Models\IpAllocation;
use App\Models\Network;

/**
 * IPv4 address management for overlay networks.
 *
 * Addresses are materialised into ip_allocations when a network is created, so
 * assignment is a single row-locked UPDATE rather than a scan-and-guess. A /16
 * is 65k rows — acceptable — but anything larger is refused because the pool
 * table would stop being the right tool.
 */
final class IpamService
{
    /** Largest pool we will materialise: /16 = 65,534 usable addresses. */
    private const MIN_PREFIX = 16;
    private const MAX_PREFIX = 30;

    /** Addresses reserved at the bottom of every pool for infrastructure. */
    private const RESERVED_LOW = 1;

    /**
     * Parse and validate a CIDR.
     *
     * @return array{network:int,broadcast:int,prefix:int,first:int,last:int,total:int,cidr:string}
     * @throws ValidationException
     */
    public static function parseCidr(string $cidr): array
    {
        $cidr = trim($cidr);
        if (!str_contains($cidr, '/')) {
            throw new ValidationException(['cidr' => 'CIDR must include a prefix length, e.g. 10.50.0.0/16.']);
        }

        [$ipPart, $prefixPart] = explode('/', $cidr, 2);
        if (filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidationException(['cidr' => 'CIDR must start with a valid IPv4 address.']);
        }
        if (!ctype_digit($prefixPart)) {
            throw new ValidationException(['cidr' => 'CIDR prefix must be a number.']);
        }

        $prefix = (int) $prefixPart;
        if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX) {
            throw new ValidationException([
                'cidr' => sprintf('CIDR prefix must be between /%d and /%d.', self::MIN_PREFIX, self::MAX_PREFIX),
            ]);
        }

        if (!self::isPrivateRange($ipPart)) {
            throw new ValidationException([
                'cidr' => 'Overlay networks must use RFC 1918 private space (10/8, 172.16/12 or 192.168/16).',
            ]);
        }

        $ipLong = (int) ip2long($ipPart);
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $networkLong = $ipLong & $mask;
        $broadcastLong = $networkLong | (~$mask & 0xFFFFFFFF);

        if ($networkLong !== $ipLong) {
            throw new ValidationException([
                'cidr' => 'Host bits must be zero. Did you mean ' . long2ip($networkLong) . '/' . $prefix . '?',
            ]);
        }

        // .0 is the network address and .255 the broadcast; neither is assignable.
        $first = $networkLong + 1 + self::RESERVED_LOW;
        $last = $broadcastLong - 1;

        return [
            'network'   => $networkLong,
            'broadcast' => $broadcastLong,
            'prefix'    => $prefix,
            'first'     => $first,
            'last'      => $last,
            'total'     => max(0, $last - $first + 1),
            'cidr'      => long2ip($networkLong) . '/' . $prefix,
        ];
    }

    public static function isPrivateRange(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $long = (int) $long;

        return ($long >= (int) ip2long('10.0.0.0')     && $long <= (int) ip2long('10.255.255.255'))
            || ($long >= (int) ip2long('172.16.0.0')   && $long <= (int) ip2long('172.31.255.255'))
            || ($long >= (int) ip2long('192.168.0.0')  && $long <= (int) ip2long('192.168.255.255'));
    }

    /**
     * Materialise the address pool for a new network.
     *
     * @return int number of assignable addresses created
     */
    public static function createPool(int $tenantId, int $networkId, string $cidr): int
    {
        $range = self::parseCidr($cidr);

        $addresses = [];
        for ($num = $range['first']; $num <= $range['last']; $num++) {
            $addresses[] = ['ip' => long2ip($num), 'num' => $num];
        }

        $created = IpAllocation::seedPool($tenantId, $networkId, $addresses);

        Logger::info('app', 'IP pool created', [
            'network_id' => $networkId,
            'cidr'       => $range['cidr'],
            'addresses'  => $created,
        ]);

        return $created;
    }

    /**
     * Assign an address to an approved device.
     *
     * @param string|null $preferredIp request a specific address, e.g. for a
     *                                 server that must keep a fixed IP
     * @throws AppException when the pool is exhausted
     */
    public static function assign(int $networkId, int $deviceId, ?string $preferredIp = null): string
    {
        return DB::transaction(static function () use ($networkId, $deviceId, $preferredIp): string {
            // Re-assignment is idempotent: a device that already holds an
            // address keeps it rather than consuming a second one.
            $existing = IpAllocation::findForDevice($deviceId);
            if ($existing !== null && $preferredIp === null) {
                return (string) $existing['ip'];
            }

            if ($preferredIp !== null) {
                if (filter_var($preferredIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new ValidationException(['virtual_ip' => 'Not a valid IPv4 address.']);
                }
                if ($existing !== null) {
                    IpAllocation::release($deviceId);
                }
                if (!IpAllocation::claimSpecific($networkId, $deviceId, $preferredIp)) {
                    throw new ValidationException(['virtual_ip' => $preferredIp . ' is already in use or reserved.']);
                }
                Device::update($deviceId, ['virtual_ip' => $preferredIp]);

                return $preferredIp;
            }

            $ip = IpAllocation::claimNext($networkId, $deviceId);
            if ($ip === null) {
                $stats = IpAllocation::poolStats($networkId);
                throw new AppException(sprintf(
                    'The address pool for this network is exhausted (%d of %d in use). Widen the CIDR or release addresses.',
                    $stats['used'],
                    $stats['total']
                ));
            }

            Device::update($deviceId, ['virtual_ip' => $ip]);

            return $ip;
        });
    }

    /** Return a device's address to the pool. */
    public static function release(int $deviceId): void
    {
        DB::transaction(static function () use ($deviceId): void {
            IpAllocation::release($deviceId);
            Device::update($deviceId, ['virtual_ip' => null]);
        });
    }

    /** Hold an address back from automatic assignment. */
    public static function reserve(int $networkId, string $ip, string $label): void
    {
        $existing = IpAllocation::findByIp($networkId, $ip);
        if ($existing === null) {
            throw new ValidationException(['ip' => $ip . ' is not part of this network\'s range.']);
        }
        if ($existing['device_id'] !== null) {
            throw new ValidationException(['ip' => $ip . ' is assigned to a device; release it first.']);
        }

        IpAllocation::update((int) $existing['id'], ['reserved' => 1, 'label' => $label]);
    }

    public static function unreserve(int $networkId, string $ip): void
    {
        $existing = IpAllocation::findByIp($networkId, $ip);
        if ($existing !== null) {
            IpAllocation::update((int) $existing['id'], ['reserved' => 0, 'label' => null]);
        }
    }

    /**
     * Address map for the network's IP Management tab.
     *
     * @return array{stats:array{total:int,used:int,reserved:int,free:int},rows:list<array<string,mixed>>}
     */
    public static function map(int $networkId, int $limit = 512): array
    {
        $rows = DB::select(
            'SELECT a.ip, a.ip_numeric, a.reserved, a.label, a.device_id,
                    d.name AS device_name, d.status AS device_status, d.connection_type
             FROM ' . DB::table('ip_allocations') . ' a
             LEFT JOIN ' . DB::table('devices') . ' d ON d.id = a.device_id AND d.deleted_at IS NULL
             WHERE a.network_id = :n AND (a.device_id IS NOT NULL OR a.reserved = 1)
             ORDER BY a.ip_numeric
             LIMIT ' . max(1, min($limit, 2000)),
            ['n' => $networkId]
        );

        return ['stats' => IpAllocation::poolStats($networkId), 'rows' => $rows];
    }

    /**
     * Grow a network's pool after its CIDR is widened.
     *
     * Narrowing is refused: it would strand devices that hold an address
     * outside the new range.
     *
     * @throws ValidationException
     */
    public static function resizePool(int $tenantId, int $networkId, string $oldCidr, string $newCidr): int
    {
        $old = self::parseCidr($oldCidr);
        $new = self::parseCidr($newCidr);

        if ($new['network'] > $old['network'] || $new['broadcast'] < $old['broadcast']) {
            throw new ValidationException([
                'cidr' => 'A network\'s range can only be widened, and must still contain the existing range.',
            ]);
        }

        $addresses = [];
        for ($num = $new['first']; $num <= $new['last']; $num++) {
            // INSERT IGNORE skips addresses that already exist, so only the
            // newly available ones are added.
            $addresses[] = ['ip' => long2ip($num), 'num' => $num];
        }

        $added = IpAllocation::seedPool($tenantId, $networkId, $addresses);
        Network::bumpRevision($networkId);

        return $added;
    }

    /** Human-readable capacity summary for the UI. */
    public static function describeCapacity(string $cidr): string
    {
        try {
            $range = self::parseCidr($cidr);
        } catch (ValidationException) {
            return 'invalid range';
        }

        return sprintf('%s — %s usable addresses (%s to %s)',
            $range['cidr'],
            number_format($range['total']),
            long2ip($range['first']),
            long2ip($range['last'])
        );
    }
}
