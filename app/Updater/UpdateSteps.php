<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Session;
use App\Core\UpdateException;
use App\Models\AppUpdate;
use App\Models\UpdateSetting;
use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * The eleven units of work in the update pipeline (§9.4).
 *
 * Each method does exactly one step and returns a one-line summary for the
 * progress UI. They are deliberately separate from UpdateManager, which owns
 * sequencing, persistence and failure handling: the orchestration rules and
 * the work itself change for different reasons and are reviewed differently.
 *
 * Every method is safe to call only once per run, in order. UpdateManager
 * enforces that; nothing here assumes it.
 */
final class UpdateSteps
{
    public function __construct(
        private readonly string $appRoot,
        private readonly PathGuard $guard,
        private readonly MaintenanceMode $maintenance,
    ) {
    }

    /**
     * Dispatch by step name.
     *
     * @param array<string,mixed> $update
     * @throws UpdateException on an unknown step
     */
    public function execute(string $step, array $update, UpdateLog $log): string
    {
        return match ($step) {
            'PRECHECK'     => $this->stepPrecheck($update, $log),
            'MAINTENANCE'  => $this->stepMaintenance($update, $log),
            'BACKUP_FILES' => $this->stepBackupFiles($update, $log),
            'BACKUP_DB'    => $this->stepBackupDatabase($update, $log),
            'DOWNLOAD'     => $this->stepDownload($update, $log),
            'STAGE'        => $this->stepStage($update, $log),
            'MIGRATE'      => $this->stepMigrate($update, $log),
            'APPLY'        => $this->stepApply($update, $log),
            'POST'         => $this->stepPost($update, $log),
            'HEALTH'       => $this->stepHealth($update, $log),
            'FINALISE'     => $this->stepFinalise($update, $log),
            default        => throw new UpdateException('Unknown update step: ' . $step),
        };
    }

