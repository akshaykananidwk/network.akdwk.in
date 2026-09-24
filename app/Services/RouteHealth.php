<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;
use App\Models\Device;
use App\Models\RouteHost;

/**
 * Whether a shared LAN actually works — proven, not assumed.
 *
 * A share used to be shown "live" as soon as it was approved. On a Windows
 * gateway without WinNAT (every ordinary Windows 10 Pro PC) the gateway put
 * peers' packets onto the LAN with their overlay address as the source, the
 * router at 192.168.10.1 had no way to answer, and the page said "live" the
 * whole time.
 *
 * Now the status is, in order:
 *
 *   - waiting for approval / disabled — nothing is routed yet;
 *   - NOT working, with the reason — the computer sharing it is offline, its
 *     gateway mode is off, or its agent reports that its gateway could not
 *     start;
 *   - the result of the last end-to-end test through the computer sharing it
 *     NOW: another computer in the network pinging (or connecting to) an
 *     address in the share, through the tunnel and the gateway, and getting
 *     the answer — "working, proven at 14:02 from AVFM7NN, 21 ms", or "NOT
 *     working" with what failed. A proof older than a day is shown as that,
 *     not as working; a test nobody picked up within minutes is a failure;
 *   - not tested yet, until somebody presses Test.
 */
final class RouteHealth
{
    /** Probe label that ties a probe to the share and the gateway it tested. */
    public const LABEL_PREFIX = 'share-test:route:';

    /** A test not picked up in this long was not going to be. */
    public const PENDING_EXPIRES_SECONDS = 180;

    /** A proof this old is history, not a status. */
    public const PROOF_FRESH_SECONDS = 86400;

    /**
     * The label of a test of this share through its current gateway. The
     * gateway is part of it so that moving the share to another computer
     * does not carry the old one's proof across.
     *
     * @param array<string,mixed> $route
     */
    public static function label(array $route): string
    {
        return self::LABEL_PREFIX . (int) $route['id'] . ':via:' . (int) $route['via_device_id'];
    }

    /**
     * @param array<string,mixed>      $route   network_routes row
     * @param array<string,mixed>|null $gateway the device sharing it
     * @param array<string,mixed>|null $probe   the latest test, with tester_name
     * @return array{state:string,text:string,detail:string}
     */
    public static function of(array $route, ?array $gateway, ?array $probe, ?int $now = null): array
    {
        $now ??= time();

        if ((int) ($route['approved'] ?? 0) !== 1) {
            return ['state' => 'waiting', 'text' => 'waiting for approval', 'detail' => ''];
        }
        if ((int) ($route['enabled'] ?? 1) !== 1) {
            return ['state' => 'disabled', 'text' => 'disabled', 'detail' => ''];
        }

        if ($gateway === null || !Device::isOnline($gateway)) {
            return [
                'state'  => 'broken',
                'text'   => 'NOT working',
                'detail' => 'the computer sharing it' . ($gateway !== null ? ' (' . $gateway['name'] . ')' : '')
                    . ' is offline',
            ];
        }

        if ((int) ($gateway['is_gateway'] ?? 0) !== 1) {
            return [
                'state'  => 'broken',
                'text'   => 'NOT working',
                'detail' => 'gateway mode is turned off on ' . $gateway['name']
                    . ' — tick "This device is a gateway" on its page',
            ];
        }

        $problems = json_decode((string) ($gateway['problems_json'] ?? ''), true);
        foreach (is_array($problems) ? $problems : [] as $problem) {
            if (is_array($problem) && (string) ($problem['code'] ?? '') === 'gateway.failed') {
                return ['state' => 'broken', 'text' => 'NOT working', 'detail' => (string) ($problem['detail'] ?? '')];
            }
        }

        if ($probe === null) {
            return ['state' => 'untested', 'text' => 'not tested yet', 'detail' => 'Test proves it end to end'];
        }

        $from = (string) ($probe['tester_name'] ?? 'another computer');
        $target = (string) $probe['target'];
        $asked = self::epoch((string) $probe['requested_at']);
        $answered = self::epoch((string) ($probe['answered_at'] ?? ''));
        $when = local_time((string) ($probe['answered_at'] ?? $probe['requested_at']));

        switch ((string) $probe['state']) {
            case 'pending':
                if ($asked !== null && $now - $asked > self::PENDING_EXPIRES_SECONDS) {
                    return ['state' => 'broken', 'text' => 'NOT working', 'detail' => $from
                        . ' did not run the test asked of it at ' . local_time((string) $probe['requested_at'])
                        . ' — it may have gone offline; press Test again'];
                }

                return ['state' => 'testing', 'text' => 'testing…', 'detail' => 'asked ' . $from . ' to reach ' . $target];

            case 'ok':
                $proof = 'from ' . $from . ' to ' . $target
                    . ($probe['latency_ms'] !== null ? ', ' . (int) $probe['latency_ms'] . ' ms' : '')
                    . ($probe['method'] !== null ? ' (' . $probe['method'] . ')' : '');
                if ($answered !== null && $now - $answered > self::PROOF_FRESH_SECONDS) {
                    return ['state' => 'stale', 'text' => 'last proven ' . $when,
                        'detail' => $proof . ' — press Test to prove it again'];
                }

                return ['state' => 'working', 'text' => 'working', 'detail' => 'proven ' . $when . ' ' . $proof];

            default:
                return ['state' => 'broken', 'text' => 'NOT working', 'detail' => 'test from ' . $from . ' at '
                    . $when . ' to ' . $target . ' failed'
                    . ((string) ($probe['error'] ?? '') !== '' ? ': ' . $probe['error'] : '')
                    . ' — if nothing is meant to answer at ' . $target . ', test another address in the range'];
        }
    }

