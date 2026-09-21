<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\ValidationException;
use App\Models\Network;
use App\Models\NetworkRoute;

/**
 * Virtual prefixes for advertised LANs (§16–17).
 *
 * Nearly every router sold in India hands out 192.168.1.0/24 or
 * 192.168.0.0/24. A technician's laptop on one of those cannot reach a
 * customer whose LAN is the same range — a routing table holds one route per
 * destination, and taking the customer's would cut the laptop off from the
 * printer beside it. Refusing the route, which is what the agent did before
 * this existed, is honest and useless.
 *
 * So the overlay never sees the customer's real range. Each advertised LAN is
 * given a prefix of the same size out of a pool, unique within the network, and
 * the gateway rewrites one to the other with the host part preserved:
 * 192.168.1.50 at the hotel is reached at 10.201.5.50. Rules are still written
 * about 192.168.1.50, because that is the address on the label on the
 * recorder; this class is what translates between the two.
 *
 * One-to-one and arithmetic, not a table: the mapped address is the host part
 * of the real one grafted onto the mapped prefix. Nothing has to be looked up
 * per host, and a LAN with two hundred cameras costs exactly what a LAN with
 * one costs.
 */
final class SubnetMapper
{
    /**
     * The pool virtual prefixes come from.
     *
     * 10.128.0.0/10 by default: inside RFC 1918, and the half of 10/8 that
     * almost nobody numbers a LAN or an overlay out of. Configurable because
     * "almost nobody" is not "nobody", and a customer who does use it needs a
     * way out that is not a code change.
     */
    public const DEFAULT_POOL = '10.128.0.0/10';

    /** Nothing smaller than this is mapped, or the pool would fragment badly. */
    private const MIN_PREFIX_BITS = 8;

    /**
     * Allocate a virtual prefix for one real LAN.
     *
     * The mapped prefix is the same size as the real one, so the host part is
     * carried across unchanged and the arithmetic stays reversible.
     *
     * @throws ValidationException when the pool has no room, or is unusable
     */
    public static function allocate(int $networkId, string $realCidr): string
    {
        $network = Network::find($networkId);
        [$realAddr, $bits] = self::split($realCidr);
        if ($realAddr === null) {
            throw new ValidationException([
                'destination_cidr' => sprintf('%s is not a network I can map.', $realCidr),
            ]);
        }

        $pool = self::pool($network);
        [$poolAddr, $poolBits] = self::split($pool);

        if ($bits < $poolBits) {
            throw new ValidationException([
                'destination_cidr' => sprintf(
                    'A /%d is larger than the whole mapping pool (%s). Advertise a smaller range.',
                    $bits, $pool
                ),
            ]);
        }

        $taken = self::takenIn($networkId);
        $step = self::blockSize($bits);
        $end = $poolAddr + self::blockSize($poolBits);

        for ($candidate = $poolAddr; $candidate < $end; $candidate += $step) {
            $cidr = long2ip($candidate) . '/' . $bits;
            if (self::collides($cidr, $taken)) {
                continue;
            }

            return $cidr;
        }

        throw new ValidationException([
            'destination_cidr' => sprintf(
                'No free /%d left in the mapping pool %s for this network.', $bits, $pool
            ),
        ]);
    }

    /**
     * Translate one address from the real LAN into the mapped prefix.
     *
     * Used for compiling rules: an operator writes 192.168.1.50 and the agent
     * has to be told 10.201.5.50, because the real address never reaches it.
     */
    public static function mapAddress(string $address, string $realCidr, string $mappedCidr): ?string
    {
        [$addr, $addrBits] = self::split($address);
        [$real, $realBits] = self::split($realCidr);
        [$mapped, $mappedBits] = self::split($mappedCidr);

        if ($addr === null || $real === null || $mapped === null) {
            return null;
        }
        if ($realBits !== $mappedBits) {
            return null;
        }
        if ($addrBits < $realBits) {
            // Wider than the LAN it is supposed to sit inside, so it is not an
            // address in that LAN and mapping it would invent one.
            return null;
        }

        $mask = self::mask($realBits);
        if (($addr & $mask) !== ($real & $mask)) {
            return null;
        }

        $host = $addr & ~$mask & 0xFFFFFFFF;

        return long2ip(($mapped & $mask) | $host) . '/' . $addrBits;
    }

