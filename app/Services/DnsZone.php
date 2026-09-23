<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\Device;
use App\Models\NetworkRoute;
use App\Models\RouteHost;

/**
 * Names for a network, and the records an agent resolves them from (§18).
 *
 * Two kinds of name, because there are two kinds of machine:
 *
 *   laptop.acme.internal           a device running our agent
 *   nvr.hotel-abc.acme.internal    a machine behind the gateway "hotel-abc"
 *
 * The second is why this matters more than it looks. Since subnet mapping, the
 * address a technician connects to is one the panel invented — 10.128.0.50
 * rather than the 192.168.1.50 printed on the recorder — and expecting anybody
 * to carry that in their head, per site, is how a feature goes unused.
 *
 * **The zone is always under `.internal`.** ICANN reserved that TLD for
 * private use in 2024, so it can never collide with a real name, and refusing
 * anything else means a network cannot be configured to make its agents
 * authoritative for a domain somebody else owns. An operator who could set the
 * search domain to `google.com` would be one typo from a very confusing
 * afternoon.
 */
final class DnsZone
{
    public const SUFFIX = '.internal';

    /** Labels are DNS labels: lowercase, digits and hyphens, not at the ends. */
    private const LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * The zone for a network, derived from its name when nobody has set one.
     *
     * @param array<string,mixed> $network
     */
    public static function forNetwork(array $network): string
    {
        $configured = trim((string) ($network['search_domain'] ?? ''));
        if ($configured !== '') {
            return strtolower($configured);
        }

        $slug = self::slug((string) ($network['name'] ?? ''));
        if ($slug === '') {
            // A network with a name made entirely of characters DNS cannot
            // carry still needs a zone, and its uid is guaranteed to be
            // usable.
            $slug = strtolower((string) ($network['network_uid'] ?? 'net'));
        }

        return $slug . self::SUFFIX;
    }

    /**
     * Validate a zone an operator typed.
     *
     * @throws ValidationException
     */
    public static function validate(string $zone): string
    {
        $zone = strtolower(trim($zone, " \t\n\r\0\x0B."));

        if ($zone === '') {
            throw new ValidationException(['search_domain' => 'A search domain cannot be empty.']);
        }

        if (!str_ends_with($zone, self::SUFFIX)) {
            throw new ValidationException([
                'search_domain' => 'A search domain must end in ' . self::SUFFIX
                    . '. That top-level domain is reserved for private use, so a name here can never'
                    . ' collide with a real one — and the agent must never be made authoritative for'
                    . ' a domain somebody else owns.',
            ]);
        }

        foreach (explode('.', $zone) as $label) {
            if (preg_match(self::LABEL, $label) !== 1) {
                throw new ValidationException([
                    'search_domain' => "\"{$label}\" is not usable as a DNS label. Use lowercase letters,"
                        . ' digits and hyphens, not starting or ending with a hyphen.',
                ]);
            }
        }

        return $zone;
    }

    /** Turn a human name into one DNS label. */
    public static function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, 63);
    }

    /**
     * Every name this network resolves, as the agent will answer them.
     *
     * Addresses are the ones the overlay uses: a device's virtual IP, and for
     * a machine behind a gateway the **mapped** address, because the real one
     * is not reachable and answering with it would send a technician to
     * whatever happens to sit at that address on their own LAN.
     *
     * @param array<string,mixed> $network
     * @return list<array{name: string, address: string, kind: string}>
     */
    public static function records(array $network): array
    {
        $networkId = (int) $network['id'];
        $zone = self::forNetwork($network);

        $out = [];
        $seen = [];

        foreach (Device::activeInNetwork($networkId) as $device) {
            $label = self::slug((string) $device['name']);
            if ($label === '' || empty($device['virtual_ip'])) {
                continue;
            }

            self::add($out, $seen, $label . '.' . $zone, (string) $device['virtual_ip'], 'device');
        }

        foreach (RouteHost::forNetwork($networkId) as $host) {
            if ((int) $host['approved'] !== 1 || (int) $host['enabled'] !== 1) {
                // An unapproved route carries no traffic, so a name pointing
                // into it would resolve to an address nothing can reach.
                continue;
            }

            $label = self::slug((string) $host['label']);
            $site = self::slug((string) ($host['gateway_name'] ?? ''));
            if ($label === '' || $site === '') {
                continue;
            }

            $address = SubnetMapper::mapAddress(
                (string) $host['address'],
                (string) $host['destination_cidr'],
                (string) ($host['mapped_cidr'] ?: $host['destination_cidr'])
            );
            if ($address === null) {
                continue;
            }

            self::add($out, $seen, $label . '.' . $site . '.' . $zone, explode('/', $address)[0], 'lan');
        }

        return $out;
    }

    /**
     * @param list<array{name: string, address: string, kind: string}> $out
     * @param array<string,bool> $seen
     */
    private static function add(array &$out, array &$seen, string $name, string $address, string $kind): void
    {
        if (isset($seen[$name])) {
            // First one wins rather than last. Two devices sluggified to the
            // same label is possible — "Front Desk" and "front-desk" — and a
            // name that flips between two machines depending on row order is
            // worse than one that is stably wrong and can be noticed.
            return;
        }

        $seen[$name] = true;
        $out[] = ['name' => $name, 'address' => $address, 'kind' => $kind];
    }
}
