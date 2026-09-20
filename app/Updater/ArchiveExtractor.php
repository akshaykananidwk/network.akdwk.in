<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Logger;
use App\Core\UpdateException;
use ZipArchive;

/**
 * Extracts a GitHub zipball into a staging directory.
 *
 * GitHub wraps everything in a single top-level folder named
 * "{owner}-{repo}-{sha7}"; that prefix is stripped so the staged tree mirrors
 * the repository root.
 *
 * Every entry is validated by PathGuard before a byte is written. Entries that
 * fail are skipped and counted, and a non-zero rejection count aborts the
 * update — a legitimate repository never produces one, so a rejection means
 * either a corrupt download or a crafted archive.
 */
final class ArchiveExtractor
{
    /** Refuse an archive that would expand to more than this (zip bomb guard). */
    private const MAX_UNCOMPRESSED_BYTES = 2_147_483_648; // 2 GB
    private const MAX_ENTRIES = 50_000;

    public function __construct(private readonly PathGuard $guard)
    {
    }

    /**
     * @return array{files:int,bytes:int,rejected:list<string>,root_prefix:string}
     * @throws UpdateException
     */
    public function extract(string $zipPath, string $destination): array
    {
        if (!is_file($zipPath)) {
            throw new UpdateException('Archive not found: ' . $zipPath, 'STAGE');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new UpdateException('Archive could not be opened (code ' . $opened . '). The download may be corrupt.', 'STAGE');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new UpdateException('Archive contains an implausible number of entries (' . $zip->numFiles . ').', 'STAGE');
            }

            $prefix = $this->detectRootPrefix($zip);
            $this->assertNotZipBomb($zip);

            if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
                throw new UpdateException('Cannot create staging directory: ' . $destination, 'STAGE');
            }

            $files = 0;
            $bytes = 0;
            $rejected = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = (string) $stat['name'];
                $relative = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;

                if ($relative === '' || str_ends_with($relative, '/')) {
                    continue; // directory entry
                }

                $safe = $this->guard->validateArchiveEntry($relative, $this->isSymlink($zip, $i));
                if ($safe === null) {
                    $rejected[] = $name;
                    continue;
                }

                // Resolve against the staging root, not the app root: the same
                // traversal rules apply, just with a different base.
                $target = $this->resolveInto($destination, $safe);
                if ($target === null) {
                    $rejected[] = $name;
                    continue;
                }

                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    throw new UpdateException('Cannot create staging subdirectory: ' . $directory, 'STAGE');
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new UpdateException('Could not read archive entry: ' . $name, 'STAGE');
                }

                if (file_put_contents($target, $contents) === false) {
                    throw new UpdateException('Could not write staged file: ' . $target, 'STAGE');
                }

                chmod($target, 0644);
                $files++;
                $bytes += strlen($contents);
            }

            if ($rejected !== []) {
                Logger::critical('update', 'Archive contained unsafe entries; extraction aborted', [
                    'rejected_count' => count($rejected),
                    'examples'       => array_slice($rejected, 0, 5),
                ]);
                throw new UpdateException(
                    sprintf(
                        'Archive contains %d unsafe entr%s (path traversal, absolute path or symlink). Update aborted.',
                        count($rejected),
                        count($rejected) === 1 ? 'y' : 'ies'
                    ),
                    'STAGE'
                );
            }

            if ($files === 0) {
                throw new UpdateException('Archive contained no files.', 'STAGE');
            }

            Logger::info('update', 'Archive extracted', ['files' => $files, 'bytes' => $bytes, 'prefix' => $prefix]);

            return ['files' => $files, 'bytes' => $bytes, 'rejected' => $rejected, 'root_prefix' => $prefix];
        } finally {
            $zip->close();
        }
    }

    /**
     * Verify every staged PHP file parses.
     *
     * A half-downloaded or truncated file that reaches the live tree takes the
     * whole panel down, and the health check would then be running against a
     * broken autoloader. Catching it at STAGE keeps the failure recoverable.
     *
     * @return array{ok:bool,checked:int,errors:list<array{file:string,error:string}>}
     */
    public function verifyPhpSyntax(string $stageDirectory): array
    {
        $errors = [];
        $checked = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stageDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $checked++;
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                $errors[] = ['file' => $file->getPathname(), 'error' => 'unreadable'];
                continue;
            }

            // token_get_all with TOKEN_PARSE performs a real parse and raises
            // ParseError on a syntax error, without needing to shell out to
            // `php -l` (which may be disabled on shared hosting).
            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $errors[] = [
                    'file'  => str_replace($stageDirectory . '/', '', $file->getPathname()),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['ok' => $errors === [], 'checked' => $checked, 'errors' => $errors];
    }

    /**
     * Verify staged files against the manifest's checksums.
     *
     * @param array<string,string> $checksums
     * @return array{ok:bool,checked:int,mismatches:list<string>,missing:list<string>}
     */
    public function verifyChecksums(string $stageDirectory, array $checksums): array
    {
        $mismatches = [];
        $missing = [];
        $checked = 0;

        foreach ($checksums as $relative => $expected) {
            $path = $stageDirectory . '/' . ltrim($relative, '/');
            if (!is_file($path)) {
                $missing[] = $relative;
                continue;
            }

            $checked++;
            $expectedHash = str_starts_with($expected, 'sha256:') ? substr($expected, 7) : $expected;
            $actual = hash_file('sha256', $path);

            if ($actual === false || !hash_equals(strtolower($expectedHash), strtolower($actual))) {
                $mismatches[] = $relative;
            }
        }

        return [
            'ok'         => $mismatches === [] && $missing === [],
            'checked'    => $checked,
            'mismatches' => $mismatches,
            'missing'    => $missing,
        ];
    }

    /**
     * GitHub names the wrapper folder "{owner}-{repo}-{sha7}". We detect it
     * rather than assume it, so an archive produced another way still works.
     */
    private function detectRootPrefix(ZipArchive $zip): string
    {
        $first = $zip->getNameIndex(0);
        if ($first === false) {
            return '';
        }

        $slash = strpos($first, '/');
        if ($slash === false) {
            return '';
        }

        $candidate = substr($first, 0, $slash + 1);

        // Only strip it when *every* entry shares it.
        for ($i = 1; $i < min($zip->numFiles, 50); $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && !str_starts_with($name, $candidate)) {
                return '';
            }
        }

        return $candidate;
    }

    private function assertNotZipBomb(ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $total += (int) ($stat['size'] ?? 0);
            }
        }

        if ($total > self::MAX_UNCOMPRESSED_BYTES) {
            throw new UpdateException(
                sprintf('Archive would expand to %s, which exceeds the safety limit.', $this->humanBytes($total)),
                'STAGE'
            );
        }
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        // Unix mode is stored in the high 16 bits of the external attributes.
        $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr) ? (int) $attr : 0;
        $mode = ($attributes >> 16) & 0xFFFF;

        return ($mode & 0xF000) === 0xA000; // S_IFLNK
    }

    /** Resolve a validated relative path inside an arbitrary base directory. */
    private function resolveInto(string $base, string $relative): ?string
    {
        $base = rtrim($this->guard->normalise($base), '/');
        $candidate = $this->guard->normalise($base . '/' . $relative);

        return str_starts_with($candidate, $base . '/') ? $candidate : null;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
