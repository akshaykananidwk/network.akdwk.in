#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php              everything that can run here
 *   php tests/run.php --unit       unit tests only (no database, no server)
 *   php tests/run.php --db         database and tenant-isolation tests
 *   php tests/run.php --http       end-to-end tests against a running server
 *
 * The HTTP tests need a server:
 *   php -S 127.0.0.1:8088 -t . tests/dev-server.php
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
use App\Core\Lang;
use App\Core\Logger;
use App\Core\View;
use Tests\BackupTests;
use Tests\DatabaseTests;
use Tests\HttpTests;
use Tests\ProductionDefectTests;
use Tests\TestCase;
use Tests\DocumentationTests;
use Tests\GatewayTests;
use Tests\StaticAnalysisTests;
use Tests\UnitTests;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $argument = substr($argument, 2);
        [$key, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, true];
        $options[$key] = $value;
    }
}

// Prefer the real installation when there is one — the tests are far more
// meaningful against the configuration the product actually ships with.
$configFile = APP_ROOT . '/config/config.php';
$hasInstall = is_file($configFile);

Config::load($hasInstall ? $configFile : APP_ROOT . '/tests/fixtures/test-config.php');
Logger::configure(APP_ROOT . '/storage/logs', 'error');
Lang::configure(APP_ROOT . '/lang', 'en');
View::configure(APP_ROOT . '/app/Views');

$runAll = !isset($options['unit']) && !isset($options['db']) && !isset($options['http']);

echo "\n";
echo "  Verification suite\n";
echo '  PHP ' . PHP_VERSION . ' · ' . ($hasInstall ? 'installed configuration' : 'test fixture configuration') . "\n";

if ($runAll || isset($options['unit'])) {
    UnitTests::run();
    StaticAnalysisTests::run();
    DocumentationTests::run();
    // The ten defects a real deployment found. Every check fails on 1.9.0.
    ProductionDefectTests::run();
}

if ($runAll || isset($options['db'])) {
    if (!$hasInstall) {
        TestCase::group('Database tests');
        TestCase::skip('all database tests', 'no config/config.php — run the installer first');
    } else {
        try {
            App\Core\DB::scalar('SELECT 1');
        } catch (Throwable $e) {
            TestCase::group('Database tests');
            TestCase::skip('all database tests', 'database unreachable: ' . $e->getMessage());
            goto httpPhase;
        }

        try {
            DatabaseTests::run();
            // Its own transaction, and its own fixtures: subnet routing across
            // two customers is a different question from tenant scoping, and
            // mixing them makes a failure in either harder to read.
            GatewayTests::run();
            // Runs after, and outside, the transaction DatabaseTests wraps
            // itself in: dumping and restoring means DDL, which commits.
            BackupTests::run();
        } catch (Throwable $e) {
            // A throw here is a genuine failure, not a missing database —
            // report it as one, with where it happened.
            TestCase::group('Database tests — aborted');
            TestCase::assert(false, 'the suite ran to completion',
                $e::class . ': ' . $e->getMessage()
                . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }

        httpPhase:
    }
}

if ($runAll || isset($options['http'])) {
    $chosen = is_string($options['url'] ?? null);
    $baseUrl = $chosen
        ? rtrim((string) $options['url'], '/')
        : rtrim((string) Config::get('app.url', 'http://127.0.0.1:8088'), '/');

    // Never a machine somebody else is using, unless it was named on the
    // command line.
    //
    // app.url is the panel's real address — on a production installation it is
    // the customer's domain, and this file is in the release. These tests sign
    // in, post an agent binary to the edge endpoint, enrol devices and delete
    // what they made. Pointed at a live panel they are an attack on it, and
    // they were: config/config.local.php, which is how the lab points app.url
    // at its own panel, is removed when the lab finishes, and the next run of
    // this suite read the shipped configuration instead, resolved the real
    // domain, and ran the whole end-to-end phase against the production server
    // over the internet. Every authenticated request was refused, which is the
    // only reason this is a near miss rather than an incident.
    //
    // So the default is loopback or nothing. --url is the way to say otherwise,
    // and saying it is a deliberate act.
    if (!$chosen && !httpTargetIsLocal($baseUrl)) {
        TestCase::group('End-to-end HTTP tests');
        TestCase::skip(
            'all HTTP tests',
            'app.url is ' . $baseUrl . ', which is not this machine. These tests sign in and '
            . 'post to the edge endpoint, so they are never run against an address they were '
            . 'not given. Start a server — php -S 127.0.0.1:8088 -t . tests/dev-server.php — '
            . 'and pass --url=http://127.0.0.1:8088.'
        );
        goto httpDone;
    }

    $reachable = false;
    $parts = parse_url($baseUrl);
    if (is_array($parts) && isset($parts['host'])) {
        $socket = @fsockopen($parts['host'], (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)), $errno, $errstr, 3);
        if ($socket !== false) {
            $reachable = true;
            fclose($socket);
        }
    }

    if (!$reachable) {
        TestCase::group('End-to-end HTTP tests');
        TestCase::skip('all HTTP tests', 'no server at ' . $baseUrl
            . ' — start one with: php -S 127.0.0.1:8088 -t . tests/dev-server.php');
    } else {
        HttpTests::run($baseUrl);
    }

    httpDone:
}

/**
 * Is this address this machine, and nothing else?
 *
 * By the literal loopback addresses and the one name reserved for them.
 * Anything that has to be resolved to be judged is not local enough: a name
 * that resolves to 127.0.0.1 today is a name somebody else controls.
 */
function httpTargetIsLocal(string $baseUrl): bool
{
    $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

    return in_array($host, ['127.0.0.1', '::1', '[::1]', 'localhost'], true);
}

exit(TestCase::summary());
