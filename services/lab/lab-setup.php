#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Panel-side helper for the lab harness.
 *
 * The networking drills need a tenant, a network and a join code, and they
 * need to approve and revoke devices the way an administrator would. Doing
 * that through the real services rather than SQL is the point: a drill that
 * set up its own rows would not exercise the code a customer's traffic
 * depends on.
 *
 *   lab-setup.php seed                 print TENANT/NETWORK/JOIN_CODE
 *   lab-setup.php approve <uid>...     approve devices, print their addresses
 *   lab-setup.php revoke <uid>         revoke one device
 *   lab-setup.php reapprove <uid>      put a revoked device back
 *   lab-setup.php relays <host> <port>... register the lab relay fleet
 *   lab-setup.php unthrottle           clear the rate limiter
 *   lab-setup.php usage <tenant>       print relayed byte counters
 *   lab-setup.php teardown <tenant>    remove everything the drill created
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();
require APP_ROOT . '/app/Core/helpers.php';
App\Core\Config::load(APP_ROOT . '/config/config.php');

use App\Core\DB;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Plan;
use App\Models\Relay;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Services\DeviceService;
use App\Services\IpamService;

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

exit(match ($command) {
    'seed'      => labSeed(),
    'approve'   => labApprove($args),
    'revoke'    => labRevoke($args[0] ?? ''),
    'reapprove' => labReapprove($args[0] ?? ''),
    'relays'    => labRelays($args),
    'unthrottle' => labUnthrottle(),
    'usage'     => labUsage((int) ($args[0] ?? 0)),
    'teardown'  => labTeardown((int) ($args[0] ?? 0)),
    default     => fail('unknown command: ' . $command),
});

function fail(string $message): int
{
    fwrite(STDERR, "  lab-setup: {$message}\n");

    return 1;
}

function labSeed(): int
{
    $plan = Plan::publicPlans()[0] ?? null;
    if ($plan === null) {
        return fail('no plans are seeded; run the installer first');
    }

    $slug = 'lab-' . bin2hex(random_bytes(4));

    $tenantId = TenantScope::acrossAllTenants('lab harness', static fn (): int => Tenant::create([
        'company_name'  => 'Lab Harness',
        'slug'          => $slug,
        'email'         => $slug . '@example.test',
        'plan_id'       => (int) $plan['id'],
        'device_limit'  => 20,
        'network_limit' => 2,
        'user_limit'    => 2,
        'status'        => 'active',
        'timezone'      => 'UTC',
    ]));

    $networkId = 0;
    $code = '';

    TenantScope::asTenant($tenantId, static function () use ($tenantId, &$networkId, &$code): void {
        $networkId = Network::create([
            'tenant_id'   => $tenantId,
            'network_uid' => bin2hex(random_bytes(8)),
            'name'        => 'Lab Net',
            'cidr'        => '10.99.0.0/24',
            'status'      => 'active',
        ]);

        IpamService::createPool($tenantId, $networkId, '10.99.0.0/24');

        $issued = JoinCode::issue($tenantId, $networkId, null, 20, 1440);
        $code = (string) $issued['code'];
    });

    printf("TENANT=%d\nNETWORK=%d\nJOIN_CODE=%s\n", $tenantId, $networkId, $code);

    return 0;
}

/** @param list<string> $uids */
function labApprove(array $uids): int
{
    foreach ($uids as $uid) {
        $device = findDevice($uid);
        if ($device === null) {
            return fail("device {$uid} not found");
        }

        TenantScope::asTenant((int) $device['tenant_id'], static function () use ($device): void {
            DeviceService::approve((int) $device['id']);
        });

        $after = findDevice($uid);
        printf("%s=%s\n", $uid, (string) ($after['virtual_ip'] ?? ''));
    }

    return 0;
}

function labRevoke(string $uid): int
{
    $device = findDevice($uid);
    if ($device === null) {
        return fail("device {$uid} not found");
    }

    TenantScope::asTenant((int) $device['tenant_id'], static function () use ($device): void {
        DeviceService::revoke((int) $device['id'], 'lab drill');
    });

    printf("REVOKED_AT=%s\n", microtime(true));

    return 0;
}

function labReapprove(string $uid): int
{
    $device = findDevice($uid);
    if ($device === null) {
        return fail("device {$uid} not found");
    }

    TenantScope::asTenant((int) $device['tenant_id'], static function () use ($device): void {
        Device::update((int) $device['id'], ['status' => 'pending']);
        DeviceService::approve((int) $device['id']);
    });

    return 0;
}

