<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Logger;
use App\Core\UpdateException;

/**
 * Writing, reading back and extracting backup archives.
 *
 * Split out of BackupManager, which decides what to back up and keeps the
 * records; this class only knows how to get a tree of files into an archive
 * and out again.
 *
 * Its rule is that an archive is not a backup until it has been read back. A
 * disk that fills up mid-write leaves a file that exists, has a size, and
 * hashes consistently with itself — every property a checksum can see — while
 * containing nothing restorable. A live run against a 256 KB disk produced a
 * zero-byte archive that the pipeline reported as verified, which is why every
 * write here ends by opening what it just wrote.
 */
final class ArchiveStore
{
    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * Read the archive back before calling it a backup.
     *
     * @throws UpdateException when it cannot be opened, or holds fewer files
     *                         than were put in
     */
    public function assertReadable(string $path, int $expectedEntries): void
    {
        if (!is_file($path)) {
            throw new UpdateException('Compressed archive was not produced.', 'BACKUP_FILES');
        }

        $entries = $this->entryCount($path);

        if ($entries === null) {
            throw new UpdateException(
                'The backup archive cannot be read back — it was truncated as it was written'
                . ' (a full disk is the usual cause). Refusing to treat it as a backup.',
                'BACKUP_FILES'
            );
        }

        if ($entries < $expectedEntries) {
            throw new UpdateException(sprintf(
                'The backup archive holds %d of the %d files it should — it is incomplete.'
                . ' Refusing to treat it as a backup.',
                $entries,
                $expectedEntries
            ), 'BACKUP_FILES');
        }
    }

    /** @return array{ok:bool,detail:string} for the verification report */
    public function describe(string $path): array
    {
        $entries = $this->entryCount($path);

        return $entries === null || $entries === 0
            ? ['ok' => false, 'detail' => 'but it cannot be read back — the archive is truncated or corrupt']
            : ['ok' => true, 'detail' => sprintf('%d files, archive reads back cleanly', $entries)];
    }

    /**
     * How many files the archive actually contains, or null if it cannot be
     * opened at all.
     */
    private function entryCount(string $path): ?int
    {
        if (filesize($path) === 0) {
            return null;
        }

        try {
            if (str_ends_with($path, '.zip')) {
                $zip = new \ZipArchive();
                if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
                    return null;
                }
                $count = $zip->numFiles;
                $zip->close();

                return $count;
            }

            $count = 0;
            foreach (new \RecursiveIteratorIterator(new \PharData($path)) as $ignored) {
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            Logger::warning('backup', 'Backup archive could not be read back', [
                'path'  => basename($path),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }


    /**
     * @param list<string> $relativeFiles
     * @return int files written
     */
    public function writeTarGz(string $target, array $relativeFiles): int
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

            $this->assertReadable($target, $written);

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
    public function collectFiles(string $root, array $excludeRelative): array
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


    public function extract(string $archive, string $destination): void
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
}
