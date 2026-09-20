<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * Key/value settings. tenant_id NULL = platform setting.
 *
 * Reads are memoised per request; a write busts the cache for that key only.
 */
final class Setting extends Model
{
    protected static string $table = 'settings';
    protected static bool $tenantScoped = false; // scoping is explicit per call
    protected static bool $softDeletes = false;
    protected static array $fillable = ['tenant_id', 'key_name', 'value_text', 'is_encrypted'];

    /** @var array<string,string|null> */
    private static array $cache = [];

    public static function get(string $key, ?int $tenantId = null, ?string $default = null): ?string
    {
        $cacheKey = ($tenantId ?? 0) . ':' . $key;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey] ?? $default;
        }

        $row = DB::selectOne(
            'SELECT value_text, is_encrypted FROM ' . self::tableName() . '
             WHERE key_name = :k AND ' . ($tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :t') . ' LIMIT 1',
            $tenantId === null ? ['k' => $key] : ['k' => $key, 't' => $tenantId]
        );

        if ($row === null) {
            self::$cache[$cacheKey] = null;

            return $default;
        }

        $value = (string) ($row['value_text'] ?? '');
        if ((int) $row['is_encrypted'] === 1) {
            $value = Crypto::decrypt($value) ?? '';
        }
        self::$cache[$cacheKey] = $value;

        return $value;
    }

    public static function getInt(string $key, ?int $tenantId = null, int $default = 0): int
    {
        $value = self::get($key, $tenantId);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function getBool(string $key, ?int $tenantId = null, bool $default = false): bool
    {
        $value = self::get($key, $tenantId);

        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, ?string $value, ?int $tenantId = null, bool $encrypt = false): void
    {
        $stored = $encrypt && $value !== null && $value !== '' ? Crypto::encrypt($value) : $value;

        DB::execute(
            'INSERT INTO ' . self::tableName() . ' (tenant_id, key_name, value_text, is_encrypted, created_at, updated_at)
             VALUES (:t, :k, :v, :enc, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), is_encrypted = VALUES(is_encrypted), updated_at = UTC_TIMESTAMP()',
            ['t' => $tenantId, 'k' => $key, 'v' => $stored, 'enc' => $encrypt ? 1 : 0]
        );

        unset(self::$cache[($tenantId ?? 0) . ':' . $key]);
    }

    /**
     * All settings under a prefix.
     *
     * @return array<string,string>
     */
    public static function withPrefix(string $prefix, ?int $tenantId = null): array
    {
        $rows = DB::select(
            'SELECT key_name, value_text, is_encrypted FROM ' . self::tableName() . '
             WHERE key_name LIKE :p AND ' . ($tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :t'),
            $tenantId === null
                ? ['p' => addcslashes($prefix, '%_\\') . '%']
                : ['p' => addcslashes($prefix, '%_\\') . '%', 't' => $tenantId]
        );

        $out = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value_text'] ?? '');
            $out[(string) $row['key_name']] = (int) $row['is_encrypted'] === 1 ? (Crypto::decrypt($value) ?? '') : $value;
        }

        return $out;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
