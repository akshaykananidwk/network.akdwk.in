<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * Signed agent binaries served to devices (§14).
 *
 * Every row carries both a sha256 and an ed25519 signature; the agent verifies
 * both before swapping its binary, so a compromised panel alone is not enough
 * to push code to devices.
 */
final class AgentRelease extends Model
{
    protected static string $table = 'agent_releases';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['version', 'published_at', 'channel'];
    protected static array $fillable = [
        'version', 'channel', 'platform', 'arch', 'file_path', 'file_size',
        'sha256', 'signature', 'release_notes', 'rollout_percent', 'published_at',
    ];

    /**
     * The release a given device should run.
     *
     * Staged rollout is decided by a stable hash of the device uid, so a device
     * does not flip in and out of the rollout cohort between polls.
     *
     * @return array<string,mixed>|null
     */
    public static function latestFor(string $channel, string $platform, string $arch, string $deviceUid = ''): ?array
    {
        // A channel sees itself and everything more stable than it.
        //
        // Without this, a panel on dev would be offered development builds
        // and nothing else — so the moment development stopped it would fall
        // behind the stable release it is supposed to be ahead of. Beta sees
        // beta and stable; stable sees only stable, which is the whole point
        // of stable.
        $visible = match ($channel) {
            'dev'   => ['dev', 'beta', 'stable'],
            'beta'  => ['beta', 'stable'],
            default => ['stable'],
        };

        $placeholders = [];
        $bindings = ['p' => $platform, 'a' => $arch];
        foreach ($visible as $i => $name) {
            $key = 'c' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $name;
        }

        $rows = DB::select(
            'SELECT * FROM ' . self::tableName() . '
             WHERE channel IN (' . implode(', ', $placeholders) . ')
               AND platform = :p AND arch = :a
               AND published_at IS NOT NULL AND published_at <= UTC_TIMESTAMP() AND deleted_at IS NULL
             ORDER BY published_at DESC, id DESC
             LIMIT 10',
            $bindings
        );

        foreach ($rows as $row) {
            $percent = (int) $row['rollout_percent'];
            if ($percent >= 100 || $deviceUid === '') {
                return $row;
            }
            $bucket = (int) (hexdec(substr(hash('sha256', $deviceUid . '|' . $row['version']), 0, 4)) % 100);
            if ($bucket < $percent) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public static function allPublished(): array
    {
        return DB::select(
            'SELECT * FROM ' . self::tableName() . ' WHERE deleted_at IS NULL ORDER BY published_at DESC, id DESC LIMIT 200'
        );
    }
}
