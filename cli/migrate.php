#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run pending database migrations.
 *
 * Usage:
 *   php cli/migrate.php              apply everything pending
 *   php cli/migrate.php --status     list applied and pending, and any drift
 *   php cli/migrate.php --dry-run    show what would run, change nothing
 *   php cli/migrate.php --rollback=2026_01_01_120000_example.php
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\DB;
use App\Models\MigrationRecord;
use App\Updater\MigrationRunner;

$options = cli_options($argv);
$runner = new MigrationRunner(APP_ROOT . '/database/migrations', DB::prefix());

$applied = MigrationRecord::appliedFilenames();
$pending = $runner->pending();
$drift = $runner->driftedMigrations();

if (isset($options['status'])) {
    cli_heading('Migration status');
    cli_out(sprintf('  %d applied, %d pending', count($applied), count($pending)));

    if ($applied !== []) {
        cli_out('');
        cli_out(cli_colour('  Applied:', 'grey'));
        foreach ($applied as $filename) {
            cli_out('    ' . cli_colour('✓', 'green') . ' ' . $filename);
        }
    }

    if ($pending !== []) {
        cli_out('');
        cli_out(cli_colour('  Pending:', 'grey'));
        foreach ($pending as $filename) {
            cli_out('    ' . cli_colour('·', 'yellow') . ' ' . $filename);
        }
    }

    if ($drift !== []) {
        cli_out('');
        cli_warn(sprintf('%d applied migration file(s) have changed since they ran:', count($drift)));
        foreach ($drift as $entry) {
            cli_out('    ' . $entry['filename']);
        }
        cli_out(cli_colour('    Editing an applied migration silently diverges environments.', 'grey'));
        cli_out(cli_colour('    Add a new migration instead of changing an old one.', 'grey'));
    }

    exit(0);
}

if (isset($options['rollback'])) {
    $filename = (string) $options['rollback'];
    cli_heading('Reversing ' . $filename);

    $result = $runner->rollback([$filename]);

    foreach ($result['reversed'] as $file) {
        cli_ok('Reversed ' . $file);
    }
    foreach ($result['irreversible'] as $file) {
        cli_warn($file . ' could not be reversed (only .php migrations with a down() can be).');
    }

    exit($result['reversed'] === [] ? 1 : 0);
}

cli_heading('Pending migrations');

if ($pending === []) {
    cli_ok('Nothing to do — the database is up to date.');
    exit(0);
}

foreach ($pending as $filename) {
    cli_out('  · ' . $filename);
}

if (isset($options['dry-run'])) {
    cli_out('');
    cli_out('Dry run: nothing was changed.');
    exit(0);
}

$lock = cli_lock('migrate');
if ($lock === false) {
    cli_fail('Another migration run is already in progress.');
    exit(1);
}

cli_out('');

try {
    $result = $runner->run();

    foreach ($result['applied'] as $filename) {
        cli_ok($filename);
    }

    cli_out('');
    cli_ok(sprintf('Applied %d migration(s) in batch %d (%dms).',
        count($result['applied']), $result['batch'], $result['elapsed_ms']));
    exit(0);
} catch (Throwable $e) {
    cli_out('');
    cli_fail($e->getMessage());
    cli_out('');
    cli_out('The database may be part-migrated. Restore from a backup before retrying:');
    cli_out('  php cli/backup.php --list');
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
