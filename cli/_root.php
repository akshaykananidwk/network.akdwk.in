<?php

declare(strict_types=1);

/**
 * --root=<panel>: work on that panel rather than the tree this file is in.
 *
 * Required before _bootstrap.php by the scripts deploy/getting-started.sh runs
 * from the edge source it has just fetched — cli/edge-settings.php and
 * cli/setup-link.php. A re-run leaves an installed panel at its own version,
 * so a script, or an option, that is new in this release is not in that
 * panel's tree; the first version called the panel's copies and died with
 * "Could not open input file" on exactly the panels a re-run exists to
 * repair. The panel's own classes, configuration and database are used
 * either way: only the script comes from the installer's release.
 *
 * Only these scripts take it. The rest act on the tree they are in.
 */

foreach (array_slice($argv ?? [], 1) as $argument) {
    // "--root /path" and a bare "--root" are refused, not skipped: skipped,
    // the script quietly worked on the tree it is in — issuing a takeover link
    // for the wrong panel's administrator, or writing another panel's
    // coordinator settings.
    if ((string) $argument === '--root') {
        fwrite(STDERR, "--root needs its value joined to it: --root=/path/to/panel\n");
        exit(1);
    }
    if (!str_starts_with((string) $argument, '--root=')) {
        continue;
    }

    $given = substr((string) $argument, strlen('--root='));
    if ($given === '') {
        fwrite(STDERR, "--root= is empty: --root=/path/to/panel\n");
        exit(1);
    }
    $root = realpath($given);
    if ($root === false || !is_file($root . '/app/bootstrap.php')) {
        fwrite(STDERR, 'Not a panel: ' . $given . "\n");
        exit(1);
    }
    if (defined('APP_ROOT')) {
        fwrite(STDERR, "--root given twice.\n");
        exit(1);
    }
    define('APP_ROOT', $root);
}
