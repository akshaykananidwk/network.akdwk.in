<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * Tenant API credentials.
 *
 * The plaintext key is shown exactly once, at creation. We store a SHA-256 hash
 * plus a short prefix; the prefix exists only so the UI can say "ak_live_7f3c…"
 * next to a key the user is trying to identify.
 */
final class ApiKey extends Model
{
    protected static string $table = 'api_keys';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['scopes_json'];
    protected static array $sortable = ['name', 'last_used_at', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'user_id', 'name', 'key_prefix', 'key_hash', 'scopes_json', 'expires_at', 'revoked_at',
    ];

    /**
     * @param list<string> $scopes
     * @return array{id:int,plain:string}
     */
    public static function issue(int $tenantId, ?int $userId, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $environment = (string) \App\Core\Config::get('app.env', 'production') === 'production' ? 'live' : 'test';
        $plain = 'ak_' . $environment . '_' . Crypto::randomToken(24);

        $id = self::create([
            'tenant_id'   => $tenantId,
            'user_id'     => $userId,
            'name'        => $name,
            'key_prefix'  => substr($plain, 0, 16),
            'key_hash'    => Crypto::hashToken($plain),
            'scopes_json' => $scopes,
            'expires_at'  => $expiresAt,
        ]);

        return ['id' => $id, 'plain' => $plain];
    }

    /**
     * Authenticate a presented key.
     *
     * Unscoped by necessity: the key itself establishes the tenant. The caller
     * (ApiKeyMiddleware) immediately adopts the row's tenant_id as the scope.
     *
     * @return array<string,mixed>|null
     */
    public static function authenticate(string $plain): ?array
    {
        $hash = Crypto::hashToken($plain);

        return TenantScope::acrossAllTenants('api key authentication', static fn (): ?array => DB::selectOne(
            'SELECT * FROM ' . self::tableName() . '
             WHERE key_hash = :h
               AND revoked_at IS NULL
               AND deleted_at IS NULL
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
             LIMIT 1',
            ['h' => $hash]
        ));
    }

    public static function touch(int $id, string $ip): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET last_used_at = UTC_TIMESTAMP(), last_used_ip = :ip WHERE id = :id',
            ['id' => $id, 'ip' => $ip]
        );
    }

    public static function revoke(int $id): bool
    {
        return self::update($id, ['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }
}
