<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the web front controller and every CLI entry point.
 *
 * Returns the configured Router. Nothing here emits output, so it is safe to
 * include from a script that will later send its own headers.
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Router;
use App\Core\View;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require APP_ROOT . '/app/Core/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();

require APP_ROOT . '/app/Core/helpers.php';

// config/config.php is written by the installer. Without it we are not
// installed, and the caller decides what that means.
$configFile = APP_ROOT . '/config/config.php';

// The env file lives in config/, which every shipped .htaccess denies as a
// whole directory. A .env in the web root is protected only by a per-file
// rule that nginx ignores entirely and that Apache skips under
// "AllowOverride None" — too fragile for a file holding the database
// password. A root .env is still read if one exists, for compatibility.
$envFile = is_file(APP_ROOT . '/config/.env')
    ? APP_ROOT . '/config/.env'
    : APP_ROOT . '/.env';

Config::load($configFile, $envFile);

if (!defined('APP_INSTALLED')) {
    define('APP_INSTALLED', is_file($configFile) && Config::get('app.key', '') !== '');
}

// The VERSION file is authoritative: the updater writes it, and config may be
// a release behind until the next cache clear.
$versionFile = APP_ROOT . '/VERSION';
if (is_file($versionFile)) {
    $version = trim((string) file_get_contents($versionFile));
    if ($version !== '') {
        Config::set('app.version', $version);
    }
}

date_default_timezone_set('UTC'); // everything is stored UTC; views convert

$isProduction = Config::get('app.env', 'production') === 'production';
$debug = (bool) Config::get('app.debug', false) && !$isProduction;

ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

Logger::configure(APP_ROOT . '/storage/logs', (string) Config::get('logging.level', 'info'));
Lang::configure(APP_ROOT . '/lang', (string) Config::get('app.locale', 'en'));
View::configure(APP_ROOT . '/app/Views');

// PHP's own warnings and notices should reach the same structured log as
// everything else rather than being swallowed or printed.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    Logger::warning('app', 'PHP ' . self_error_label($severity), [
        'message' => $message,
        'file'    => $file,
        'line'    => $line,
    ]);

    return true;
});

if (!function_exists('self_error_label')) {
    function self_error_label(int $severity): string
    {
        return match ($severity) {
            E_WARNING, E_USER_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE   => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecation',
            default => 'error',
        };
    }
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::critical('app', 'Fatal error', [
            'message' => $error['message'],
            'file'    => $error['file'],
            'line'    => $error['line'],
        ]);
    }
});

$router = new Router();
require APP_ROOT . '/app/routes.php';

// route() resolves named routes through this instance.
Config::set('runtime.router', $router);

return $router;
