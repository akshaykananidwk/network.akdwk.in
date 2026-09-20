<?php

declare(strict_types=1);

/**
 * Shared CLI bootstrap.
 *
 * Every cli/ script starts here. It refuses to run over HTTP — these scripts
 * take no authentication and would be a remote-command hole if they were
 * reachable from the web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));

if (!is_file(APP_ROOT . '/config/config.php')) {
    fwrite(STDERR, "Not installed: config/config.php is missing.\nRun the web installer, or cli/install.php with an answers file.\n");
    exit(1);
}

$router = require APP_ROOT . '/app/bootstrap.php';

// Long-running maintenance work should not be cut off mid-way.
@set_time_limit(0);
@ini_set('memory_limit', '512M');

/** Coloured output, but only when the terminal will render it. */
function cli_colour(string $text, string $colour): string
{
    static $supportsColour = null;
    if ($supportsColour === null) {
        $supportsColour = function_exists('posix_isatty') && @posix_isatty(STDOUT) && getenv('NO_COLOR') === false;
    }
    if (!$supportsColour) {
        return $text;
    }

    $codes = ['red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'blue' => '0;34', 'grey' => '0;90', 'bold' => '1'];

    return "\033[" . ($codes[$colour] ?? '0') . 'm' . $text . "\033[0m";
}

function cli_out(string $message = ''): void
{
    fwrite(STDOUT, $message . "\n");
}

function cli_ok(string $message): void
{
    cli_out(cli_colour('  ✓ ', 'green') . $message);
}

function cli_warn(string $message): void
{
    cli_out(cli_colour('  ! ', 'yellow') . $message);
}

function cli_fail(string $message): void
{
    fwrite(STDERR, cli_colour('  ✗ ', 'red') . $message . "\n");
}

function cli_heading(string $message): void
{
    cli_out('');
    cli_out(cli_colour($message, 'bold'));
    cli_out(cli_colour(str_repeat('─', min(72, strlen($message))), 'grey'));
}

/**
 * Parse --key=value and --flag arguments.
 *
 * @param list<string> $argv
 * @return array<string,string|bool>
 */
function cli_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $argument = substr($argument, 2);
        if (str_contains($argument, '=')) {
            [$key, $value] = explode('=', $argument, 2);
            $options[$key] = $value;
        } else {
            $options[$argument] = true;
        }
    }

    return $options;
}

/** Prevent two copies of the same maintenance task running at once. */
function cli_lock(string $name): mixed
{
    $path = APP_ROOT . '/storage/tmp/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.lock';
    $handle = fopen($path, 'c');
    if ($handle === false) {
        return null;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return false;
    }

    return $handle;
}

return $router;
