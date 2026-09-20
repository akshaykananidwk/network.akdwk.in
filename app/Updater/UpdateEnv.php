<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Models\Setting;

/**
 * Small shared helpers used by both the update orchestrator and its steps.
 *
 * These live together because they are the handful of things that must give
 * the *same* answer on both sides — the running version, the database version,
 * and what "clear the caches" means.
 */
final class UpdateEnv
{
    /** The version actually on disk, which is what an update moves. */
    public static function currentVersion(string $appRoot): string
    {
        $file = $appRoot . '/VERSION';
        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));
            if ($version !== '') {
                return $version;
            }
        }

        return (string) Config::get('app.version', '0.0.0');
    }

    public static function mysqlVersion(): ?string
    {
        try {
            $version = DB::scalar('SELECT VERSION()');

            return is_string($version) ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Clear every cache that could keep serving pre-update state.
     *
     * opcache is the one that bites: without a reset the old bytecode keeps
     * being served and the update appears not to have happened at all.
     *
     * @return list<string> descriptions of what was cleared
     */
    public static function clearCaches(string $appRoot): array
    {
        $cleared = [];

        if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
            @opcache_reset();
            $cleared[] = 'opcache';
        }

        $removed = 0;
        foreach (glob($appRoot . '/storage/cache/*') ?: [] as $entry) {
            if (is_file($entry) && basename($entry) !== '.gitkeep' && @unlink($entry)) {
                $removed++;
            } elseif (is_dir($entry)) {
                self::removeDirectory($entry);
                $removed++;
            }
        }
        $cleared[] = $removed . ' cache file(s)';

        Setting::flushCache();
        $cleared[] = 'settings cache';

        return $cleared;
    }

    public static function removeDirectory(string $directory): void
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

    public static function humanBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max((float) $bytes, 0);
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }

    /**
     * Rebuild a Manifest from what an update row stored.
     *
     * @param array<string,mixed> $update
     * @param bool $preferStaged use the manifest that shipped with the staged
     *                           files, which is authoritative once STAGE has run
     */
    public static function manifestFor(array $update, bool $preferStaged = false): Manifest
    {
        $stored = (array) ($update['manifest_json'] ?? []);

        if ($preferStaged && isset($stored['staged']) && is_array($stored['staged'])) {
            return Manifest::fromJson((string) json_encode($stored['staged']));
        }

        return Manifest::fromJson((string) json_encode([
            'version'     => $stored['new_version'] ?? ($update['to_version'] ?? '0.0.0'),
            'released_at' => $stored['released_at'] ?? null,
            'breaking'    => $stored['breaking'] ?? false,
            'notes'       => $stored['notes'] ?? '',
            'migrations'  => $stored['migrations'] ?? [],
            'delete'      => $stored['deletions'] ?? [],
            'post_update' => [],
            'checksums'   => [],
        ]));
    }
}
