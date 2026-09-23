#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Print a one-time link that lets the administrator set their own password.
 *
 * Usage:
 *   php cli/setup-link.php                       the super administrator
 *   php cli/setup-link.php --email=me@example.com
 *   php cli/setup-link.php --minutes=120
 *
 * Why this exists
 * ---------------
 * An unattended install has to put *some* password on the administrator
 * account, and every way of handing that password over is worse than this
 * one. Printed on the terminal it ends up in scrollback and in whatever
 * captured the installer's output; mailed, it needs a working mail server on
 * a machine that has just been built; written to a file, it stays there.
 *
 * So the installer sets a long random password nobody ever learns, and prints
 * this instead: a single-use token that expires, over the same
 * /reset-password route the panel already uses. Issuing a new one invalidates
 * the previous one, so running this again after a link leaks is a complete
 * remedy.
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\Config;
use App\Core\DB;
use App\Models\PasswordReset;

$options = cli_options($argv);

$minutes = (int) ($options['minutes'] ?? 60);
if ($minutes < 5 || $minutes > 60 * 24 * 7) {
    fwrite(STDERR, "--minutes must be between 5 and 10080 (a week).\n");
    exit(1);
}

$email = strtolower(trim((string) ($options['email'] ?? '')));

// The oldest active super administrator when none is named: on a fresh
// install there is exactly one, and on an older panel it is the account the
// installer made rather than whichever was added most recently.
$user = $email !== ''
    ? DB::selectOne(
        'SELECT id, email, name FROM ' . DB::table('users')
        . " WHERE email = :e AND deleted_at IS NULL AND status = 'active' LIMIT 1",
        ['e' => $email]
    )
    : DB::selectOne(
        'SELECT id, email, name FROM ' . DB::table('users')
        . " WHERE role = 'super_admin' AND deleted_at IS NULL AND status = 'active'"
        . ' ORDER BY id ASC LIMIT 1'
    );

if ($user === null) {
    fwrite(STDERR, $email !== ''
        ? "No active user with that address.\n"
        : "No active super administrator on this panel.\n");
    exit(1);
}

$base = rtrim((string) Config::get('app.url', ''), '/');
if ($base === '') {
    fwrite(STDERR, "app.url is not set in config/config.php, so the link cannot be built.\n");
    exit(1);
}

// Issued from the loopback address: nobody asked for this over the network,
// and the stored address is only ever read back as evidence of who did.
$token = PasswordReset::issue((int) $user['id'], '127.0.0.1', $minutes);

$link = $base . '/reset-password?token=' . rawurlencode($token);

if (isset($options['quiet'])) {
    cli_out($link);
    exit(0);
}

cli_out('');
cli_out('  Set the administrator password here — the link works once:');
cli_out('');
cli_out('    ' . $link);
cli_out('');
cli_out('    account : ' . (string) $user['email']);
cli_out('    expires : in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's'));
cli_out('');
cli_out('  Issuing another link cancels this one.');
cli_out('');

exit(0);
