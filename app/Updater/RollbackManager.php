<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Core\UpdateException;
use App\Models\AppBackup;
use App\Models\AppUpdate;
use App\Models\Setting;
use App\Models\UpdateSetting;
use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * Undoes an update (§9.6).
 *
 * Invoked automatically when a step at or after APPLY fails, and manually from
 * the History screen.
 *
 * The ordering is deliberate: files first, so the running code matches the
 * schema we are about to restore; then reversible migrations; then the
 * database dump, which is the authoritative restore for anything a down()
 * could not undo.
 *
 * The one rule that overrides everything else: if the rollback cannot be
 * completed, maintenance mode stays ON and the operator is handed exact
 * recovery commands. A half-updated site serving live traffic is the worst
 * possible outcome, worse than an outage.
 */
final class RollbackManager
{
    public function __construct(
        private readonly string $appRoot,
        private readonly PathGuard $guard,
        private readonly MaintenanceMode $maintenance,
    ) {
    }

    /**
     * @return array{ok:bool,steps:list<string>,failures:list<string>,health:array<string,mixed>,maintenance:bool,recovery:array<string,mixed>|null}
     */
    public function rollback(int $updateId, string $reason = 'Manual rollback'): array
    {
        $update = AppUpdate::findRow($updateId);
        if ($update === null) {
            throw new UpdateException('Update #' . $updateId . ' not found.');
        }

        $log = UpdateLog::forUpdate($updateId);
        $log->step('ROLLBACK', $reason);

        $this->maintenance->enable('Rolling back update #' . $updateId, [], 600);

        $steps = [];
        $failures = [];

        $this->restoreFiles($update, $log, $steps, $failures);
        $this->reverseMigrations($update, $log, $steps, $failures);
        $this->restoreDatabase($update, $log, $steps, $failures);
        $this->restoreVersionMarker($update, $failures);

        // A rollback that leaves the panel broken is not a rollback.
        $health = HealthChecker::make()->run();
        if (!$health['ok']) {
            $failures[] = sprintf('Post-rollback health check reported %d critical failure(s).', $health['critical_failures']);
        }

        $ok = $failures === [];
        $backupId = (int) ($update['backup_id'] ?? 0);

        if ($ok) {
            $this->maintenance->disable();
            AppUpdate::markRolledBack($updateId, $reason);
            // The undo list has been consumed: the tree is back at its
            // pre-APPLY state, so replaying it again could only confuse a
            // later operator. It is kept on failure, where a second attempt
            // still needs it.
            $this->discardJournal($update);
            $log->info('Rollback complete. The previous version is live again.');
        } else {
            AppUpdate::fail($updateId, $reason . ' — rollback incomplete: ' . implode(' ', $failures));
            $log->error('ROLLBACK INCOMPLETE. Maintenance mode has been left ON deliberately.');
            $log->error('Recover manually from backup #' . $backupId . ' using the commands on the update detail page.');
        }

        $result = [
            'ok'          => $ok,
            'steps'       => $steps,
            'failures'    => $failures,
            'health'      => $health,
            'maintenance' => $this->maintenance->isEnabled(),
            'recovery'    => $ok ? null : $this->recoveryInstructions($backupId),
        ];

        AuditService::log(
            $ok ? 'update.rollback' : 'update.rollback_failed',
            'app_update',
            $updateId,
            null,
            ['reason' => $reason, 'steps' => $steps, 'failures' => $failures],
            $ok ? 'success' : 'failure'
        );

        $this->notify($updateId, $ok, $reason, $failures);

        return $result;
    }

    // ------------------------------------------------------------- phases

    /**
     * @param array<string,mixed> $update
     * @param list<string> $steps
     * @param list<string> $failures
     */
    private function restoreFiles(array $update, UpdateLog $log, array &$steps, array &$failures): void
    {
        try {
            $journalRelative = (string) ($update['journal_path'] ?? '');
            $journalPath = $journalRelative !== '' ? $this->appRoot . '/' . ltrim($journalRelative, '/') : '';

            if ($journalPath === '' || !is_file($journalPath)) {
                $steps[] = 'Files: nothing to undo (the update had not reached APPLY).';
                $log->info(end($steps));

                return;
            }

            $journal = new RollbackJournal(
                $journalPath,
                sprintf('%s/storage/updates/journal-%d-files', $this->appRoot, (int) $update['id'])
            );

            $replay = $journal->replay($this->guard);
            $steps[] = sprintf('Files: %d restored, %d removed.', $replay['restored'], $replay['deleted']);
            $log->info(end($steps));

            if ($replay['failed'] !== []) {
                $failures[] = sprintf('%d file(s) could not be restored.', count($replay['failed']));
                foreach (array_slice($replay['failed'], 0, 10) as $failure) {
                    $log->error('  ✗ ' . $failure['path'] . ': ' . $failure['error']);
                }
            }
        } catch (\Throwable $e) {
            $failures[] = 'File rollback failed: ' . $e->getMessage();
            $log->error(end($failures));
        }
    }

    /** @param array<string,mixed> $update */
    private function discardJournal(array $update): void
    {
        $relative = (string) ($update['journal_path'] ?? '');
        if ($relative === '') {
            return;
        }

        (new RollbackJournal(
            $this->appRoot . '/' . ltrim($relative, '/'),
            sprintf('%s/storage/updates/journal-%d-files', $this->appRoot, (int) $update['id'])
        ))->discard();
    }