    /**
     * The latest test of each share through its current gateway, with the
     * name of the computer that ran it.
     *
     * @param list<array<string,mixed>> $routes
     * @return array<int,array<string,mixed>> route id => probe
     */
    public static function latestTests(int $tenantId, array $routes): array
    {
        $out = [];
        foreach ($routes as $route) {
            $row = DB::selectOne(
                'SELECT p.*, d.name AS tester_name
                 FROM ' . DB::table('device_probes') . ' p
                 JOIN ' . DB::table('devices') . ' d ON d.id = p.device_id
                 WHERE p.tenant_id = :t AND p.label = :l
                 ORDER BY p.id DESC LIMIT 1',
                ['t' => $tenantId, 'l' => self::label($route)]
            );
            if ($row !== null) {
                $out[(int) $route['id']] = $row;
            }
        }

        return $out;
    }

    /**
     * Ask another computer in the network to reach an address in the share.
     *
     * The tester is the one given, or the most recently heard online,
     * authorized device in the network that is not the gateway itself — the
     * gateway reaching its own LAN proves nothing about the tunnel — and that
     * the access rules let reach this share: one they do not would report a
     * working share as broken. The target is the one given, else the first
     * machine named in the share, else the first address of the range as the
     * overlay sees it — the site's router, almost always.
     *
     * @param array<string,mixed> $route
     * @return array<string,mixed> the probe
     * @throws ValidationException
     */
    public static function test(array $route, ?int $fromDeviceId = null, ?string $target = null): array
    {
        $mapped = (string) ($route['mapped_cidr'] ?: $route['destination_cidr']);

        // Say what is really wrong before looking for a tester: every tester
        // would otherwise be "not allowed", and the admin sent to the rules.
        $gateway = Device::find((int) $route['via_device_id']);
        if ((int) ($route['approved'] ?? 0) !== 1 || (int) ($route['enabled'] ?? 1) !== 1) {
            throw new ValidationException(['target' => 'This share is not approved and enabled, so nothing is routed to test.']);
        }
        if ($gateway === null) {
            throw new ValidationException(['target' => 'The computer sharing this range no longer exists.']);
        }
        if ((int) ($gateway['is_gateway'] ?? 0) !== 1) {
            throw new ValidationException(['target' => 'Gateway mode is turned off on ' . $gateway['name']
                . ', so no other computer is sent this range. Tick "This device is a gateway" on its page first.']);
        }

        $target = trim((string) $target);
        if ($target === '') {
            $target = self::defaultTarget($route, $mapped);
        }
        if (!self::inPrefix($target, $mapped)) {
            throw new ValidationException(['target' => $target . ' is not inside ' . $mapped
                . ', the range the other computers reach this share at.']);
        }

        $candidates = DB::select(
            'SELECT * FROM ' . DB::table('devices') . '
             WHERE tenant_id = :t AND network_id = :n AND status = \'authorized\' AND deleted_at IS NULL
               AND id <> :via
             ORDER BY last_seen_at DESC',
            ['t' => (int) $route['tenant_id'], 'n' => (int) $route['network_id'], 'via' => (int) $route['via_device_id']]
        );

        // First choice: a computer no rule restricts on the way to the
        // target, so an answer — or its absence — is about the share and not
        // about the rules. A restricted one only when there is nothing else.
        $tester = null;
        $restricted = null;
        $onlineButRuledOut = false;
        foreach ($candidates as $device) {
            if ($fromDeviceId !== null && (int) $device['id'] !== $fromDeviceId) {
                continue;
            }
            if (!Device::isOnline($device)) {
                continue;
            }
            if (!self::mayReach((int) $route['network_id'], $device, $gateway, $mapped)) {
                $onlineButRuledOut = true;
                continue;
            }
            if (self::restrictedTowards((int) $route['network_id'], $device, $route, $target)) {
                $restricted ??= $device;
                continue;
            }
            $tester = $device;
            break;
        }
        $tester ??= $restricted;

        if ($tester === null) {
            throw new ValidationException(['target' => match (true) {
                $onlineButRuledOut => 'No online computer in this network is allowed by the access rules to reach '
                    . 'this share, so none can test it.',
                $fromDeviceId !== null => 'That computer is not online to test from.',
                default => 'No other computer in this network is online to test from. A share can only be proven '
                    . 'from a computer that uses it.',
            }]);
        }

        return ProbeService::request((int) $tester['id'], $target, self::label($route));
    }

