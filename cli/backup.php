#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backups from the command line.
 *
 * Usage:
 *   php cli/backup.php                      take a manual backup
 *   php cli/backup.php --scheduled          take one tagged as scheduled (for cron)
 *   php cli/backup.php --list               list stored backups
 *   php cli/backup.php --verify=7           verify backup #7's checksums
 *   php cli/backup.php --prune              apply the retention policy
 *   php cli/backup.php --restore=7 --yes    restore backup #7 (destructive)
 *   php cli/backup.php --restore=7 --dry-run  report what a restore would do
 */

require __DIR__ . '/_bootstrap.php';

use App\Models\AppBackup;
use App\Models\UpdateSetting;
use App\Updater\BackupManager;
use App\Updater\PathGuard;

$options = cli_options($argv);
$manager = BackupManager::make();

if (isset($options['list'])) {
    cli_heading('Backups');
    $backups = AppBackup::recent(50);

    if ($backups === []) {
        cli_out('  None stored.');
        exit(0);
    }

    printf("  %-5s %-12s %-20s %-10s %-10s %s\n", 'ID', 'TYPE', 'CREATED (UTC)', 'SIZE', 'STATUS', 'CONTENTS');
    foreach ($backups as $backup) {
        printf("  %-5d %-12s %-20s %-10s %-10s %s\n",
            $backup['id'],
            $backup['type'],
            $backup['created_at'],
            format_bytes((int) $backup['size_bytes']),
            $backup['status'],
            (int) $backup['uploads_included'] === 1 ? 'files + db + uploads' : 'files + db (uploads EXCLUDED)'
        );
    }

    cli_out('');
    cli_out(sprintf('  %d backup(s), %s total.', count($backups), format_bytes(AppBackup::totalSize())));
    exit(0);
}

if (isset($options['verify'])) {
    $backupId = (int) $options['verify'];
    cli_heading('Verifying backup #' . $backupId);

    $result = $manager->verify($backupId);
    foreach ($result['checks'] as $check) {
        $check['ok'] ? cli_ok($check['name'] . ': ' . $check['detail'])
                     : cli_fail($check['name'] . ': ' . $check['detail']);
    }

    exit($result['ok'] ? 0 : 1);
}

if (isset($options['prune'])) {
    $keep = (int) UpdateSetting::current()['backup_retention'];
    cli_heading('Pruning backups (keeping the newest ' . $keep . ')');

    $result = $manager->prune($keep);
    cli_ok(sprintf('Removed %d backup(s), freeing %s.', $result['removed'], format_bytes($result['freed_bytes'])));
    exit(0);
}

if (isset($options['restore'])) {
    $backupId = (int) $options['restore'];
    $dryRun = isset($options['dry-run']);

    cli_heading(($dryRun ? 'Dry run: restoring' : 'Restoring') . ' backup #' . $backupId);

    $verification = $manager->verify($backupId);
    foreach ($verification['checks'] as $check) {
        $check['ok'] ? cli_ok($check['name'] . ': ' . $check['detail'])
                     : cli_fail($check['name'] . ': ' . $check['detail']);
    }

    if (!$verification['ok']) {
        cli_out('');
        cli_fail('Refusing to restore: this backup failed verification.');
        exit(1);
    }

    if ($dryRun) {
        $backup = AppBackup::findRow($backupId);
        cli_out('');
        cli_out('  Would restore:');
        cli_out('    files    : ' . ($backup['files_path'] ?? '—'));
        cli_out('    database : ' . ($backup['db_path'] ?? '—'));
        cli_out('');
        cli_out('  Would NOT touch (protected):');
        foreach (UpdateSetting::protectedPaths() as $path) {
            cli_out('    ' . $path);
        }
        cli_out('');
        cli_ok('Dry run complete. Nothing was changed.');
        exit(0);
    }

    if (!isset($options['yes'])) {
        cli_out('');
        fwrite(STDOUT, 'This OVERWRITES the live application and database. Type the backup number to confirm: ');
        if (trim((string) fgets(STDIN)) !== (string) $backupId) {
            cli_out('Cancelled.');
            exit(0);
        }
    }

    try {
        $guard = new PathGuard(APP_ROOT, UpdateSetting::protectedPaths());
        $files = $manager->restoreFiles($backupId, $guard);
        cli_ok(sprintf('%d file(s) restored, %d protected path(s) skipped.', $files['restored'], $files['skipped']));

        $statements = $manager->restoreDatabase($backupId);
        cli_ok(sprintf('Database restored (%d statements).', $statements));

        cli_out('');
        cli_ok('Restore complete.');
        exit(0);
    } catch (Throwable $e) {
        cli_fail($e->getMessage());
        exit(1);
    }
}

// Default: take a backup.
$type = isset($options['scheduled']) ? 'scheduled' : 'manual';
cli_heading('Taking a ' . $type . ' backup');

$lock = cli_lock('backup');
if ($lock === false) {
    cli_fail('Another backup is already running.');
    exit(1);
}

try {
    $backup = $manager->createFull($type);

    cli_ok(sprintf('Backup #%d created (%s).', $backup['id'], format_bytes((int) $backup['size_bytes'])));
    cli_out('    files    : ' . $backup['files_path']);
    cli_out('    database : ' . $backup['db_path']);

    if ((int) $backup['uploads_included'] !== 1) {
        cli_out('');
        cli_warn('uploads/ was NOT included: ' . (string) $backup['uploads_skipped_reason']);
        cli_out(cli_colour('    Back that directory up separately.', 'grey'));
    }

    exit(0);
} catch (Throwable $e) {
    cli_fail($e->getMessage());
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
