<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Everything that is not a real file on disk arrives here (see .htaccess).
 * Before the application boots we check for the two states that must not
 * depend on it: not yet installed, and mid-update.
 */

define('APP_ROOT', __DIR__);
define('APP_START', microtime(true));

$configFile = APP_ROOT . '/config/config.php';
$installLock = APP_ROOT . '/install/install.lock';

// Not installed yet — send the visitor to the wizard rather than showing them
// a database error.
if (!is_file($configFile) && !is_file($installLock)) {
    if (is_dir(APP_ROOT . '/install')) {
        header('Location: install/', true, 302);
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Not installed</title></head>'
        . '<body style="font-family:system-ui;padding:3rem;max-width:40rem;margin:0 auto">'
        . '<h1>Not installed</h1><p>config/config.php is missing and the installer is not present. '
        . 'Restore the <code>install/</code> directory and reload this page.</p></body></html>';
    exit;
}

$router = require APP_ROOT . '/app/bootstrap.php';

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;

$request = Request::capture();

// Sessions are database-backed, so this needs the application booted — but it
// must happen before the kernel so middleware can read the session.
try {
    Session::start();
} catch (Throwable $e) {
    \App\Core\Logger::critical('app', 'Session start failed', ['error' => $e->getMessage()]);

    http_response_code(503);
    header('Retry-After: 30');
    echo 'The service is temporarily unavailable. Please try again shortly.';
    exit;
}

(new Kernel($router))->handle($request)->send();
