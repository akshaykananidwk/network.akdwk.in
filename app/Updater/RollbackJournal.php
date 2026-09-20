<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Logger;
use App\Core\UpdateException;

/**
 * Per-file undo log for the APPLY step.
 *
 * Before any live file is written, its previous contents are copied aside and
 * an entry is appended here. The journal is written to disk line by line and
 * fsync'd, so it survives the PHP process being killed mid-apply — which is
 * exactly the case where the whole backup tarball is a clumsy tool and a
 * per-file undo list is the right one.
 *
 * Replaying in reverse restores the tree to its pre-APPLY state:
 *   created  -> delete the file
 *   replaced -> copy the saved original back
 *   deleted  -> copy the saved original back
 */
final class RollbackJournal
{
    public const ACTION_CREATED  = 'created';
    public const ACTION_REPLACED = 'replaced';
    public const ACTION_DELETED  = 'deleted';

    /** @var resource|null */
    private $handle = null;
    private int $entries = 0;

    public function __construct(
        private readonly string $journalPath,
        private readonly string $backupDirectory,
    ) {
    }

    public function open(): void
    {
        $directory = dirname($this->journalPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new UpdateException('Cannot create the rollback journal directory.', 'APPLY');
        }
        if (!is_dir($this->backupDirectory) && !mkdir($this->backupDirectory, 0750, true) && !is_dir($this->backupDirectory)) {
            throw new UpdateException('Cannot create the rollback backup directory.', 'APPLY');
        }

        $handle = fopen($this->journalPath, 'ab');
        if ($handle === false) {
            throw new UpdateException('Cannot open the rollback journal for writing.', 'APPLY');
        }

        $this->handle = $handle;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function path(): string
    {
        return $this->journalPath;
    }

    public function entryCount(): int
    {
        return $this->entries;
    }

    /**
     * Record that a live file is about to be replaced, and preserve the
     * original.
     */
    public function recordReplace(string $relativePath, string $absolutePath): void
    {
        $saved = $this->preserve($relativePath, $absolutePath);
        $this->append([
            'action'   => self::ACTION_REPLACED,
            'path'     => $relativePath,
            'saved_as' => $saved,
            'mode'     => $this->fileMode($absolutePath),
        ]);
    }

    /** Record that a file is about to be created where none existed. */
    public function recordCreate(string $relativePath): void
    {
        $this->append([
            'action'   => self::ACTION_CREATED,
            'path'     => $relativePath,
            'saved_as' => null,
            'mode'     => null,
        ]);
    }

    /** Record that a file is about to be deleted, and preserve it. */
    public function recordDelete(string $relativePath, string $absolutePath): void
    {
        $saved = $this->preserve($relativePath, $absolutePath);
        $this->append([
            'action'   => self::ACTION_DELETED,
            'path'     => $relativePath,
            'saved_as' => $saved,
            'mode'     => $this->fileMode($absolutePath),
        ]);
    }

    /**
     * Undo everything in the journal, newest entry first.
     *
     * Individual failures do not abort the replay — restoring nine files out of
     * ten is better than stopping at the first problem — but they are counted
     * and reported so the caller can decide the rollback itself failed.
     *
     * @return array{restored:int,deleted:int,failed:list<array{path:string,error:string}>}
     */
    public function replay(PathGuard $guard): array
    {
        $this->close();

        if (!is_file($this->journalPath)) {
            return ['restored' => 0, 'deleted' => 0, 'failed' => []];
        }

        $lines = file($this->journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new UpdateException('Rollback journal could not be read: ' . $this->journalPath);
        }

        $restored = 0;
        $deleted = 0;
        $failed = [];

        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry) || !isset($entry['action'], $entry['path'])) {
                continue;
            }

            $relative = (string) $entry['path'];

