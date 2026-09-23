<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\Network;
use App\Models\NetworkRoute;
use App\Models\RouteHost;

/**
 * Naming the machines behind a gateway (§18).
 *
 * An operator adds "nvr → 192.168.1.50" to a route, and a technician types
 * `nvr.hotel-abc.acme.internal`. The address stored is the real one, because
 * that is what is printed on the recorder and what somebody standing in front
 * of it can read; the address a technician connects to is derived from the
 * route's mapping when the configuration is built.
 */
final class RouteHostService
{
    /**
     * Name one machine inside an advertised range.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function add(int $routeId, array $input): array
    {
        $route = NetworkRoute::findOrFail($routeId);

        $label = DnsZone::slug((string) ($input['label'] ?? ''));
        if ($label === '') {
            throw new ValidationException([
                'label' => 'A name is required, using letters, digits and hyphens.',
            ]);
        }

        $address = trim((string) ($input['address'] ?? ''));
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidationException([
                'address' => 'Enter the machine\'s address on the site\'s own network, e.g. 192.168.1.50.',
            ]);
        }

        // Inside the range, or the name points at something this gateway does
        // not route for and will never resolve to anything reachable.
        if (!AclRouteFilters::overlap($address . '/32', (string) $route['destination_cidr'])) {
            throw new ValidationException([
                'address' => sprintf(
                    '%s is not inside %s, which is the range this gateway routes for.',
                    $address,
                    $route['destination_cidr']
                ),
            ]);
        }

        $id = RouteHost::create([
            'tenant_id'   => (int) $route['tenant_id'],
            'network_id'  => (int) $route['network_id'],
            'route_id'    => $routeId,
            'label'       => $label,
            'address'     => $address,
            'description' => isset($input['description']) ? trim((string) $input['description']) : null,
        ]);

        Network::bumpRevision((int) $route['network_id']);

        AuditService::log('route_host.add', 'route_host', $id, null, [
            'route_id' => $routeId,
            'label'    => $label,
            'address'  => $address,
        ]);

        return RouteHost::findOrFail($id);
    }

    /** Remove a name. The machine is unaffected; only the name goes. */
    public static function remove(int $hostId): void
    {
        $host = RouteHost::findOrFail($hostId);

        RouteHost::delete($hostId);
        Network::bumpRevision((int) $host['network_id']);

        AuditService::log('route_host.remove', 'route_host', $hostId, $host, null);
    }

    /**
     * The name a host answers to, for showing next to it in the panel.
     *
     * @param array<string,mixed> $host a row from RouteHost::forNetwork()
     * @param array<string,mixed> $network
     */
    public static function fullName(array $host, array $network): string
    {
        $site = DnsZone::slug((string) ($host['gateway_name'] ?? ''));
        if ($site === '') {
            return '';
        }

        return DnsZone::slug((string) $host['label']) . '.' . $site . '.' . DnsZone::forNetwork($network);
    }
}