    /** @param array<string,mixed> $update */
    public function stepPrecheck(array $update, UpdateLog $log): string
    {
        $log->step('PRECHECK', 'Verifying the server can complete this update');

        $problems = [];
        $warnings = [];

        // 1. Disk space — 3x the estimated size covers the archive, the staged
        //    copy and the backup existing at the same time.
        $multiplier = (int) Config::get('update.min_free_disk_multiplier', 3);
        $free = @disk_free_space($this->appRoot);
        $estimated = max(50 * 1024 * 1024, ((int) ($update['manifest_json']['estimated_kb'] ?? 0)) * 1024);
        $needed = $estimated * $multiplier;

        if ($free !== false && $free < $needed) {
            $problems[] = sprintf(
                'Not enough free disk space: %s available, about %s needed.',
                UpdateEnv::humanBytes((int) $free),
                UpdateEnv::humanBytes($needed)
            );
        }

        // 2. PHP and MySQL versions from the manifest.
        $manifest = UpdateEnv::manifestFor($update);
        $requirements = $manifest->checkRequirements(UpdateEnv::mysqlVersion());
        foreach ($requirements['failures'] as $failure) {
            $problems[] = $failure;
        }

        // 3. Required extensions.
        foreach (['pdo_mysql', 'openssl', 'curl', 'zip', 'mbstring', 'json', 'fileinfo'] as $extension) {
            if (!extension_loaded($extension)) {
                $problems[] = 'Missing PHP extension: ' . $extension;
            }
        }

        // 4. Writability of everything we will touch.
        foreach (['app', 'assets', 'database', 'cli', 'storage', 'storage/updates', 'storage/backups'] as $directory) {
            $path = $this->appRoot . '/' . $directory;
            if (is_dir($path) && !is_writable($path)) {
                $problems[] = 'Directory is not writable: ' . $directory;
            }
        }
        if (!is_writable($this->appRoot)) {
            $problems[] = 'The application root is not writable.';
        }

        // 5. Locally modified files would be silently overwritten; say so.
        $drifted = (new MigrationRunner($this->appRoot . '/database/migrations', DB::prefix()))->driftedMigrations();
        if ($drifted !== []) {
            $warnings[] = sprintf(
                '%d applied migration file(s) differ from what was recorded: %s',
                count($drifted),
                implode(', ', array_column($drifted, 'filename'))
            );
        }

        $modified = $this->detectLocalModifications();
        if ($modified !== []) {
            $warnings[] = sprintf(
                '%d local file(s) differ from the last applied release and will be overwritten: %s%s',
                count($modified),
                implode(', ', array_slice($modified, 0, 5)),
                count($modified) > 5 ? ' …' : ''
            );
        }

        foreach ($warnings as $warning) {
            $log->warn($warning);
        }

        if ($problems !== []) {
            throw new UpdateException('Pre-flight checks failed: ' . implode(' ', $problems), 'PRECHECK');
        }

        $message = sprintf(
            'Pre-flight passed. %s free, PHP %s, %s.',
            $free !== false ? UpdateEnv::humanBytes((int) $free) : 'unknown',
            PHP_VERSION,
            UpdateEnv::mysqlVersion() ?? 'database version unknown'
        );

        $log->info($message);
        if ($warnings !== []) {
            $log->warn('Proceeding with ' . count($warnings) . ' warning(s).');
        }

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepMaintenance(array $update, UpdateLog $log): string
    {
        $log->step('MAINTENANCE', 'Putting the panel into maintenance mode');

        $settings = UpdateSetting::current();
        $allowed = (array) ($settings['maintenance_allowlist_json'] ?? []);

        $request = \App\Core\Request::current();
        if ($request !== null) {
            // The operator watching the progress bar must not lock themselves out.
            $allowed[] = $request->ip();
        }

        $token = $this->maintenance->enable(
            sprintf('Updating to %s', $update['to_version']),
            array_values(array_unique(array_filter($allowed))),
            600
        );

        \App\Core\Session::set('maintenance_bypass', $token);
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            setcookie(MaintenanceMode::cookieName(), $token, [
                'expires'  => time() + 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $request?->isSecure() ?? false,
            ]);
        }

        $message = 'Maintenance mode on. Visitors see a 503 with a retry hint; your session keeps access.';
        $log->info($message);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepBackupFiles(array $update, UpdateLog $log): string
    {
        $log->step('BACKUP_FILES', 'Archiving the current application files');

        // The backup row is created here and completed by BACKUP_DB, so the
        // two steps share one artefact directory and one retention entry.
        $backup = BackupManager::make()->createFull('pre_update');

        AppUpdate::update((int) $update['id'], ['backup_id' => (int) $backup['id']]);

        $message = sprintf(
            'Backup #%d written (%s)%s.',
            $backup['id'],
            UpdateEnv::humanBytes((int) $backup['size_bytes']),
            (int) $backup['uploads_included'] === 1 ? '' : ' — uploads/ excluded, see the note on this run'
        );

        if ((int) $backup['uploads_included'] !== 1) {
            $log->warn('uploads/ was NOT included in this backup: ' . (string) $backup['uploads_skipped_reason']);
        }

        $log->info($message);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepBackupDatabase(array $update, UpdateLog $log): string
    {
        // createFull() already dumped the database in the previous step; this
        // step verifies it rather than dumping twice. Verification is the part
        // that actually protects the rollback path.
        $log->step('BACKUP_DB', 'Verifying the database backup');

        $backupId = (int) ($update['backup_id'] ?? 0);
        if ($backupId === 0) {
            throw new UpdateException('No backup was recorded; refusing to continue.', 'BACKUP_DB');
        }

        $verification = BackupManager::make()->verify($backupId);
        foreach ($verification['checks'] as $check) {
            $check['ok'] ? $log->info('  ✓ ' . $check['name'] . ': ' . $check['detail'])
                         : $log->error('  ✗ ' . $check['name'] . ': ' . $check['detail']);
        }

        if (!$verification['ok']) {
            throw new UpdateException('The pre-update backup failed verification; refusing to continue.', 'BACKUP_DB');
        }

        $message = 'Backup verified — files and database both match their checksums.';
        $log->info($message);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepDownload(array $update, UpdateLog $log): string
    {
        $log->step('DOWNLOAD', 'Fetching the release archive from GitHub');

        $settings = UpdateSetting::current();
        $client = new GithubClient(
            (string) $settings['repo_owner'],
            (string) $settings['repo_name'],
            UpdateSetting::token()
        );

        $sha = (string) $update['to_commit'];
        $destination = sprintf('%s/storage/updates/tmp/%s.zip', $this->appRoot, $sha);

        $bytes = $client->downloadZipball($sha, $destination);

        $message = sprintf('Archive downloaded: %s.', UpdateEnv::humanBytes($bytes));
        $log->info($message, ['sha' => substr($sha, 0, 7)]);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepStage(array $update, UpdateLog $log): string
    {
        $log->step('STAGE', 'Extracting and verifying the new files');

        $sha = (string) $update['to_commit'];
        $archive = sprintf('%s/storage/updates/tmp/%s.zip', $this->appRoot, $sha);
        $stage = sprintf('%s/storage/updates/stage/%s', $this->appRoot, $sha);

        if (is_dir($stage)) {
            UpdateEnv::removeDirectory($stage);
        }

        $extractor = new ArchiveExtractor($this->guard);
        $extraction = $extractor->extract($archive, $stage);
        $log->info(sprintf('Extracted %d files (%s).', $extraction['files'], UpdateEnv::humanBytes($extraction['bytes'])));

        // The staged manifest is authoritative from here: it is the one that
        // shipped with these exact files.
        $manifestPath = $stage . '/update.json';
        $manifest = is_file($manifestPath)
            ? Manifest::fromJson((string) file_get_contents($manifestPath))
            : Manifest::fallback((string) $update['to_version']);

        if ((int) UpdateSetting::current()['verify_signature'] === 1 && !$manifest->verifySignature()) {
            throw new UpdateException('The staged manifest signature is invalid. Update refused.', 'STAGE');
        }

        $checksums = $manifest->checksums();
        if ($checksums !== []) {
            $verification = $extractor->verifyChecksums($stage, $checksums);
            if (!$verification['ok']) {
                throw new UpdateException(sprintf(
                    'Checksum verification failed: %d mismatch(es), %d missing file(s).',
                    count($verification['mismatches']),
                    count($verification['missing'])
                ), 'STAGE');
            }
            $log->info(sprintf('Verified %d manifest checksums.', $verification['checked']));
        }

        $syntax = $extractor->verifyPhpSyntax($stage);
        if (!$syntax['ok']) {
            $sample = array_slice($syntax['errors'], 0, 3);
            throw new UpdateException(sprintf(
                '%d staged PHP file(s) do not parse — refusing to apply a broken release. First: %s (%s)',
                count($syntax['errors']),
                $sample[0]['file'] ?? '?',
                $sample[0]['error'] ?? '?'
            ), 'STAGE');
        }

        AppUpdate::update((int) $update['id'], [
            'stage_path'    => 'storage/updates/stage/' . $sha,
            'manifest_json' => array_merge((array) $update['manifest_json'], ['staged' => $manifest->toArray()]),
        ]);

        $message = sprintf('Staged and verified: %d files, %d PHP files parse cleanly.', $extraction['files'], $syntax['checked']);
        $log->info($message);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepMigrate(array $update, UpdateLog $log): string
    {
        $log->step('MIGRATE', 'Applying database migrations');

        $sha = (string) $update['to_commit'];
        $stage = sprintf('%s/storage/updates/stage/%s', $this->appRoot, $sha);

        // Migrations come from the staged tree: the new files are not live yet,
        // but their migrations must run before the code that needs them.
        $stagedMigrations = $stage . '/database/migrations';
        if (!is_dir($stagedMigrations)) {
            $log->info('No migrations directory in this release; nothing to apply.');

            return 'No migrations to apply.';
        }

        $manifest = UpdateEnv::manifestFor($update, true);
        $named = $manifest->migrations();

        // Copy the staged migration files into place first, so the ledger and
        // the files on disk agree even if a later step fails.
        $liveMigrations = $this->appRoot . '/database/migrations';
        if (!is_dir($liveMigrations)) {
            @mkdir($liveMigrations, 0755, true);
        }

        // Remembered, not just counted: a rollback has to delete the files it
        // introduced, or migrate.php reports them as pending and invites an
        // operator to re-apply a migration from the version they rolled back
        // from.
        $copied = [];
        foreach (scandir($stagedMigrations) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $stagedMigrations . '/' . $entry;
            $target = $liveMigrations . '/' . basename($entry);
            if (is_file($source) && !is_file($target) && copy($source, $target)) {
                $copied[] = basename($entry);
            }
        }

        $runner = new MigrationRunner($liveMigrations, DB::prefix());

        try {
            $result = $runner->run($named === [] ? null : $named);
        } finally {
            // After the runner, not before: the column this list goes into was
            // itself added by a migration, so writing to it first would fail on
            // the very update that introduces it. In a finally block because a
            // failed migration still needs rolling back, and the rollback needs
            // to know which files to remove.
            $this->recordCopied($update, $copied, $log);
        }

        if ($result['applied'] === []) {
            $message = 'No pending migrations.';
            $log->info($message);

            return $message;
        }

        AppUpdate::recordAppliedMigrations((int) $update['id'], $result['applied']);

        foreach ($result['applied'] as $filename) {
            $log->info('  ✓ ' . $filename);
        }

        $message = sprintf(
            'Applied %d migration(s) in batch %d (%dms). %d new migration file(s) copied.',
            count($result['applied']),
            $result['batch'],
            $result['elapsed_ms'],
            count($copied)
        );
        $log->info($message);

        return $message;
    }

    /**
     * @param array<string,mixed> $update
     * @param list<string>        $copied
     */
    private function recordCopied(array $update, array $copied, UpdateLog $log): void
    {
        if ($copied === []) {
            return;
        }

        try {
            AppUpdate::recordCopiedMigrations((int) $update['id'], $copied);
        } catch (\Throwable $e) {
            // Worth saying, never worth failing an otherwise-good update over:
            // the cost is a rollback that leaves these files behind, which is
            // untidy rather than dangerous.
            $log->info('Could not record the copied migration files: ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $update */
    public function stepApply(array $update, UpdateLog $log): string
    {
        $log->step('APPLY', 'Copying the new files into place');

        $sha = (string) $update['to_commit'];
        $stage = sprintf('%s/storage/updates/stage/%s', $this->appRoot, $sha);

        if (!is_dir($stage)) {
            throw new UpdateException('Staged files are missing; re-run the update.', 'APPLY');
        }

        $journalPath = sprintf('%s/storage/updates/journal-%d.jsonl', $this->appRoot, (int) $update['id']);
        $journalBackups = sprintf('%s/storage/updates/journal-%d-files', $this->appRoot, (int) $update['id']);

        $journal = new RollbackJournal($journalPath, $journalBackups);
        $journal->open();

        AppUpdate::update((int) $update['id'], ['journal_path' => 'storage/updates/journal-' . (int) $update['id'] . '.jsonl']);

        $copied = 0;
        $skippedProtected = 0;
        $created = 0;

        try {
            $stageLength = strlen($stage) + 1;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), $stageLength));

                // §9.5: protected paths are never written, whatever the release
                // contains. config.php, .env, uploads/ and storage/ survive.
                if ($this->guard->isProtected($relative)) {
                    $skippedProtected++;
                    continue;
                }

                if (!$this->guard->isSafe($relative)) {
                    $log->warn('Skipped an unsafe path from the staged tree', ['path' => $relative]);
                    continue;
                }

                $target = $this->guard->resolve($relative);

                if (is_file($target)) {
                    // Identical files are left alone — fewer journal entries and
                    // a far shorter rollback.
                    if (hash_file('sha256', $target) === hash_file('sha256', $file->getPathname())) {
                        continue;
                    }
                    $journal->recordReplace($relative, $target);
                } else {
                    $journal->recordCreate($relative);
                    $created++;
                }

                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new UpdateException('Cannot create directory: ' . dirname($relative), 'APPLY');
                }

                if (!copy($file->getPathname(), $target)) {
                    throw new UpdateException('Failed to copy ' . $relative, 'APPLY');
                }

                @chmod($target, 0644);
                $copied++;
            }

            // Apply the manifest's delete list. deletions() has already dropped
            // anything unsafe or protected.
            $manifest = UpdateEnv::manifestFor($update, true);
            $deleted = 0;
            foreach ($manifest->deletions($this->guard) as $relative) {
                $target = $this->guard->resolve($relative);
                if (is_file($target)) {
                    $journal->recordDelete($relative, $target);
                    if (@unlink($target)) {
                        $deleted++;
                        $log->info('  − removed ' . $relative);
                    }
                }
            }

            $this->fixPermissions();

            file_put_contents($this->appRoot . '/VERSION', $update['to_version'] . "\n");
            UpdateSetting::setCurrentCommit($sha);

            $message = sprintf(
                'Applied: %d file(s) written (%d new), %d removed, %d protected path(s) left untouched.',
                $copied,
                $created,
                $deleted,
                $skippedProtected
            );
            $log->info($message);

            return $message;
        } finally {
            $journal->close();
        }
    }

    /** @param array<string,mixed> $update */
    public function stepPost(array $update, UpdateLog $log): string
    {
        $log->step('POST', 'Running post-update tasks and clearing caches');

        $manifest = UpdateEnv::manifestFor($update, true);
        $ran = 0;

        foreach ($manifest->postUpdateScripts($this->guard) as $script) {
            $path = $this->guard->resolve($script);
            if (!is_file($path)) {
                $log->warn('Post-update script not found; skipped', ['script' => $script]);
                continue;
            }

            try {
                // Scoped include so a script cannot accidentally clobber our locals.
                (static function (string $file): void {
                    require $file;
                })($path);
                $ran++;
                $log->info('  ✓ ran ' . $script);
            } catch (\Throwable $e) {
                // A post-update task is by definition not load-bearing; log it
                // and continue rather than rolling back a good file copy.
                $log->warn('Post-update script failed: ' . $script . ' — ' . $e->getMessage());
            }
        }

        $cleared = UpdateEnv::clearCaches($this->appRoot);
        $log->info(sprintf('Caches cleared: %s.', implode(', ', $cleared)));

        $message = sprintf('Post-update complete: %d script(s) ran, caches cleared.', $ran);
        $log->info($message);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepHealth(array $update, UpdateLog $log): string
    {
        $log->step('HEALTH', 'Verifying the updated installation');

        $health = HealthChecker::make()->run();

        foreach ($health['checks'] as $check) {
            $marker = $check['ok'] ? '✓' : ($check['critical'] ? '✗' : '!');
            $log->write($check['ok'] ? 'info' : ($check['critical'] ? 'error' : 'warning'),
                sprintf('  %s %s: %s (%dms)', $marker, $check['name'], $check['detail'], $check['ms']));
        }

        if (!$health['ok']) {
            throw new UpdateException(sprintf(
                'Health check failed with %d critical failure(s); rolling back.',
                $health['critical_failures']
            ), 'HEALTH');
        }

        $message = sprintf('Health check passed (%d checks).', count($health['checks']));
        $log->info($message);

        return $message;
    }

    /** @param array<string,mixed> $update */
    public function stepFinalise(array $update, UpdateLog $log): string
    {
        $log->step('FINALISE', 'Finishing up');

        $this->maintenance->disable();

        $updateId = (int) $update['id'];
        AppUpdate::succeed($updateId);

        $this->cleanStaging((string) $update['to_commit']);

        // The journal is deliberately kept. It is the per-file undo list that
        // "roll back to this point" in History replays; discarding it here
        // would leave that button able to reverse migrations but restore no
        // files at all. It is pruned on the same retention count as the
        // backups it pairs with.
        $retention = (int) UpdateSetting::current()['backup_retention'];
        $pruned = BackupManager::make()->prune($retention);
        if ($pruned['removed'] > 0) {
            $log->info(sprintf('Pruned %d old backup(s), freeing %s.', $pruned['removed'], UpdateEnv::humanBytes($pruned['freed_bytes'])));
        }

        $prunedJournals = RollbackJournal::pruneDirectory($this->appRoot . '/storage/updates', $retention);
        if ($prunedJournals['removed'] > 0) {
            $log->info(sprintf(
                'Pruned %d old rollback journal(s), freeing %s.',
                $prunedJournals['removed'],
                UpdateEnv::humanBytes($prunedJournals['freed_bytes'])
            ));
        }

        Config::set('app.version', (string) $update['to_version']);

        AuditService::log('update.success', 'app_update', $updateId, null, [
            'from' => $update['from_version'],
            'to'   => $update['to_version'],
        ]);

        NotificationService::notifySuperAdmins(
            'success',
            sprintf('Updated to %s', $update['to_version']),
            sprintf(
                "The panel was updated from %s to %s.\nCommit: %s\nAll health checks passed.",
                $update['from_version'],
                $update['to_version'],
                substr((string) $update['to_commit'], 0, 7)
            ),
            'admin/updates/' . $updateId,
            'update'
        );

        $message = sprintf('Update complete. Now running %s.', $update['to_version']);
        $log->info($message);

        return $message;
    }


    // ------------------------------------------------------------- internals

    /**
     * Files whose content differs from what the last release recorded.
     *
     * Best effort: without git we compare against the manifest's checksums,
     * which is enough to warn about a hand-edited file about to be replaced.
     *
     * @return list<string>
     */
    private function detectLocalModifications(): array
    {
        $file = $this->appRoot . '/update.json';
        if (!is_file($file)) {
            return [];
        }

        try {
            $manifest = Manifest::fromJson((string) file_get_contents($file));
        } catch (UpdateException) {
            return [];
        }

        $modified = [];
        foreach ($manifest->checksums() as $relative => $expected) {
            $path = $this->appRoot . '/' . ltrim($relative, '/');
            if (!is_file($path)) {
                continue;
            }
            $expectedHash = str_starts_with($expected, 'sha256:') ? substr($expected, 7) : $expected;
            $actual = hash_file('sha256', $path);
            if ($actual !== false && !hash_equals(strtolower($expectedHash), strtolower($actual))) {
                $modified[] = $relative;
            }
        }

        return $modified;
    }

    private function fixPermissions(): void
    {
        foreach (['storage', 'uploads'] as $directory) {
            $path = $this->appRoot . '/' . $directory;
            if (is_dir($path)) {
                @chmod($path, 0775);
            }
        }
        foreach (['config', 'app', 'database', 'cli'] as $directory) {
            $path = $this->appRoot . '/' . $directory;
            if (is_dir($path)) {
                @chmod($path, 0755);
            }
        }

        $config = $this->appRoot . '/config/config.php';
        if (is_file($config)) {
            @chmod($config, 0640);
        }
    }

    private function cleanStaging(string $sha): void
    {
        $archive = sprintf('%s/storage/updates/tmp/%s.zip', $this->appRoot, $sha);
        if (is_file($archive)) {
            @unlink($archive);
        }

        $stage = sprintf('%s/storage/updates/stage/%s', $this->appRoot, $sha);
        if (is_dir($stage)) {
            UpdateEnv::removeDirectory($stage);
        }
    }
}