/**
 * Print the numbers a customer would be billed on.
 *
 * RELAY_BYTES is the metered figure: the one that turns into an invoice. The
 * per-device totals are printed beside it because a mismatch between them and
 * the counter means the metering, not the tunnel, is wrong — and that is a
 * difference worth being able to see at a glance.
 */
/**
 * Register the lab's relays so agents are told about them.
 *
 * Relays are platform-level, not tenant-scoped, so this replaces the whole
 * fleet rather than adding to it: a previous run's relays on ports that are no
 * longer listening would be measured as unreachable and skew the drill.
 *
 * Region is left empty on purpose. The drill has to prove that selection works
 * on measured latency alone, which it cannot do if a region label is quietly
 * doing the work.
 *
 * @param list<string> $args
 */
function labRelays(array $args): int
{
    $host = (string) ($args[0] ?? '');
    $ports = array_slice($args, 1);

    if ($host === '' || $ports === []) {
        return fail('usage: relays <host> <port> [<port>...]');
    }

    TenantScope::acrossAllTenants('lab harness', static function () use ($host, $ports): void {
        DB::execute('DELETE FROM ' . DB::table('relays'));

        foreach ($ports as $index => $port) {
            Relay::create([
                'name'       => 'lab-' . chr(ord('a') + (int) $index),
                'region'     => '',
                'host'       => $host,
                'port'       => (int) $port,
                'tcp_port'   => (int) $port,
                'public_key' => base64_encode(random_bytes(32)),
                'status'     => 'active',
            ]);
        }
    });

    printf("RELAYS=%d\n", count($ports));

    return 0;
}

/**
 * Clear the rate limiter between scenarios.
 *
 * Enrolment is throttled to 60 requests an hour per address, which is right
 * for a public endpoint and wrong for a drill that enrols eighteen devices
 * from two addresses in twenty minutes. Clearing it keeps the drill measuring
 * the network rather than the throttle.
 *
 * The limit itself is a real question for a customer rolling out a site in one
 * afternoon — see VERIFICATION_REPORT.md.
 */
function labUnthrottle(): int
{
    TenantScope::acrossAllTenants('lab harness', static function (): void {
        DB::execute('DELETE FROM ' . DB::table('rate_limits'));
    });

    return 0;
}

function labUsage(int $tenantId): int
{
    if ($tenantId <= 0) {
        return fail('a tenant id is required');
    }

    $counters = TenantScope::asTenant($tenantId, static fn (): array => DB::select(
        'SELECT metric, value_num FROM ' . DB::table('usage_counters') . '
         WHERE tenant_id = :t AND period = :p',
        ['t' => $tenantId, 'p' => gmdate('Y-m')]
    ));

    $byMetric = [];
    foreach ($counters as $counter) {
        $byMetric[(string) $counter['metric']] = (int) $counter['value_num'];
    }

    $devices = TenantScope::asTenant($tenantId, static fn (): ?array => DB::selectOne(
        'SELECT COALESCE(SUM(rx_bytes), 0) AS rx, COALESCE(SUM(tx_bytes), 0) AS tx
         FROM ' . DB::table('devices') . ' WHERE tenant_id = :t',
        ['t' => $tenantId]
    ));

    printf(
        "RELAY_BYTES=%d\nDIRECT_BYTES=%d\nDEVICE_RX=%d\nDEVICE_TX=%d\n",
        $byMetric[UsageCounter::METRIC_RELAY_BYTES] ?? 0,
        $byMetric[UsageCounter::METRIC_DIRECT_BYTES] ?? 0,
        (int) ($devices['rx'] ?? 0),
        (int) ($devices['tx'] ?? 0)
    );

    return 0;
}

function labTeardown(int $tenantId): int
{
    if ($tenantId <= 0) {
        return fail('a tenant id is required');
    }

    TenantScope::acrossAllTenants('lab harness', static function () use ($tenantId): void {
        foreach (['ip_allocations', 'join_codes', 'devices', 'networks', 'audit_logs', 'notifications', 'usage_counters'] as $table) {
            DB::execute('DELETE FROM ' . DB::table($table) . ' WHERE tenant_id = :t', ['t' => $tenantId]);
        }
        DB::execute('DELETE FROM ' . DB::table('tenants') . ' WHERE id = :t', ['t' => $tenantId]);
    });

    return 0;
}

/** @return array<string,mixed>|null */
function findDevice(string $uid): ?array
{
    return TenantScope::acrossAllTenants(
        'lab harness',
        static fn (): ?array => Device::findByUid($uid)
    );
}
