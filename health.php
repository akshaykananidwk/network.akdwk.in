<?php

declare(strict_types=1);

/**
 * Health endpoint.
 *
 * Deliberately standalone: it must answer during maintenance mode, mid-update
 * and when the router itself is broken, so it does not route through the
 * kernel and does not start a session.
 *
 * It reveals nothing an unauthenticated caller should not see — no versions of
 * dependencies, no hostnames, no counts. ?deep=1 with a valid token adds
 * detail for monitoring.
 */

define('APP_ROOT', __DIR__);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$started = microtime(true);
$checks = [];
$healthy = true;

// 1. Are we installed at all?
$configFile = APP_ROOT . '/config/config.php';
$checks['installed'] = is_file($configFile);
if (!$checks['installed']) {
    http_response_code(503);
    echo json_encode(['status' => 'not_installed', 'checks' => $checks]);
    exit;
}

require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();

App\Core\Config::load(
    $configFile,
    is_file(APP_ROOT . '/config/.env') ? APP_ROOT . '/config/.env' : APP_ROOT . '/.env'
);
App\Core\Logger::configure(APP_ROOT . '/storage/logs', 'error');

// 2. Database reachable?
try {
    $checks['database'] = (int) App\Core\DB::scalar('SELECT 1') === 1;
} catch (Throwable) {
    $checks['database'] = false;
}
$healthy = $healthy && $checks['database'];

// 3. Can we write where we need to?
$checks['storage_writable'] = is_writable(APP_ROOT . '/storage/logs') && is_writable(APP_ROOT . '/storage/cache');
$healthy = $healthy && $checks['storage_writable'];

// 4. Is an update in progress? Not a failure — but monitoring should know.
$maintenance = is_file(APP_ROOT . '/storage/maintenance.flag');
$checks['maintenance'] = $maintenance;

$version = trim((string) @file_get_contents(APP_ROOT . '/VERSION'));

$payload = [
    'status'     => $maintenance ? 'maintenance' : ($healthy ? 'ok' : 'degraded'),
    'version'    => $version !== '' ? $version : null,
    'checks'     => $checks,
    'time'       => gmdate('c'),
    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
];

// Deep check for monitoring, gated by a shared secret so it cannot be used to
// fingerprint the install from outside.
$deepToken = (string) App\Core\Config::get('app.health_token', '');
if (($_GET['deep'] ?? '') === '1' && $deepToken !== '' && hash_equals($deepToken, (string) ($_GET['token'] ?? ''))) {
    try {
        $payload['deep'] = [
            'php'             => PHP_VERSION,
            'database'        => App\Core\DB::scalar('SELECT VERSION()'),
            'pending_jobs'    => App\Models\Job::stats(),
            'migrations'      => count(App\Models\MigrationRecord::appliedFilenames()),
            'last_update'     => App\Models\AppUpdate::latest()['status'] ?? null,
            'free_disk_bytes' => @disk_free_space(APP_ROOT) ?: null,
        ];
    } catch (Throwable $e) {
        $payload['deep'] = ['error' => 'deep check failed'];
    }
}

// 503 during maintenance so a load balancer takes the node out of rotation
// rather than serving a half-updated page.
http_response_code($maintenance ? 503 : ($healthy ? 200 : 503));
if ($maintenance) {
    header('Retry-After: 120');
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
