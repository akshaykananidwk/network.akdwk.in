<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Network;
use App\Models\NetworkRoute;
use App\Services\RouteHostService;
use App\Services\RouteService;

/**
 * Advertised LANs, and the machines named inside them (§16–18).
 *
 * Separate from NetworkController because that file is already long and
 * because this is a different subject: a route is about a building somebody
 * else's equipment sits in, not about the overlay.
 *
 * Nothing here decides anything. Every rule about what may be advertised,
 * which prefix it is mapped to and whether a name is usable lives in
 * RouteService, SubnetMapper and RouteHostService — this turns a form into a
 * call and a call into a message a person can read.
 */
final class NetworkRouteController extends Controller
{
    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        $route = RouteService::advertise($networkId, $request->all());

        return $this->redirect(
            'networks/' . $networkId . '?tab=routes',
            sprintf(
                '%s advertised as %s. It carries nothing until you approve it.',
                $route['destination_cidr'],
                $route['mapped_cidr'] ?: $route['destination_cidr']
            )
        );
    }

    /** @param array<string,string> $params */
    public function approve(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        $route = RouteService::approve((int) $params['routeId']);

        return $this->redirect(
            'networks/' . $networkId . '?tab=routes',
            sprintf(
                'Approved. Devices reach %s at %s within 10 seconds.',
                $route['destination_cidr'],
                $route['mapped_cidr'] ?: $route['destination_cidr']
            )
        );
    }

    /** @param array<string,string> $params */
    public function withdraw(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        RouteService::withdraw((int) $params['routeId']);

        return $this->redirect(
            'networks/' . $networkId . '?tab=routes',
            'Route withdrawn. Devices stop routing it within 10 seconds.'
        );
    }

    /** @param array<string,string> $params */
    public function storeHost(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        $network = Network::findOrFail($networkId);

        $host = RouteHostService::add((int) $params['routeId'], $request->all());

        $route = NetworkRoute::findOrFail((int) $params['routeId']);
        $name = RouteHostService::fullName(
            $host + ['gateway_name' => $this->gatewayName($route)],
            $network
        );

        return $this->redirect(
            'networks/' . $networkId . '?tab=routes',
            $name === ''
                ? sprintf('%s named.', $host['address'])
                : sprintf('%s is now %s.', $host['address'], $name)
        );
    }

    /** @param array<string,string> $params */
    public function deleteHost(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        RouteHostService::remove((int) $params['hostId']);

        return $this->redirect(
            'networks/' . $networkId . '?tab=routes',
            'Name removed. The machine is unaffected; only the name is gone.'
        );
    }

    /** @param array<string,mixed> $route */
    private function gatewayName(array $route): string
    {
        $gateway = \App\Models\Device::find((int) ($route['via_device_id'] ?? 0));

        return (string) ($gateway['name'] ?? '');
    }
}
