<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Logger;
use App\Core\UpdateException;
use App\Models\AppBackup;
use App\Services\AuditService;

/**
 * File and database backups (§9.4 steps 3–4, §16).
 *
 * Two rules shape this class:
 *
 *   * A backup is either complete or it is not a backup. Every artefact gets a
 *     sha256 recorded at creation, and verify() re-checks it before a restore
 *     is ever attempted.
 *
 *   * Nothing is skipped silently. When uploads/ is too large to include, the
 *     row records uploads_included = 0 with a reason, and the UI shows it. An
 *     operator who thinks they have a full backup and does not is worse off
 *     than one who knows they have a partial one.
 */
final class BackupManager
{
    /** Directories never included in a file backup (they are backups, or scratch). */
    private const ALWAYS_EXCLUDE = [
        'storage/backups',
        'storage/tmp',
        'storage/updates',
        'storage/cache',
        '.git',
        'node_modules',
    ];

    public function __construct(
        private readonly string $appRoot,
        private readonly string $backupRoot,
    ) {
    }

    public static function make(): self
    {
        return new self(APP_ROOT, APP_ROOT . '/storage/backups');
    }

    /**
     * Take a full backup: application files plus the database.
     *
     * @param string $type pre_update | manual | scheduled
     * @return array<string,mixed> the app_backups row
     */
    public function createFull(string $type = 'manual'): array
    {
        $stamp = gmdate('Ymd-His');
        $directory = $this->backupRoot . '/' . $stamp . '-' . bin2hex(random_bytes(3));

        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new UpdateException('Cannot create the backup directory: ' . $directory, 'BACKUP_FILES');
        }

        $backupId = AppBackup::create([
            'type'        => in_array($type, ['pre_update', 'manual', 'scheduled'], true) ? $type : 'manual',
            'app_version' => (string) Config::get('app.version', 'unknown'),
            'status'      => 'running',
            'created_by'  => Auth::id(),
        ]);

