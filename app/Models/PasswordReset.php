<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * Single-use, time-limited password reset tokens. Only the hash is stored, so
 * a database leak does not hand out working reset links.
 */
final class PasswordReset extends Model
{
    protected static string $table = 'password_resets';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = false;
    protected static array $fillable = ['user_id', 'token_hash', 'ip', 'expires_at', 'used_at'];

    /** @return string the plaintext token to embed in the emailed link */
    public static function issue(int $userId, string $ip, int $ttlMinutes = 60): string
    {
        // Outstanding tokens are invalidated: requesting a new link must retire
        // the old one, or a stolen earlier email stays usable.
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET used_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL',
            ['u' => $userId]
        );

        $token = Crypto::randomToken(32);
        DB::execute(
            'INSERT INTO ' . self::tableName() . ' (user_id, token_hash, ip, expires_at, created_at, updated_at)
             VALUES (:u, :h, :ip, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :t MINUTE), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['u' => $userId, 'h' => Crypto::hashToken($token), 'ip' => $ip, 't' => $ttlMinutes]
        );

        return $token;
    }

    /** @return array<string,mixed>|null the matching user row, if the token is valid */
    public static function resolve(string $token): ?array
    {
        return TenantScope::acrossAllTenants('password reset', static function () use ($token): ?array {
            $row = DB::selectOne(
                'SELECT r.*, u.email, u.name, u.tenant_id
                 FROM ' . self::tableName() . ' r
                 JOIN ' . DB::table('users') . ' u ON u.id = r.user_id
                 WHERE r.token_hash = :h AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP()
                   AND u.deleted_at IS NULL AND u.status = \'active\'
                 LIMIT 1',
                ['h' => Crypto::hashToken($token)]
            );

            return $row;
        });
    }

    public static function consume(int $id): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET used_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function pruneExpired(): int
    {
        return DB::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)'
        )->rowCount();
    }
}
