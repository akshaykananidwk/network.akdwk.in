<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AclRule;

/**
 * Rules about machines behind a gateway.
 *
 * A peer rule names a device. A route rule names an address on somebody's LAN,
 * because that is how an operator thinks about an NVR — the rule is about the
 * recorder, and the PC routing for it is incidental. The two are compiled
 * differently and that is why this is a separate class rather than three more
 * methods on AclService.
 */
final class AclRouteFilters
{
    /**
     * Rules governing one device's traffic into a routed subnet.
     *
     * These are different from a peer's filters in what they are *about*. A
     * peer rule names a device; a route rule names an address on somebody's
     * LAN — "AK Support may reach 192.168.1.50 on tcp/554" is about the NVR,
     * and the PC routing for it is incidental.
     *
     * Only CIDR-destination rules are considered, and only where the rule's
     * range overlaps the advertised prefix. A rule about 10.0.0.0/8 has
     * nothing to say about a hotel's 192.168.77.0/24 and must not be compiled
     * into it, or an operator's rule for one customer would silently apply to
     * another.
     *
     * @param array<string,mixed> $self
     * @return list<array<string,mixed>>
     */
    public static function forRoute(int $networkId, array $self, string $routeCidr): array
    {
        $selfTags = AclService::tagsOf($self);
        $filters = [];

        foreach (AclRule::forNetwork($networkId) as $rule) {
            if (!AclService::matches($rule['src_type'], $rule['src_value'], $self, $selfTags)) {
                continue;
            }
            if ($rule['dst_type'] !== 'cidr' || $rule['dst_value'] === null) {
                continue;
            }
            if (!self::governs((string) $rule['dst_value'], $routeCidr)) {
                continue;
            }

            $filters[] = AclService::toFilter($rule);
        }

        return $filters;
    }

    /**
     * Compile one advertised LAN into the route entries an agent enforces.
     *
     * Two things happen here that do not happen anywhere else.
     *
     * **Addresses are translated.** An operator writes rules about
     * 192.168.1.50 because that is the address on the label on the recorder.
     * The agent never sees that address — the overlay reaches the recorder at
     * its mapped address, say 10.201.5.50 — so every rule destination inside
     * the advertised range is carried across into the mapped range before it
     * is sent.
     *
     * **Rules keep their precision.** An earlier version compiled every rule
     * touching the LAN into one entry for the whole prefix, so a rule naming
     * 192.168.1.50 on tcp/554 also opened tcp/554 on the till at
     * 192.168.1.60. The agent matches the longest prefix, so a rule about one
     * host gets an entry of its own, carrying the rules for that host *and*
     * the ones about the subnet it sits in — otherwise the more specific entry
     * would silently discard the wider rules rather than refine them.
     *
     * @param array<string,mixed> $self
     * @return list<array<string,mixed>> each with destination, real_destination, filters
     */
    public static function compile(int $networkId, array $self, string $realCidr, ?string $mappedCidr): array
    {
        $mapped = ($mappedCidr !== null && $mappedCidr !== '') ? $mappedCidr : $realCidr;

        $out = [[
            'destination'      => $mapped,
            'real_destination' => $realCidr,
            'filters'          => self::forRoute($networkId, $self, $realCidr),
        ]];

        foreach (self::ruleDestinationsInside($networkId, $realCidr) as $inside) {
            $translated = SubnetMapper::mapAddress($inside, $realCidr, $mapped);
            if ($translated === null) {
                continue;
            }

            $out[] = [
                'destination'      => $translated,
                'real_destination' => $inside,
                'filters'          => self::forRoute($networkId, $self, $inside),
            ];
        }

        return $out;
    }

    /**
     * The distinct rule destinations that name something narrower than the
     * advertised range.
     *
     * A rule about the whole LAN, or wider, is already covered by the base
     * entry; only a rule about part of it needs one of its own.
     *
     * @return list<string>
     */
    private static function ruleDestinationsInside(int $networkId, string $realCidr): array
    {
        [$prefixAddr, $prefixBits] = self::split($realCidr);
        if ($prefixAddr === null) {
            return [];
        }

        $seen = [];

        foreach (AclRule::forNetwork($networkId) as $rule) {
            if ($rule['dst_type'] !== 'cidr' || $rule['dst_value'] === null) {
                continue;
            }

            $value = trim((string) $rule['dst_value']);
            [$addr, $bits] = self::split($value);
            if ($addr === null || $bits <= $prefixBits) {
                continue;
            }
            if (!self::overlap($value, $realCidr)) {
                continue;
            }

            $seen[$value] = true;
        }

        return array_keys($seen);
    }

    /**
     * The same question asked of several prefixes at once: what may this
     * device reach inside each of the LANs a gateway routes for?
     *
     * Compiled into the **gateway's** configuration, keyed by the peer whose
     * traffic it would be forwarding. Without it the gateway forwards on the
     * strength of the peer link alone, and every rule about a machine behind
     * it is enforced only by the agent being restricted — which runs on
     * hardware the customer owns.
     *
     * @param array<string,mixed> $peer
     * @param list<array<string,mixed>> $served rows carrying destination_cidr and mapped_cidr
     * @return list<array<string,mixed>>
     */
    public static function forEachRoute(int $networkId, array $peer, array $served): array
    {
        $out = [];

        foreach ($served as $route) {
            foreach (self::compile(
                $networkId,
                $peer,
                (string) $route['destination_cidr'],
                isset($route['mapped_cidr']) ? (string) $route['mapped_cidr'] : null
            ) as $entry) {
                $out[] = ['destination' => $entry['destination'], 'filters' => $entry['filters']];
            }
        }

        return $out;
    }

    /**
     * Does a rule's destination govern this entry?
     *
     * Overlap is not the test, and using it was a bug. A rule naming
     * 192.168.1.50 overlaps the /24 that contains it, so compiling it into the
     * entry for the whole LAN opened tcp/554 on the till at 192.168.1.60 as
     * well — the rule said one machine and the agent enforced twenty.
     *
     * A rule governs an entry when it covers the entry: equal to it, or wider.
     * A narrower rule gets an entry of its own, which wins on longest prefix,
     * and that entry picks the wider rules up again through this same test.
     */
    public static function governs(string $ruleCidr, string $entryCidr): bool
    {
        [$_, $ruleBits] = self::split($ruleCidr);
        [$__, $entryBits] = self::split($entryCidr);

        if ($ruleBits > $entryBits) {
            return false;
        }

        return self::overlap($ruleCidr, $entryCidr);
    }

    /**
     * Do two ranges share any address?
     *
     * Compared by masking both to the shorter prefix: two ranges overlap
     * exactly when the wider one contains the narrower one's network address.
     */
    public static function overlap(string $a, string $b): bool
    {
        [$aAddr, $aBits] = self::split($a);
        [$bAddr, $bBits] = self::split($b);

        if ($aAddr === null || $bAddr === null) {
            return false;
        }

        $shorter = min($aBits, $bBits);
        if ($shorter <= 0) {
            return true;
        }

        $mask = $shorter === 32 ? 0xFFFFFFFF : (~((1 << (32 - $shorter)) - 1)) & 0xFFFFFFFF;

        return ($aAddr & $mask) === ($bAddr & $mask);
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