            try {
                // The journal is ours, but replay still goes through the guard:
                // a corrupted journal must not become an arbitrary file write.
                $absolute = $guard->resolve($relative);

                switch ($entry['action']) {
                    case self::ACTION_CREATED:
                        if (is_file($absolute) && @unlink($absolute)) {
                            $deleted++;
                        }
                        break;

                    case self::ACTION_REPLACED:
                    case self::ACTION_DELETED:
                        $saved = $entry['saved_as'] ?? null;
                        if (!is_string($saved)) {
                            throw new UpdateException('Journal entry has no preserved copy.');
                        }
                        $savedPath = $this->backupDirectory . '/' . ltrim($saved, '/');
                        if (!is_file($savedPath)) {
                            throw new UpdateException('Preserved copy is missing: ' . $saved);
                        }

                        $directory = dirname($absolute);
                        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                            throw new UpdateException('Cannot recreate directory: ' . $directory);
                        }

                        if (!copy($savedPath, $absolute)) {
                            throw new UpdateException('Copy back failed.');
                        }

                        if (isset($entry['mode']) && is_int($entry['mode'])) {
                            @chmod($absolute, $entry['mode']);
                        }
                        $restored++;
                        break;
                }
            } catch (\Throwable $e) {
                $failed[] = ['path' => $relative, 'error' => $e->getMessage()];
                Logger::error('update', 'Rollback entry failed', ['path' => $relative, 'error' => $e->getMessage()]);
            }
        }

        Logger::info('update', 'Rollback journal replayed', [
            'restored' => $restored,
            'deleted'  => $deleted,
            'failed'   => count($failed),
        ]);

        return ['restored' => $restored, 'deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Keep only the newest journals in a directory, discarding the rest.
     *
     * A journal outlives the update that wrote it: without it, "roll back to
     * this point" in History has no per-file undo list and restores nothing.
     * It is still disk, so it is pruned on the same retention count as the
     * backups it pairs with.
     *
     * @return array{removed:int,freed_bytes:int}
     */
    public static function pruneDirectory(string $directory, int $keep): array
    {
        $keep = max(1, $keep);

        $journals = glob($directory . '/journal-*.jsonl') ?: [];
        if (count($journals) <= $keep) {
            return ['removed' => 0, 'freed_bytes' => 0];
        }

        // Newest update id first, so the most recent runs keep their undo list.
        usort($journals, static function (string $a, string $b): int {
            return self::updateIdOf($b) <=> self::updateIdOf($a);
        });

        $removed = 0;
        $freed = 0;

        foreach (array_slice($journals, $keep) as $path) {
            $updateId = self::updateIdOf($path);
            if ($updateId === 0) {
                continue;
            }

            $files = sprintf('%s/journal-%d-files', $directory, $updateId);
            $freed += self::sizeOf($path) + self::sizeOf($files);

            (new self($path, $files))->discard();
            $removed++;
        }

        if ($removed > 0) {
            Logger::info('update', 'Old rollback journals pruned', ['removed' => $removed, 'freed_bytes' => $freed]);
        }

        return ['removed' => $removed, 'freed_bytes' => $freed];
    }

    private static function updateIdOf(string $journalPath): int
    {
        return preg_match('/journal-(\\d+)\\.jsonl$/', $journalPath, $m) === 1 ? (int) $m[1] : 0;
    }

    /** Bytes used by a file, or by a directory tree. */
    private static function sizeOf(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        if (!is_dir($path)) {
            return 0;
        }

        $bytes = 0;
        foreach (glob($path . '/*') ?: [] as $child) {
            $bytes += self::sizeOf($child);
        }

        return $bytes;
    }

    /** Discard the journal and its preserved copies after a successful update. */
    public function discard(): void
    {
        $this->close();

        if (is_file($this->journalPath)) {
            @unlink($this->journalPath);
        }

        if (is_dir($this->backupDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->backupDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item instanceof \SplFileInfo) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
            }
            @rmdir($this->backupDirectory);
        }
    }

    /** Copy a live file aside, flattening its path into a unique name. */
    private function preserve(string $relativePath, string $absolutePath): string
    {
        $savedName = bin2hex(random_bytes(6)) . '_' . str_replace('/', '__', $relativePath);
        $savedPath = $this->backupDirectory . '/' . $savedName;

        if (!copy($absolutePath, $savedPath)) {
            throw new UpdateException('Could not preserve the original of ' . $relativePath . ' before overwriting it.', 'APPLY');
        }

        return $savedName;
    }

    /** @param array<string,mixed> $entry */
    private function append(array $entry): void
    {
        if ($this->handle === null) {
            throw new UpdateException('Rollback journal is not open.', 'APPLY');
        }

        $entry['at'] = gmdate('c');
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new UpdateException('Could not encode a rollback journal entry.', 'APPLY');
        }

        if (fwrite($this->handle, $line . "\n") === false) {
            throw new UpdateException('Could not write to the rollback journal (disk full?).', 'APPLY');
        }

        // Flush every entry: a journal that lags behind the files it describes
        // is worse than no journal at all.
        fflush($this->handle);

        $this->entries++;
    }

    private function fileMode(string $path): ?int
    {
        $perms = @fileperms($path);

        return $perms === false ? null : ($perms & 0777);
    }
}
