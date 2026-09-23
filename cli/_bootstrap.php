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

// A script may name the panel it works on (cli/edge-settings.php --root=…);
// otherwise it is the tree this file is in.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/*
 * Act as the owner of this panel's files, whoever started the script.
 *
 * The panel runs as the web user and updates itself in place as that user, so
 * every file in this tree has to stay theirs. These scripts run as whoever
 * types them, and the instructions for them — `php cli/update.php --apply`,
 * `php cli/maintenance.php on`, `php cli/backup.php`, a cron line for
 * cli/worker.php — never said which user. On a server, that is root. A root
 * run left root-owned files behind: new files from an update, the rollback
 * journal, the pre-update backup, a maintenance flag, the log. The next
 * update from the panel then could not replace one of them and rolled back
 * over a file it had never changed ("ROLLBACK INCOMPLETE", maintenance left
 * on); a root-taken backup was reported missing and pruned without being
 * deleted; a root maintenance flag locked out the address it had allowed.
 *
 * It is the same rule as git's, and as deploy/getting-started.sh follows for
 * git: operate on a tree as its owner. So a root run becomes the owner before
 * it touches anything, and says so. If that is not possible, it refuses
 * rather than leave the files in a state the panel cannot work with.
 */
require __DIR__ . '/_owner.php';
akconnect_become_tree_owner(APP_ROOT);

// Unreadable is not missing. config/ is 0700 and the web user's, so to any
// other account a live panel's configuration cannot even be seen — and this
// used to call that "not installed" and point at the installer.
if (is_dir(APP_ROOT . '/config') && !is_readable(APP_ROOT . '/config')) {
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid((int) @fileowner(APP_ROOT . '/config'))['name'] ?? 'its owner') : 'its owner';
    fwrite(STDERR, "Cannot read " . APP_ROOT . "/config: permission denied.\n"
        . "The panel is probably installed; its configuration is {$owner}'s. Run this as {$owner}:\n"
        . "    sudo -u {$owner} php " . implode(' ', array_map('escapeshellarg', $_SERVER['argv'] ?? [])) . "\n");
    exit(1);
}

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
    $handle = @fopen($path, 'c');

    // One left by a root run — every run before 1.9.7-dev.21 that was typed
    // as root made one. storage/tmp is this user's, so the file can be
    // replaced even though it cannot be opened; refusing instead would stop
    // the five-minute worker, every five minutes, on exactly the servers
    // that were already affected.
    //
    // Only when nobody holds it. A root run still going — a worker or a backup
    // started before the update — holds its lock on that file, and deleting it
    // and locking a new one let a second copy run beside it. Read-only is
    // enough to ask: the file is 0644, and flock does not need write access.
    // (One only root can read cannot be asked, and is replaced as before; the
    // root runs that left these made them under umask 022, so 0644.)
    if ($handle === false && is_file($path)) {
        $probe = @fopen($path, 'r');
        if ($probe !== false) {
            $free = flock($probe, LOCK_EX | LOCK_NB);
            if (!$free) {
                fclose($probe);

                return false;
            }
            flock($probe, LOCK_UN);
            fclose($probe);
        }
        if (@unlink($path)) {
            $handle = @fopen($path, 'c');
        }
    }

    if ($handle === false) {
        // This returned null, and every caller tested only `=== false` — so a
        // lock file it could not open, typically one a root run had left
        // behind, meant carrying on with no lock at all. Two workers could run
        // the same backup, or the same migration, and nothing said so. It is
        // not a lock that was taken, and it is not one that is held: it is a
        // fault, and it stops here.
        $error = error_get_last();
        fwrite(STDERR, 'Cannot open the lock file ' . $path . ': '
            . (string) ($error['message'] ?? 'unknown error') . "\n"
            . (is_file($path) && function_exists('posix_getpwuid')
                ? 'It belongs to ' . (posix_getpwuid((int) fileowner($path))['name'] ?? '?')
                    . '. Remove it, and run this as the owner of the panel\'s files.' . "\n"
                : ''));
        exit(1);
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return false;
    }

    return $handle;
}

return $router;
