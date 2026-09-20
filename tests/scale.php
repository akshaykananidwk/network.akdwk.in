#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scale drill (§20.F): a tenant with a thousand devices.
 *
 * Run on demand, not as part of the default suite — it writes real rows and
 * takes long enough to be annoying:
 *
 *   php tests/scale.php --devices=1000 [--url=http://127.0.0.1:8088] [--keep]
 *
 * What it is for is finding the queries that are fine with ten devices and
 * quadratic with a thousand. Every timing below is wall-clock on the machine
 * that ran it, against a development server that handles one request at a
 * time — they are useful as ratios and as a guard against a query that walks
 * the whole table, not as a throughput figure for a real deployment.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->addNamespace('Tests', APP_ROOT . '/tests');
$autoloader->register();
require APP_ROOT . '/app/Core/helpers.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\DeviceService;
use App\Services\IpamService;
use Tests\ScaleReport;

Config::load(APP_ROOT . '/config/config.php');

$options = getopt('', ['devices::', 'url::', 'keep']);
$target = max(1, (int) ($options['devices'] ?? 1000));
$baseUrl = rtrim((string) ($options['url'] ?? Config::get('app.url', 'http://127.0.0.1:8088')), '/');
$keep = isset($options['keep']);

$report = new ScaleReport($target, $baseUrl);
$report->run($keep);

exit($report->exitCode());
