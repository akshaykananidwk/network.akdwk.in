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
            if (!self::overlap((string) $rule['dst_value'], $routeCidr)) {
                continue;
            }

            $filters[] = AclService::toFilter($rule);
        }

        return $filters;
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
     * @param list<string> $prefixes
     * @return list<array<string,mixed>>
     */
    public static function forEachRoute(int $networkId, array $peer, array $prefixes): array
    {
        $out = [];

        foreach ($prefixes as $cidr) {
            $out[] = [
                'destination' => $cidr,
                'filters'     => self::forRoute($networkId, $peer, $cidr),
            ];
        }

        return $out;
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
