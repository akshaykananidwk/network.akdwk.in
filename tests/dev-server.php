<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in web server, for local development and for the
 * verification suite.
 *
 * It mirrors what the shipped .htaccess does under Apache: serve real files,
 * deny the application directories, and route everything else through
 * index.php. Without it, the built-in server would hand /health.php and the
 * installer to the front controller and they would 404.
 *
 *   php -S 127.0.0.1:8088 -t . tests/dev-server.php
 *
 * This is a development tool. It lives under tests/, which every shipped
 * .htaccess denies, and it refuses to run under a real web server.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('This router is only for PHP\'s built-in development server.');
}

$root = dirname(__DIR__);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// The same directories the .htaccess files deny.
foreach (['app', 'config', 'database', 'storage', 'cli', 'services', 'tests'] as $denied) {
    if (preg_match('#^/' . $denied . '(/|$)#', $path) === 1) {
        http_response_code(403);
        echo 'Forbidden';

        return true;
    }
}

// The same per-file denials as the root .htaccess FilesMatch block. The
// application no longer keeps credentials in the web root, but a stray file
// should still not be served.
if (preg_match('#(^|/)(\.env|\.env\..*|update\.json|VERSION|.*\.sql|.*\.log|install\.lock)$#', $path) === 1) {
    http_response_code(403);
    echo 'Forbidden';

    return true;
}

// Matches the RewriteRule the installer's requirement check probes for.
if ($path === '/__rewrite_probe') {
    require $root . '/install/probe.php';

    return true;
}

$file = $root . $path;

if ($path !== '/' && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        chdir(dirname($file));
        require $file;

        return true;
    }

    // Anything else is a static asset; let the server serve it.
    return false;
}

require $root . '/index.php';

return true;
