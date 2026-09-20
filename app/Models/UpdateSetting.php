<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Logger;

/**
 * Single-row configuration for the GitHub updater (§9.1).
 *
 * The access token is the sensitive part. It is stored AES-256-GCM encrypted,
 * is never included in toArray(), and the only accessor that returns plaintext
 * is token(), which exists solely for GithubClient.
 */
final class UpdateSetting extends Model
{
    protected static string $table = 'update_settings';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = false;
    protected static array $jsonColumns = ['protected_json', 'maintenance_allowlist_json'];
    protected static array $fillable = [
        'repo_owner', 'repo_name', 'branch', 'token_encrypted', 'channel',
        'auto_check', 'auto_apply', 'check_interval_hours', 'last_checked_at',
        'last_available_version', 'last_available_commit', 'current_commit',
        'verify_signature', 'protected_json', 'backup_retention', 'maintenance_allowlist_json',
    ];

    public const DEFAULT_PROTECTED = [
        '.env',
        'config/config.php',
        'uploads/',
        'storage/',
        'install/install.lock',
        '*.local.php',
    ];

    /** @return array<string,mixed> the row, creating it if the table is empty */
    public static function current(): array
    {
        $row = DB::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE id = 1');
        if ($row === null) {
            DB::execute(
                'INSERT INTO ' . self::tableName() . ' (id, branch, channel, protected_json, created_at, updated_at)
                 VALUES (1, :b, :c, :p, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                ['b' => 'main', 'c' => 'stable', 'p' => json_encode(self::DEFAULT_PROTECTED)]
            );
            $row = DB::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE id = 1') ?? [];
        }

        foreach (['protected_json', 'maintenance_allowlist_json'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }

    /**
     * Safe projection for the settings screen and the API.
     *
     * The token appears only as a mask — never the ciphertext, never the
     * plaintext. A caller that wants the real value must ask for token().
     *
     * @return array<string,mixed>
     */
    public static function toArray(): array
    {
        $row = self::current();
        $token = self::token();

        return [
            'repo_owner'           => $row['repo_owner'],
            'repo_name'            => $row['repo_name'],
            'branch'               => $row['branch'],
            'channel'              => $row['channel'],
            'auto_check'           => (bool) $row['auto_check'],
            'auto_apply'           => (bool) $row['auto_apply'],
            'check_interval_hours' => (int) $row['check_interval_hours'],
            'last_checked_at'      => $row['last_checked_at'],
            'current_commit'       => $row['current_commit'],
            'verify_signature'     => (bool) $row['verify_signature'],
            'protected'            => $row['protected_json'] ?? self::DEFAULT_PROTECTED,
            'backup_retention'     => (int) $row['backup_retention'],
            'maintenance_allowlist' => $row['maintenance_allowlist_json'] ?? [],
            'token_set'            => $token !== null && $token !== '',
            'token_masked'         => $token !== null && $token !== '' ? Logger::mask($token) : '',
        ];
    }

    /** @param array<string,mixed> $attributes */
    public static function save(array $attributes): void
    {
        self::current(); // ensure the row exists

        $data = array_intersect_key($attributes, array_flip(self::$fillable));
        if ($data === []) {
            return;
        }

        foreach (['protected_json', 'maintenance_allowlist_json'] as $column) {
            if (isset($data[$column]) && is_array($data[$column])) {
                $data[$column] = json_encode(array_values($data[$column]), JSON_UNESCAPED_SLASHES);
            }
        }

        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = '`' . $column . '` = :' . $column;
        }

        DB::execute(
            'UPDATE ' . self::tableName() . ' SET ' . implode(', ', $assignments) . ', updated_at = UTC_TIMESTAMP() WHERE id = 1',
            $data
        );
    }

    /**
     * Store a new token. Passing an empty string clears it; passing null (the
     * "unchanged" case for a write-only form field) leaves it alone.
     */
    public static function setToken(?string $plain): void
    {
        if ($plain === null) {
            return;
        }
        self::save(['token_encrypted' => $plain === '' ? null : Crypto::encrypt($plain)]);
    }

    /** @return string|null plaintext token, for GithubClient only */
    public static function token(): ?string
    {
        $row = self::current();
        $encrypted = $row['token_encrypted'] ?? null;
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $plain = Crypto::decrypt($encrypted);
        if ($plain === null) {
            // Typically means APP_KEY changed; say so rather than failing with
            // an opaque 401 from GitHub later.
            Logger::error('update', 'Stored GitHub token could not be decrypted — has APP_KEY changed?');
        }

        return $plain;
    }

    public static function isConfigured(): bool
    {
        $row = self::current();

        return !empty($row['repo_owner']) && !empty($row['repo_name']) && self::token() !== null;
    }

    /** @return list<string> */
    public static function protectedPaths(): array
    {
        $row = self::current();
        $paths = $row['protected_json'] ?? null;
        $paths = is_array($paths) && $paths !== [] ? $paths : self::DEFAULT_PROTECTED;

        // The defaults are non-negotiable: an operator may add to the list but
        // never remove config/config.php or storage/ from it.
        return array_values(array_unique(array_merge(self::DEFAULT_PROTECTED, array_map('strval', $paths))));
    }

    public static function markChecked(?string $version, ?string $commit): void
    {
        self::save([
            'last_checked_at'        => gmdate('Y-m-d H:i:s'),
            'last_available_version' => $version,
            'last_available_commit'  => $commit,
        ]);
    }

    public static function setCurrentCommit(string $commit): void
    {
        self::save(['current_commit' => $commit]);
    }
}