    /**
     * The pool a network draws from: its own if it has one, else the
     * configured default.
     *
     * Per network rather than global because plenty of offices — and most
     * ISP-managed connections in this market — number out of 10.x. A customer
     * who collides with the default needs a way out that does not move every
     * other customer with them.
     *
     * @param array<string,mixed>|null $network
     */
    public static function pool(?array $network = null): string
    {
        $candidates = [];
        if ($network !== null && !empty($network['mapped_pool'])) {
            $candidates[] = (string) $network['mapped_pool'];
        }
        $candidates[] = (string) Config::get('network.mapped_pool', self::DEFAULT_POOL);
        $candidates[] = self::DEFAULT_POOL;

        foreach ($candidates as $candidate) {
            if (self::usablePool(trim($candidate))) {
                return trim($candidate);
            }
        }

        return self::DEFAULT_POOL;
    }

    /**
     * Validate a pool an operator typed.
     *
     * @throws ValidationException
     */
    public static function validatePool(string $pool, string $networkCidr): string
    {
        $pool = trim($pool);

        if (!self::usablePool($pool)) {
            throw new ValidationException([
                'mapped_pool' => 'A mapping pool must be a private IPv4 range no wider than a /8 '
                    . 'and no narrower than a /24, e.g. 10.128.0.0/10 or 172.20.0.0/14.',
            ]);
        }

        // A pool overlapping the network's own range would hand out virtual
        // prefixes that collide with the overlay, which every agent would then
        // refuse — a setting that breaks the network it is set on.
        if ($networkCidr !== '' && AclRouteFilters::overlap($pool, $networkCidr)) {
            throw new ValidationException([
                'mapped_pool' => sprintf(
                    '%s overlaps this network\'s own range %s. Virtual prefixes drawn from it would '
                        . 'collide with the overlay itself.',
                    $pool,
                    $networkCidr
                ),
            ]);
        }

        return $pool;
    }

    private static function usablePool(string $pool): bool
    {
        [$addr, $bits] = self::split($pool);

        if ($addr === null || $bits < self::MIN_PREFIX_BITS || $bits > 24) {
            return false;
        }

        // Private space only. A pool out of public address space would have
        // agents routing somebody else's internet into the tunnel.
        return self::overlapsAny($pool, ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
    }

    /** @param list<string> $ranges */
    private static function overlapsAny(string $cidr, array $ranges): bool
    {
        [$addr, $bits] = self::split($cidr);
        if ($addr === null) {
            return false;
        }

        foreach ($ranges as $range) {
            [$rangeAddr, $rangeBits] = self::split($range);
            if ($rangeAddr === null || $bits < $rangeBits) {
                // Wider than the private range, so it reaches outside it.
                continue;
            }
            if (AclRouteFilters::overlap($cidr, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefixes this network may not hand out: the ones it already has, and its
     * own overlay range.
     *
     * The overlay matters because a mapped prefix that overlapped it would have
     * every agent routing its own peers into somebody's LAN.
     *
     * @return list<string>
     */
    private static function takenIn(int $networkId): array
    {
        $taken = NetworkRoute::mappedInNetwork($networkId);

        $network = Network::find($networkId);
        if ($network !== null && !empty($network['cidr'])) {
            $taken[] = (string) $network['cidr'];
        }

        return $taken;
    }

    /** @param list<string> $taken */
    private static function collides(string $cidr, array $taken): bool
    {
        foreach ($taken as $other) {
            if ($other !== '' && AclRouteFilters::overlap($cidr, $other)) {
                return true;
            }
        }

        return false;
    }

    private static function blockSize(int $bits): int
    {
        return 1 << (32 - $bits);
    }

    private static function mask(int $bits): int
    {
        return $bits === 0 ? 0 : ((~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF);
    }

    /** @return array{0:int|null,1:int} */
    private static function split(string $cidr): array
    {
        $cidr = trim($cidr);
        $bits = 32;

        if (str_contains($cidr, '/')) {
            [$cidr, $suffix] = explode('/', $cidr, 2);
            $bits = (int) $suffix;
        }

        $packed = ip2long($cidr);
        if ($packed === false || $bits < 0 || $bits > 32) {
            return [null, 0];
        }

        return [$packed, $bits];
    }
}
