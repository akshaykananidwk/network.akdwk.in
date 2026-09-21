<?php

declare(strict_types=1);

/**
 * Route and LAN-rule commands for the lab harness.
 *
 * Included by lab-setup.php. Split out because subnet-router mode needs its
 * own handful of commands and lab-setup.php was already long enough to be
 * worth not reading twice.
 */

use App\Middleware\TenantScope;
use App\Models\Network;
use App\Models\NetworkRoute;
use App\Services\AclService;
use App\Services\RouteService;

/**
 * Advertise a LAN prefix through a gateway device, unapproved.
 *
 * @param list<string> $args
 */
function labRoute(array $args): int
{
    $networkId = (int) ($args[0] ?? 0);
    $cidr = (string) ($args[1] ?? '');
    $viaUid = (string) ($args[2] ?? '');

    if ($networkId <= 0 || $cidr === '' || $viaUid === '') {
        return fail('usage: route <network> <cidr> <via-device-uid>');
    }

    $device = findDevice($viaUid);
    if ($device === null) {
        return fail("device {$viaUid} not found");
    }

    $route = TenantScope::asTenant(
        (int) $device['tenant_id'],
        static fn (): array => RouteService::advertise($networkId, [
            'destination_cidr' => $cidr,
            'via_device_id'    => (int) $device['id'],
        ])
    );

    printf("ROUTE=%d\nAPPROVED=%d\n", (int) $route['id'], (int) $route['approved']);

    return 0;
}

/** Approve a route, which is what puts it in front of agents. */
function labRouteApprove(string $routeId): int
{
    $id = (int) $routeId;
    if ($id <= 0) {
        return fail('usage: route-approve <route-id>');
    }

    $route = TenantScope::acrossAllTenants(
        'lab harness',
        static fn (): ?array => NetworkRoute::find($id)
    );
    if ($route === null) {
        return fail("route {$id} not found");
    }

    TenantScope::asTenant((int) $route['tenant_id'], static function () use ($id): void {
        RouteService::approve($id);
    });

    printf("APPROVED=1\nAT=%.3f\n", microtime(true));

    return 0;
}

/**
 * Author an ACL rule whose destination is a LAN address rather than a device.
 *
 * This is the shape a rule takes for a machine behind a gateway: the NVR has
 * no device row to name, so the rule names its address.
 *
 * @param list<string> $args
 */
function labAclCidr(array $args): int
{
    $networkId = (int) ($args[0] ?? 0);
    $action = (string) ($args[1] ?? '');
    $srcUid = (string) ($args[2] ?? '');
    $dstCidr = (string) ($args[3] ?? '');
    $protocol = (string) ($args[4] ?? 'any');
    $port = (string) ($args[5] ?? '');

    if ($networkId <= 0 || $action === '' || $srcUid === '' || $dstCidr === '') {
        return fail('usage: acl-cidr <network> <allow|deny> <src-uid> <dst-cidr> [protocol] [port]');
    }

    $network = TenantScope::acrossAllTenants(
        'lab harness',
        static fn (): ?array => Network::find($networkId)
    );
    if ($network === null) {
        return fail("network {$networkId} not found");
    }

    $result = TenantScope::asTenant(
        (int) $network['tenant_id'],
        static function () use ($networkId, $action, $srcUid, $dstCidr, $protocol, $port): array {
            $rule = AclService::createRule($networkId, [
                'action'    => $action,
                'src_type'  => 'device',
                'src_value' => $srcUid,
                'dst_type'  => 'cidr',
                'dst_value' => $dstCidr,
                'protocol'  => $protocol,
                'port_from' => $port === '' ? null : $port,
                'port_to'   => $port === '' ? null : $port,
                'enabled'   => 1,
            ]);

            return ['rule' => (int) $rule['id']];
        }
    );

    printf("RULE=%d\nAT=%.3f\n", $result['rule'], microtime(true));

    return 0;
}