    /**
     * Whether the access rules give this device a way to the share: its
     * compiled peer set has the gateway, carrying the share's range.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $gateway
     */
    private static function mayReach(int $networkId, array $device, array $gateway, string $mapped): bool
    {
        foreach (AclService::buildPeerSet($networkId, (int) $device['id']) as $peer) {
            if ((string) $peer['uid'] === (string) $gateway['device_uid']) {
                return in_array($mapped, (array) $peer['allowed_ips'], true);
            }
        }

        return false;
    }

    /**
     * Whether the rules the gateway enforces for this device name the LAN
     * addresses on the way to the target — rules about machines inside the
     * share (an NVR on tcp/554 only), which the peer set does not show. The
     * most specific entry covering the target decides, as on the agent.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $route
     */
    private static function restrictedTowards(int $networkId, array $device, array $route, string $target): bool
    {
        $best = null;
        $bestBits = -1;
        foreach (AclRouteFilters::compile(
            $networkId,
            $device,
            (string) $route['destination_cidr'],
            isset($route['mapped_cidr']) ? (string) $route['mapped_cidr'] : null
        ) as $entry) {
            $bits = (int) (explode('/', (string) $entry['destination'], 2)[1] ?? 32);
            if ($bits > $bestBits && self::inPrefix($target, (string) $entry['destination'])) {
                $best = $entry;
                $bestBits = $bits;
            }
        }

        return $best !== null && (array) $best['filters'] !== [];
    }

    /**
     * The first machine the share names, in overlay terms, or the range's
     * first address. Named machines are stored by their real address, and
     * are translated the way the agent translates them: host bits kept.
     *
     * @param array<string,mixed> $route
     */
    private static function defaultTarget(array $route, string $mapped): string
    {
        foreach (RouteHost::forRoute((int) $route['id']) as $host) {
            $translated = SubnetMapper::mapAddress((string) $host['address'], (string) $route['destination_cidr'], $mapped);
            if ($translated !== null) {
                return explode('/', $translated, 2)[0];
            }
        }

        [$base, $bits] = array_pad(explode('/', $mapped, 2), 2, '32');
        $long = ip2long($base);
        if ($long === false) {
            return '';
        }
        $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return (string) long2ip(($long & $mask) + ((int) $bits < 31 ? 1 : 0));
    }

    private static function epoch(string $utc): ?int
    {
        if ($utc === '') {
            return null;
        }
        $t = strtotime($utc . ' UTC');

        return $t === false ? null : $t;
    }

    private static function inPrefix(string $ip, string $cidr): bool
    {
        [$base, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipLong = ip2long($ip);
        $baseLong = ip2long($base);
        if ($ipLong === false || $baseLong === false) {
            return false;
        }
        $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($baseLong & $mask);
    }
}
