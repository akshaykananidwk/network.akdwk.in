<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * Short-lived enrolment code shown on a network page.
 *
 * A code proves "this machine was pointed at this network by someone with
 * access to the panel". It does NOT authorize the device — the row it creates
 * is still `pending` (R4).
 */
final class JoinCode extends Model
{
    protected static string $table = 'join_codes';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = false;
    protected static array $fillable = [
        'tenant_id', 'network_id', 'code', 'max_uses', 'uses', 'expires_at', 'revoked_at', 'created_by',
    ];

    /** @return array{id:int,code:string,expires_at:string} */
    public static function issue(int $tenantId, int $networkId, ?int $userId, int $maxUses = 0, ?int $ttlMinutes = null): array
    {
        $ttl = $ttlMinutes ?? (int) Config::get('network.join_code_ttl_min', 60);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttl * 60));

        do {
            $code = Crypto::randomCode(12);
        } while (DB::scalar('SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE code = :c', ['c' => $code]) > 0);

        $id = self::create([
            'tenant_id'  => $tenantId,
            'network_id' => $networkId,
            'code'       => $code,
            'max_uses'   => $maxUses,   // 0 = unlimited within the TTL
            'expires_at' => $expiresAt,
            'created_by' => $userId,
        ]);

        return ['id' => $id, 'code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Redeem a code presented by an enrolling agent.
     *
     * Unscoped: the agent has no identity yet, and the code is what establishes
     * which tenant it belongs to.
     *
     * @return array<string,mixed>|null
     */
    public static function redeem(string $code): ?array
    {
        return TenantScope::acrossAllTenants('enrolment code redemption', static function () use ($code): ?array {
            $normalised = strtoupper(trim($code));

            // Claim the use first, in one statement, and read the row only if
            // the claim succeeded. Selecting and then incrementing left a
            // window where two devices enrolling at the same moment both saw
            // the same remaining use and both took it — which was academic
            // when devices trickled in one at a time, and is not when fifty
            // machines in one office enrol together.
            $claimed = DB::execute(
                'UPDATE ' . self::tableName() . '
                 SET uses = uses + 1, updated_at = UTC_TIMESTAMP()
                 WHERE code = :c AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
                   AND (max_uses = 0 OR uses < max_uses)',
                ['c' => $normalised]
            );

            if ($claimed === 0) {
                return null;
            }

            return DB::selectOne(
                'SELECT * FROM ' . self::tableName() . ' WHERE code = :c LIMIT 1',
                ['c' => $normalised]
            );
        });
    }

    /** @return array<string,mixed>|null the newest usable code for a network */
    public static function activeForNetwork(int $networkId): ?array
    {
        $rows = DB::select(
            'SELECT * FROM ' . self::tableName() . '
             WHERE network_id = :n AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
               AND (max_uses = 0 OR uses < max_uses)
             ORDER BY id DESC LIMIT 1',
            ['n' => $networkId]
        );

        return $rows[0] ?? null;
    }

    public static function revokeAllForNetwork(int $networkId): int
    {
        return DB::execute(
            'UPDATE ' . self::tableName() . ' SET revoked_at = UTC_TIMESTAMP() WHERE network_id = :n AND revoked_at IS NULL',
            ['n' => $networkId]
        )->rowCount();
    }

    public static function pruneExpired(): int
    {
        return TenantScope::acrossAllTenants('join code pruning', static fn (): int => DB::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)'
        )->rowCount());
    }
}
