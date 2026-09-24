<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Auth;
use App\Core\ValidationException;
use App\Models\Device;
use App\Models\DeviceProbe;
use App\Models\NetworkRoute;

/**
 * "Can this computer reach that address?", asked from the panel.
 *
 * The whole value of this is that it is answered from the device rather than
 * from the panel. The panel sits on the public internet and can reach neither
 * the overlay nor anybody's camera recorder; the agent is already inside both.
 * So the panel writes down the question and the agent answers it on the poll
 * it was going to make anyway.
 *
 * What may be asked is deliberately narrow. An administrator can ask a device
 * about a peer on the overlay, or about an address inside a range that
 * administrator has already shared through that device. Anything else is
 * refused — a Test button that took an arbitrary address would be a port
 * scanner with a login page, reachable by every account on the panel.
 */
final class ProbeService
{
    /** No more than this many probes per device in the window below. */
    private const RATE_LIMIT = 20;

    /** The window the limit applies over, in seconds. */
    private const RATE_WINDOW = 60;

    /**
     * Ask a device to test one address.
     *
     * @return array<string,mixed> the pending probe
     */
    public static function request(int $deviceId, string $target, ?string $label = null): array
    {
        $device = Device::findOrFail($deviceId);
        $target = trim($target);

        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ValidationException(['target' => 'That is not an IPv4 address.']);
        }

        if (!self::mayProbe($device, $target)) {
            throw new ValidationException([
                'target' => 'This device may only be asked about its own peers and the '
                    . 'ranges shared through it. ' . $target . ' is neither.',
            ]);
        }

        if (DeviceProbe::countSince($deviceId, self::RATE_WINDOW) >= self::RATE_LIMIT) {
            throw new ValidationException([
                'target' => 'Too many tests for this device in the last minute. Wait a moment.',
            ]);
        }

        $id = DeviceProbe::create([
            'tenant_id'    => (int) $device['tenant_id'],
            'device_id'    => $deviceId,
            'target'       => $target,
            'label'        => $label !== null ? substr($label, 0, 128) : null,
            'state'        => 'pending',
            'requested_by' => Auth::id(),
            'requested_at' => gmdate('Y-m-d H:i:s'),
        ]);

        AuditService::log('device.probe', 'device', $deviceId, null, [
            'target' => $target,
            'label'  => $label,
        ]);

        return DeviceProbe::findOrFail($id);
    }

    /**
     * Is this an address this device is allowed to be asked about?
     *
     * Two cases, and nothing else:
     *
     *   - an address on the overlay, which is where its peers live;
     *   - an address inside a mapped range this device itself advertises,
     *     which is the customer's own LAN as the overlay refers to it.
     *
     * A device is never asked about another device's LAN, and never about a
     * public address: this is a reachability test between machines the
     * administrator already administers, not a way to reach the internet from
     * inside a customer's building.
     */
    private static function mayProbe(array $device, string $target): bool
    {
        $networkId = $device['network_id'] !== null ? (int) $device['network_id'] : 0;
        if ($networkId > 0) {
            $network = \App\Models\Network::find($networkId);
            if ($network !== null && self::inPrefix($target, (string) $network['cidr'])) {
                return true;
            }
        }

        // A route from before subnet mapping has no mapped range: its real
        // one is what the overlay uses.
        foreach (NetworkRoute::where(['via_device_id' => (int) $device['id']]) as $route) {
            if (self::inPrefix($target, (string) ($route['mapped_cidr'] ?: $route['destination_cidr']))) {
                return true;
            }
        }

        // And a range another device in the same network shares, once it is
        // approved: this device already has a route to it and may already
        // send it anything, so being asked to test it adds nothing it could
        // not do — and it is the only way to prove a share works from the
        // side that uses it (RouteHealth).
        if ($networkId > 0) {
            $shared = DB::select(
                'SELECT COALESCE(NULLIF(mapped_cidr, \'\'), destination_cidr) AS mapped_cidr FROM ' . DB::table('routes') . '
                 WHERE tenant_id = :t AND network_id = :n AND approved = 1 AND enabled = 1 AND deleted_at IS NULL',
                ['t' => (int) $device['tenant_id'], 'n' => $networkId]
            );
            foreach ($shared as $route) {
                if (self::inPrefix($target, (string) $route['mapped_cidr'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Is an address inside a CIDR? */
    private static function inPrefix(string $address, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$base, $bits] = explode('/', $cidr, 2);

        $a = ip2long($address);
        $b = ip2long($base);
        if ($a === false || $b === false) {
            return false;
        }

        $prefix = (int) $bits;
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;

        return ($a & $mask) === ($b & $mask);
    }
}