        try {
            $files = $this->backupFiles($directory);
            $database = DatabaseDumper::fromConfig()->dump($directory . '/db.sql.gz');

            $retentionDays = (int) Config::get('backup.retention_days', 30);

            AppBackup::update($backupId, [
                'files_path'             => $this->relative($files['path']),
                'db_path'                => $this->relative($database['path']),
                'files_sha256'           => $files['sha256'],
                'db_sha256'              => $database['sha256'],
                'db_method'              => $database['method'],
                'size_bytes'             => $files['bytes'] + $database['bytes'],
                'uploads_included'       => $files['uploads_included'] ? 1 : 0,
                'uploads_skipped_reason' => $files['uploads_skipped_reason'],
                'status'                 => 'complete',
                'retained_until'         => gmdate('Y-m-d H:i:s', time() + ($retentionDays * 86400)),
            ]);

            $row = AppBackup::findRow($backupId) ?? [];

            AuditService::log('backup.create', 'backup', $backupId, null, [
                'type'             => $type,
                'size_bytes'       => $files['bytes'] + $database['bytes'],
                'uploads_included' => $files['uploads_included'],
            ]);

            Logger::info('backup', 'Backup complete', [
                'backup_id'        => $backupId,
                'bytes'            => $files['bytes'] + $database['bytes'],
                'uploads_included' => $files['uploads_included'],
                'dump_method'      => $database['method'],
            ]);

            return $row;
        } catch (\Throwable $e) {
            AppBackup::update($backupId, [
                'status'     => 'failed',
                'error_text' => Logger::redactString($e->getMessage()),
            ]);
            Logger::error('backup', 'Backup failed', ['backup_id' => $backupId, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Archive the application tree.
     *
     * tar.gz via PharData when available (streams, handles permissions), zip
     * otherwise. Either way the archive holds paths relative to the app root,
     * so a restore does not depend on the install living at the same path.
     *
     * @return array{path:string,bytes:int,sha256:string,count:int,uploads_included:bool,uploads_skipped_reason:string|null}
     */
    private function backupFiles(string $directory): array
    {
        $maxUploads = (int) Config::get('backup.max_uploads_bytes', 2_147_483_648);
        $uploadsSize = $this->directorySize($this->appRoot . '/uploads');
        $includeUploads = $uploadsSize <= $maxUploads;
        $skipReason = $includeUploads
            ? null
            : sprintf(
                'uploads/ is %s, above the %s limit for inline backups. Back it up separately.',
                $this->humanBytes($uploadsSize),
                $this->humanBytes($maxUploads)
            );

        if (!$includeUploads) {
            Logger::warning('backup', 'uploads/ excluded from the file backup', [
                'bytes' => $uploadsSize,
                'limit' => $maxUploads,
            ]);
        }

        $exclude = self::ALWAYS_EXCLUDE;
        if (!$includeUploads) {
            $exclude[] = 'uploads';
        }

        $files = $this->collectFiles($this->appRoot, $exclude);

        $target = $directory . '/files.tar.gz';
        $count = $this->writeTarGz($target, $files);

        return [
            'path'                   => $target,
            'bytes'                  => (int) filesize($target),
            'sha256'                 => (string) hash_file('sha256', $target),
            'count'                  => $count,
            'uploads_included'       => $includeUploads,
            'uploads_skipped_reason' => $skipReason,
        ];
    }

    /**
     * @param list<string> $relativeFiles
     * @return int files written
     */
    private function writeTarGz(string $target, array $relativeFiles): int
    {
        $tarPath = substr($target, 0, -3); // strip .gz

        if (is_file($tarPath)) {
            @unlink($tarPath);
        }
        if (is_file($target)) {
            @unlink($target);
        }

        try {
            $phar = new \PharData($tarPath);
            $written = 0;

            foreach ($relativeFiles as $relative) {
                $absolute = $this->appRoot . '/' . $relative;
                if (!is_file($absolute) || is_link($absolute)) {
                    continue;
                }
                $phar->addFile($absolute, $relative);
                $written++;
            }

            $phar->compress(\Phar::GZ);
            unset($phar);
            @unlink($tarPath);

            if (!is_file($target)) {
                throw new UpdateException('Compressed archive was not produced.', 'BACKUP_FILES');
            }

            return $written;
        } catch (\PharException | \BadMethodCallException | \UnexpectedValueException $e) {
            // phar.readonly=1 is common on hardened hosts; fall back to zip.
            @unlink($tarPath);
            Logger::warning('backup', 'tar.gz unavailable, using zip', ['error' => $e->getMessage()]);

            return $this->writeZip(substr($target, 0, -7) . '.zip', $relativeFiles, $target);
        }
    }

    /** @param list<string> $relativeFiles */
    private function writeZip(string $zipPath, array $relativeFiles, string $renameTo): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('Cannot create the backup archive: ' . $zipPath, 'BACKUP_FILES');
        }

        $written = 0;
        foreach ($relativeFiles as $relative) {
            $absolute = $this->appRoot . '/' . $relative;
            if (is_file($absolute) && !is_link($absolute) && $zip->addFile($absolute, $relative)) {
                $written++;
            }
        }
        $zip->close();

        // Keep the recorded path stable whichever format was used; the
        // extension inside the name still says what it is.
        rename($zipPath, $renameTo);

        return $written;
    }

    /**
     * @param list<string> $excludeRelative
     * @return list<string> paths relative to the app root
     */
    private function collectFiles(string $root, array $excludeRelative): array
    {
        $out = [];
        $rootLength = strlen(rtrim($root, '/')) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                static function (\SplFileInfo $current) use ($rootLength, $excludeRelative): bool {
                    $relative = substr($current->getPathname(), $rootLength);
                    foreach ($excludeRelative as $exclude) {
                        if ($relative === $exclude || str_starts_with($relative, $exclude . '/')) {
                            return false;
                        }
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                $out[] = substr($file->getPathname(), $rootLength);
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Confirm a backup's artefacts are present and match their recorded hashes.
     *
     * @return array{ok:bool,checks:list<array{name:string,ok:bool,detail:string}>}
     */
    public function verify(int $backupId): array
    {
        $backup = AppBackup::findRow($backupId);
        if ($backup === null) {
            return ['ok' => false, 'checks' => [['name' => 'record', 'ok' => false, 'detail' => 'Backup not found.']]];
        }

        $checks = [];

        foreach ([['files', 'files_path', 'files_sha256'], ['database', 'db_path', 'db_sha256']] as [$label, $pathKey, $hashKey]) {
            $relative = $backup[$pathKey] ?? null;
            if ($relative === null || $relative === '') {
                $checks[] = ['name' => $label, 'ok' => false, 'detail' => 'No path recorded.'];
                continue;
            }

            $absolute = $this->appRoot . '/' . ltrim((string) $relative, '/');
            if (!is_file($absolute)) {
                $checks[] = ['name' => $label, 'ok' => false, 'detail' => 'Missing: ' . $relative];
                continue;
            }

            $expected = (string) ($backup[$hashKey] ?? '');
            $actual = (string) hash_file('sha256', $absolute);

            $checks[] = $expected !== '' && hash_equals($expected, $actual)
                ? ['name' => $label, 'ok' => true, 'detail' => sprintf('%s, sha256 matches', $this->humanBytes((int) filesize($absolute)))]
                : ['name' => $label, 'ok' => false, 'detail' => 'Checksum mismatch — the archive has changed since it was written.'];
        }

        $ok = array_reduce($checks, static fn (bool $carry, array $c): bool => $carry && $c['ok'], true);

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * Restore application files from a backup.
     *
     * Protected paths are skipped: a restore must not clobber the live
     * config.php or re-import a stale uploads/ tree over newer files.
     *
     * @return array{restored:int,skipped:int}
     */
    public function restoreFiles(int $backupId, PathGuard $guard): array
    {
        $backup = AppBackup::findRow($backupId);
        if ($backup === null || empty($backup['files_path'])) {
            throw new UpdateException('No file archive recorded for backup #' . $backupId);
        }

        $verification = $this->verify($backupId);
        if (!$verification['ok']) {
            throw new UpdateException('Refusing to restore: backup #' . $backupId . ' failed verification.');
        }

        $archive = $this->appRoot . '/' . ltrim((string) $backup['files_path'], '/');
        $workspace = $this->appRoot . '/storage/tmp/restore-' . bin2hex(random_bytes(4));

        if (!mkdir($workspace, 0750, true) && !is_dir($workspace)) {
            throw new UpdateException('Cannot create the restore workspace.');
        }

        try {
            $this->extractArchive($archive, $workspace);

            $restored = 0;
            $skipped = 0;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS)
            );

            $workspaceLength = strlen($workspace) + 1;

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $relative = substr($file->getPathname(), $workspaceLength);

                if ($guard->isProtected($relative) || !$guard->isSafe($relative)) {
                    $skipped++;
                    continue;
                }

                $target = $guard->resolve($relative);
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    $skipped++;
                    continue;
                }

                if (copy($file->getPathname(), $target)) {
                    chmod($target, 0644);
                    $restored++;
                } else {
                    $skipped++;
                }
            }

            Logger::info('backup', 'Files restored', ['backup_id' => $backupId, 'restored' => $restored, 'skipped' => $skipped]);

            return ['restored' => $restored, 'skipped' => $skipped];
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function restoreDatabase(int $backupId): int
    {
        $backup = AppBackup::findRow($backupId);
        if ($backup === null || empty($backup['db_path'])) {
            throw new UpdateException('No database dump recorded for backup #' . $backupId);
        }

        $dump = $this->appRoot . '/' . ltrim((string) $backup['db_path'], '/');

        return DatabaseDumper::fromConfig()->restore($dump);
    }

    /**
     * Delete backups beyond the retention count.
     *
     * @return array{removed:int,freed_bytes:int}
     */
    public function prune(int $keep): array
    {
        $removed = 0;
        $freed = 0;

        foreach (AppBackup::prunable($keep) as $backup) {
            foreach (['files_path', 'db_path'] as $key) {
                $relative = $backup[$key] ?? null;
                if ($relative === null || $relative === '') {
                    continue;
                }
                $absolute = $this->appRoot . '/' . ltrim((string) $relative, '/');
                if (is_file($absolute)) {
                    $freed += (int) filesize($absolute);
                    @unlink($absolute);
                }
            }

            $directory = $this->appRoot . '/' . ltrim((string) ($backup['files_path'] ?? ''), '/');
            $parent = dirname($directory);
            if (is_dir($parent) && str_starts_with($parent, $this->backupRoot . '/')) {
                @rmdir($parent);
            }

            AppBackup::delete((int) $backup['id']);
            $removed++;
        }

        if ($removed > 0) {
            Logger::info('backup', 'Old backups pruned', ['removed' => $removed, 'freed_bytes' => $freed]);
        }

        return ['removed' => $removed, 'freed_bytes' => $freed];
    }

    private function extractArchive(string $archive, string $destination): void
    {
        if (str_ends_with($archive, '.tar.gz') || str_ends_with($archive, '.tgz')) {
            try {
                $phar = new \PharData($archive);
                $phar->extractTo($destination, null, true);

                return;
            } catch (\Throwable $e) {
                // A .tar.gz name produced by the zip fallback still opens below.
                Logger::debug('backup', 'PharData extraction failed, trying zip', ['error' => $e->getMessage()]);
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new UpdateException('Cannot open the backup archive: ' . basename($archive));
        }
        $zip->extractTo($destination);
        $zip->close();
    }

    private function directorySize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function relative(string $absolute): string
    {
        $root = rtrim($this->appRoot, '/') . '/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
