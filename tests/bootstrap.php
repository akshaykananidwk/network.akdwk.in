<?php

declare(strict_types=1);

/**
 * Test bootstrap. Loads the autoloader and a minimal configuration so pure
 * units (validation, crypto, IPAM maths, manifest checks, path safety) can be
 * exercised without a database.
 *
 * Tests that need MySQL skip themselves when no connection is configured;
 * see tests/run.php for how that is reported.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/app/Core/Autoloader.php';

$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();

require APP_ROOT . '/app/Core/helpers.php';

App\Core\Config::load(APP_ROOT . '/tests/fixtures/test-config.php');
App\Core\Logger::configure(APP_ROOT . '/storage/logs', 'error');
App\Core\Lang::configure(APP_ROOT . '/lang', 'en');
App\Core\View::configure(APP_ROOT . '/app/Views');
