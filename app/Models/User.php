<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Rbac;
use App\Middleware\TenantScope;

/**
 * Panel users. tenant_id NULL identifies a platform (super admin) account.
 *
 * Lookups by email deliberately bypass the tenant scope — email is globally
 * unique and login happens before any tenant is known — so every such method
 * is explicit about it and the result is re-scoped by the caller.
 */
final class User extends Model
{
    protected static string $table = 'users';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['twofa_recovery_json'];
    protected static array $sortable = ['id', 'name', 'email', 'role', 'last_login_at', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'name', 'email', 'password_hash', 'role', 'status',
        'twofa_secret', 'twofa_enabled', 'twofa_recovery_json', 'timezone', 'locale',
    ];

    /**
     * Login lookup. Unscoped by necessity — we do not know the tenant yet.
     *
     * @return array<string,mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return TenantScope::acrossAllTenants('login by email', static function () use ($email): ?array {
            $row = DB::selectOne(
                'SELECT * FROM ' . self::tableName() . ' WHERE email = :email AND deleted_at IS NULL LIMIT 1',
                ['email' => strtolower(trim($email))]
            );

            return $row === null ? null : self::hydrateRow($row);
        });
    }

    /**
     * Re-resolve the session's user on each request. Unscoped because the scope
     * is derived *from* this row.
     *
     * @return array<string,mixed>|null
     */
    public static function findActiveById(int $id): ?array
    {
        return TenantScope::acrossAllTenants('session user resolution', static function () use ($id): ?array {
            $row = DB::selectOne(
                'SELECT * FROM ' . self::tableName() . ' WHERE id = :id AND deleted_at IS NULL AND status = \'active\' LIMIT 1',
                ['id' => $id]
            );

            return $row === null ? null : self::hydrateRow($row);
        });
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        return TenantScope::acrossAllTenants('email uniqueness check', static function () use ($email, $exceptId): bool {
            $sql = 'SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE email = :email AND deleted_at IS NULL';
            $params = ['email' => strtolower(trim($email))];
            if ($exceptId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $exceptId;
            }

            return (int) DB::scalar($sql, $params) > 0;
        });
    }

    public static function recordLogin(int $id, string $ip): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip,
                 failed_attempts = 0, locked_until = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'ip' => $ip]
        );
    }

    /** @return int the new failure count */
    public static function incrementFailedAttempts(int $id): int
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET failed_attempts = failed_attempts + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );

        return (int) DB::scalar('SELECT failed_attempts FROM ' . self::tableName() . ' WHERE id = :id', ['id' => $id]);
    }

    public static function clearFailedAttempts(int $id): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET failed_attempts = 0, locked_until = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function lock(int $id, int $seconds): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :s SECOND), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 's' => $seconds]
        );
    }

    public static function updatePasswordHash(int $id, string $hash): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET password_hash = :h, password_changed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'h' => $hash]
        );
    }

    public static function setPassword(int $id, string $plain): void
    {
        self::updatePasswordHash($id, Crypto::hashPassword($plain));
    }

    /** @param list<string> $recoveryHashes */
    public static function enableTwoFactor(int $id, string $encryptedSecret, array $recoveryHashes): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET twofa_secret = :s, twofa_enabled = 1, twofa_recovery_json = :r, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 's' => $encryptedSecret, 'r' => json_encode($recoveryHashes)]
        );
    }

    public static function disableTwoFactor(int $id): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET twofa_secret = NULL, twofa_enabled = 0, twofa_recovery_json = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id]
        );
    }

    /** @param list<string> $remainingHashes */
    public static function replaceRecoveryCodes(int $id, array $remainingHashes): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET twofa_recovery_json = :r, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id, 'r' => json_encode($remainingHashes)]
        );
    }

    /**
     * Super admins receive platform alerts (update failed, backup failed).
     *
     * @return list<array<string,mixed>>
     */
    public static function superAdmins(): array
    {
        return TenantScope::acrossAllTenants('platform alert recipients', static fn (): array => DB::select(
            'SELECT * FROM ' . self::tableName() . '
             WHERE role = :role AND tenant_id IS NULL AND status = \'active\' AND deleted_at IS NULL',
            ['role' => Rbac::SUPER_ADMIN]
        ));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateRow(array $row): array
    {
        if (isset($row['twofa_recovery_json']) && is_string($row['twofa_recovery_json'])) {
            $decoded = json_decode($row['twofa_recovery_json'], true);
            $row['twofa_recovery_json'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }
}
