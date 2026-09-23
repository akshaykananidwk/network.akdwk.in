<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\Device;
use App\Models\Network;
use App\Models\NetworkRoute;

/**
 * Advertised subnets — the control plane for gateway (subnet-router) mode.
 *
 * A gateway device routes for machines that cannot run an agent: an NVR, a
 * printer, a DVR. Advertising a subnet is therefore a decision about which
 * parts of a customer's physical network become reachable over the overlay,
 * and it is an administrator's decision: a route is created disabled-pending
 * and carries no traffic until someone approves it.
 *
 * Three things are refused outright here, before approval is even offered:
 * a prefix that would capture the default route, a prefix that is the overlay
 * itself, and a second gateway for a prefix another device already advertises.
 */
final class RouteService
{
    /**
     * Advertise a subnet through a gateway device.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> the created route, unapproved
     */
    public static function advertise(int $networkId, array $input): array
    {
        $network = Network::findOrFail($networkId);
        $cidr = self::validateCidr((string) ($input['destination_cidr'] ?? ''), $network);
        $device = self::validateGateway((int) ($input['via_device_id'] ?? 0), $networkId);

        self::refuseDuplicate($networkId, $cidr);

        // Allocated before the row is written, because a route with no mapped
        // prefix is a route that would put the customer's real range into
        // every agent's routing table — which is the thing this exists to
        // prevent. Failing here refuses the advertisement rather than creating
        // a half-mapped one.
        $mapped = SubnetMapper::allocate($networkId, $cidr);

        $id = NetworkRoute::create([
            'tenant_id'        => (int) $network['tenant_id'],
            'network_id'       => $networkId,
            'destination_cidr' => $cidr,
            'mapped_cidr'      => $mapped,
            'via_device_id'    => (int) $device['id'],
            'metric'           => isset($input['metric']) ? max(1, (int) $input['metric']) : 100,
            'description'      => isset($input['description']) ? trim((string) $input['description']) : null,
            // Never advertised on creation. R4's reasoning applies to subnets
            // as much as to devices: nothing reaches a customer's LAN until a
            // person has said it may.
            'approved'         => 0,
            'enabled'          => 1,
        ]);

        // The device is a gateway from the moment it has a route to carry,
        // because the agent uses that flag to decide whether to configure
        // forwarding at all.
        Device::update((int) $device['id'], ['is_gateway' => 1]);

        AuditService::log('route.advertise', 'route', $id, null, [
            'destination_cidr' => $cidr,
            'mapped_cidr'      => $mapped,
            'via_device_id'    => (int) $device['id'],
        ]);

        return NetworkRoute::findOrFail($id);
    }

    /**
     * The LANs this device already shares.
     *
     * @return list<array<string,mixed>>
     */
    public static function forDevice(int $deviceId): array
    {
        return NetworkRoute::where(['via_device_id' => $deviceId], 'id', 'ASC');
    }

    /**
     * The LAN this device is probably on, from the address it reports.
     *
     * A shop router hands out 192.168.10.x and the PC reports 192.168.10.23;
     * the network is almost always 192.168.10.0/24. "Almost always" is why
     * this is a suggestion put in an editable box rather than something acted
     * on: a site on a /16, or on two subnets, would be quietly wrong, and
     * quietly wrong about which range reaches a customer's cameras is not a
     * thing to be.
     *
     * Returns '' when there is nothing to suggest, which is the honest answer
     * for a device that has never reported a LAN address.
     */
    public static function suggestLan(?string $lanEndpoint): string
    {
        $address = trim((string) $lanEndpoint);
        if ($address === '') {
            return '';
        }

        // Endpoints arrive as address:port.
        if (($colon = strrpos($address, ':')) !== false) {
            $address = substr($address, 0, $colon);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return '';
        }

        // Only for addresses that are actually private. A public address is
        // the device's own internet address, and suggesting a /24 of it would
        // offer to route a stranger's network.
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false) {
            return '';
        }