    /**
     * @param array<string,mixed> $update
     * @param list<string> $steps
     * @param list<string> $failures
     */
    private function reverseMigrations(array $update, UpdateLog $log, array &$steps, array &$failures): void
    {
        $applied = (array) ($update['applied_migrations_json'] ?? []);
        if ($applied === []) {
            return;
        }

        try {
            $runner = new MigrationRunner($this->appRoot . '/database/migrations', DB::prefix());
            $reversal = $runner->rollback(array_map('strval', $applied));

            $steps[] = sprintf(
                'Migrations: %d reversed, %d not reversible (covered by the database restore).',
                count($reversal['reversed']),
                count($reversal['irreversible'])
            );
            $log->info(end($steps));
        } catch (\Throwable $e) {
            $failures[] = 'Migration rollback failed: ' . $e->getMessage();
            $log->error(end($failures));
        }
    }

    /**
     * @param array<string,mixed> $update
     * @param list<string> $steps
     * @param list<string> $failures
     */
    private function restoreDatabase(array $update, UpdateLog $log, array &$steps, array &$failures): void
    {
        $backupId = (int) ($update['backup_id'] ?? 0);
        if ($backupId === 0) {
            return;
        }

        // Restoring the dump is disruptive; it is only warranted when the
        // schema or data actually changed under us.
        if ((array) ($update['applied_migrations_json'] ?? []) === []) {
            $steps[] = 'Database restore not needed — no migrations ran.';
            $log->info(end($steps));

            return;
        }

        try {
            $statements = BackupManager::make()->restoreDatabase($backupId);
            $steps[] = sprintf('Database restored from backup #%d (%d statements).', $backupId, $statements);
            $log->info(end($steps));
        } catch (\Throwable $e) {
            $failures[] = 'Database restore failed: ' . $e->getMessage();
            $log->error(end($failures));
        }
    }

    /**
     * @param array<string,mixed> $update
     * @param list<string> $failures
     */
    private function restoreVersionMarker(array $update, array &$failures): void
    {
        try {
            $fromVersion = (string) ($update['from_version'] ?? '');
            if ($fromVersion !== '') {
                // The journal usually restored VERSION already, byte for byte.
                // Rewriting it here would reconstruct the file rather than
                // restore it — a trailing newline the original did not have is
                // enough to make the next update see it as locally modified.
                // So this is a fallback, for a rollback that never reached
                // APPLY and therefore has no journal entry for it.
                $marker = $this->appRoot . '/VERSION';
                if (trim((string) @file_get_contents($marker)) !== $fromVersion) {
                    file_put_contents($marker, $fromVersion . "\n");
                }
                Config::set('app.version', $fromVersion);
            }
            if (!empty($update['from_commit'])) {
                UpdateSetting::setCurrentCommit((string) $update['from_commit']);
            }

            if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
                @opcache_reset();
            }
            Setting::flushCache();
        } catch (\Throwable $e) {
            $failures[] = 'Version marker not restored: ' . $e->getMessage();
        }
    }

    // ------------------------------------------------------------ reporting

    /** @return array<string,mixed> */
    private function recoveryInstructions(int $backupId): array
    {
        $backup = $backupId > 0 ? AppBackup::findRow($backupId) : null;

        $filesPath = $backup['files_path'] ?? 'storage/backups/<timestamp>/files.tar.gz';
        $dbPath = $backup['db_path'] ?? 'storage/backups/<timestamp>/db.sql.gz';

        $note = 'config/config.php, .env and uploads/ were never touched by the update and do not need restoring.';

        // Backups taken before 1.0.2 could be written by mysqldump even on a
        // schema with stored generated columns, and those cannot be replayed
        // at all — the server rejects the explicit value with error 1906.
        // Saying so here is the difference between an operator recovering and
        // an operator discovering it at the worst possible moment.
        if (($backup['db_method'] ?? null) === 'mysqldump' && $this->schemaHasGeneratedColumns()) {
            $note .= ' Warning: this dump was written by mysqldump while the schema has generated columns,'
                . ' so it may fail to restore with error 1906. Use a backup taken by version 1.0.2 or later'
                . ' if one is available.';
        }

        return [
            'backup_id'    => $backupId,
            'files_path'   => $filesPath,
            'db_path'      => $dbPath,
            'db_method'    => $backup['db_method'] ?? null,
            'app_root'     => $this->appRoot,
            'manual_steps' => [
                'cd ' . $this->appRoot,
                'tar -xzf ' . $filesPath . '   # restores the application files',
                'gunzip -c ' . $dbPath . ' | mysql -u <db_user> -p <db_name>',
                'rm storage/maintenance.flag   # brings the site back online',
            ],
            'note' => $note,
        ];
    }

    private function schemaHasGeneratedColumns(): bool
    {
        try {
            return (new SqlDumpWriter((string) Config::get('db.name')))->generatedColumns() !== [];
        } catch (\Throwable) {
            // Never let a warning lookup be the reason recovery advice is
            // withheld from someone who needs it right now.
            return false;
        }
    }

    /** @param list<string> $failures */
    private function notify(int $updateId, bool $ok, string $reason, array $failures): void
    {
        NotificationService::notifySuperAdmins(
            $ok ? 'warning' : 'critical',
            $ok ? 'Update rolled back' : 'ROLLBACK FAILED — manual recovery needed',
            $ok
                ? sprintf("Update #%d was rolled back.\nReason: %s\nThe previous version is live again.", $updateId, $reason)
                : sprintf(
                    "Update #%d could not be rolled back cleanly.\n\n%s\n\n"
                    . "Maintenance mode has been left ON deliberately. Open System → Updates → History "
                    . "for the exact recovery commands.",
                    $updateId,
                    implode("\n", $failures)
                ),
            'admin/updates/' . $updateId,
            'update'
        );
    }
}