        $parts = explode('.', $address);

        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }

    /**
     * Share one LAN through one device, in a single click.
     *
     * The long way round is: tick "this device is a gateway", go to the
     * network, add a route, choose the device, type the range, then approve
     * it. Five steps and two pages to let a hotel reach its own camera
     * recorder — which is the single most common thing anybody buys this for.
     *
     * Approved here, deliberately. R4 says nothing reaches a customer's LAN
     * until a person has said it may, and an administrator pressing this
     * button, on this device, having read the range in the box, IS that
     * person saying so. Adding a second click to approve what they have just
     * asked for is ceremony, not consent.
     *
     * @return array<string,mixed> the route
     */
    public static function shareLan(int $deviceId, string $cidr): array
    {
        $device = Device::findOrFail($deviceId);
        $networkId = (int) $device['network_id'];

        $route = self::advertise($networkId, [
            'destination_cidr' => $cidr,
            'via_device_id'    => $deviceId,
            'description'      => 'Shared from ' . (string) $device['name'],
        ]);

        return self::approve((int) $route['id']);
    }

    /** Approve a route, which is what actually puts it in front of agents. */
    public static function approve(int $routeId): array
    {
        $route = NetworkRoute::findOrFail($routeId);

        NetworkRoute::update($routeId, ['approved' => 1]);
        Network::bumpRevision((int) $route['network_id']);

        AuditService::log('route.approve', 'route', $routeId, null, [
            'destination_cidr' => $route['destination_cidr'],
        ]);

        return NetworkRoute::findOrFail($routeId);
    }

    /** Withdraw a route. The gateway stops forwarding for it on next poll. */
    public static function withdraw(int $routeId): void
    {
        $route = NetworkRoute::findOrFail($routeId);

        NetworkRoute::delete($routeId);
        Network::bumpRevision((int) $route['network_id']);

        // A device with no routes left is no longer a gateway, so it stops
        // configuring forwarding and stops being a way into a LAN.
        $viaDeviceId = $route['via_device_id'] !== null ? (int) $route['via_device_id'] : 0;
        if ($viaDeviceId > 0 && NetworkRoute::cidrsViaDevice($viaDeviceId) === []) {
            Device::update($viaDeviceId, ['is_gateway' => 0]);
        }

        AuditService::log('route.withdraw', 'route', $routeId, null, [
            'destination_cidr' => $route['destination_cidr'],
        ]);
    }

    /**
     * @param array<string,mixed> $network
     */
    private static function validateCidr(string $cidr, array $network): string
    {
        $cidr = trim($cidr);
        if ($cidr === '' || !str_contains($cidr, '/')) {
            throw new ValidationException(['destination_cidr' => 'A subnet such as 192.168.1.0/24 is required.']);
        }

        [$address, $bits] = explode('/', $cidr, 2);
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidationException(['destination_cidr' => 'The subnet address is not a valid IPv4 address.']);
        }

        $prefix = (int) $bits;
        if ((string) $prefix !== $bits || $prefix < 8 || $prefix > 32) {
            throw new ValidationException(['destination_cidr' => 'The prefix length must be between 8 and 32.']);
        }

        // R1, enforced here as well as on the agent. A gateway advertising
        // 0.0.0.0/0 — or a /1, which is half the internet and pairs into all
        // of it — would turn every client's split tunnel into a full one.
        if ($prefix <= 1) {
            throw new ValidationException([
                'destination_cidr' => 'That prefix would capture the default route. '
                    . 'This is a split-tunnel product and will not advertise it.',
            ]);
        }

        // Normalised to the network address before anything compares it.
        //
        // A field test shared 192.168.1.0/24 and then 192.168.1.1/24 on the
        // same device and got both: the same LAN, twice, with two mapped
        // prefixes allocated to it and two routes for a packet to take. Every
        // check below this line, and the duplicate check above, compares
        // strings — so any host address inside a range it had already been
        // given was a brand new subnet to them. A person typing the router's
        // own address is not making a second network; they are naming the
        // same one the way a person names it.
        $cidr = self::networkAddressOf($address, $prefix) . '/' . $prefix;

        if ($cidr === (string) $network['cidr']) {
            throw new ValidationException([
                'destination_cidr' => 'That is the overlay\'s own range. A gateway cannot route the tunnel back into itself.',
            ]);
        }

        return $cidr;
    }

    /**
     * The network address a host address and prefix name.
     *
     * 192.168.1.1/24 and 192.168.1.0/24 are the same subnet, and the second
     * is what the routing table wants.
     */
    private static function networkAddressOf(string $address, int $prefix): string
    {
        $long = ip2long($address);
        if ($long === false) {
            return $address;
        }

        // A /0 mask cannot be written as -1 << 32 in PHP, and a /0 is refused
        // above anyway; this keeps the shift in range for every case that
        // reaches it.
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;

        return long2ip($long & $mask);
    }

    /** @return array<string,mixed> */
    private static function validateGateway(int $deviceId, int $networkId): array
    {
        $device = $deviceId > 0 ? Device::find($deviceId) : null;

        if ($device === null || (int) $device['network_id'] !== $networkId) {
            throw new ValidationException(['via_device_id' => 'Choose a device on this network to route through.']);
        }

        if ($device['status'] !== 'authorized') {
            throw new ValidationException([
                'via_device_id' => 'That device has not been approved yet, so it cannot route for anything.',
            ]);
        }

        return $device;
    }

    /**
     * Refuse a second gateway for a subnet another device already advertises.
     *
     * A client has one routing table. Two routes for one destination is not a
     * redundancy feature, it is an ambiguity, and the client would silently
     * pick one — which is worse than saying so. The unique key on the table
     * covers (network, subnet, gateway) and so does not catch this case: two
     * *different* gateways offering the same subnet satisfy it.
     */
    private static function refuseDuplicate(int $networkId, string $cidr): void
    {
        foreach (NetworkRoute::forNetwork($networkId, false) as $existing) {
            $other = (string) $existing['destination_cidr'];

            // Overlapping, not just identical. 192.168.1.0/24 inside an
            // existing 192.168.0.0/16 is not a second network either, and two
            // routes covering one address is a packet with two places to go.
            if (!self::overlaps($cidr, $other)) {
                continue;
            }

            $via = $existing['via_device_name'] ?? 'another device';

            throw new ValidationException([
                'destination_cidr' => $cidr === $other
                    ? sprintf(
                        '%s is already advertised through %s on this network. '
                        . 'Withdraw that route first — a device can only have one route to a subnet.',
                        $cidr,
                        $via
                    )
                    : sprintf(
                        '%s overlaps %s, which is already advertised through %s. '
                        . 'Two routes covering the same address give a packet two places to go. '
                        . 'Withdraw that one first, or advertise a range that does not overlap it.',
                        $cidr,
                        $other,
                        $via
                    ),
            ]);
        }
    }

    /**
     * Do two CIDRs cover any address in common?
     *
     * The shorter prefix is the wider range, so they overlap exactly when the
     * wider one contains the narrower one's network address.
     */
    private static function overlaps(string $a, string $b): bool
    {
        [$aAddr, $aBits] = array_pad(explode('/', $a, 2), 2, '32');
        [$bAddr, $bBits] = array_pad(explode('/', $b, 2), 2, '32');

        $aLong = ip2long($aAddr);
        $bLong = ip2long($bAddr);
        if ($aLong === false || $bLong === false) {
            return $a === $b;
        }

        $bits = min((int) $aBits, (int) $bBits);
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($aLong & $mask) === ($bLong & $mask);
    }
}
