<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * One row per assignable address in a network.
 *
 * Addresses are materialised up front rather than computed on demand: it makes
 * the unique index on (network_id, ip) the authority on "who has 10.50.0.7",
 * which is what stops two devices racing to the same address.
 */
final class IpAllocation extends Model
{
    protected static string $table = 'ip_allocations';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = false;
    protected static array $sortable = ['ip_numeric', 'ip', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'network_id', 'ip', 'ip_numeric', 'device_id', 'reserved', 'label', 'released_at',
    ];

    /** @return array<string,mixed>|null */
    public static function findForDevice(int $deviceId): ?array
    {
        return self::findBy(['device_id' => $deviceId]);
    }

    /** @return array<string,mixed>|null */
    public static function findByIp(int $networkId, string $ip): ?array
    {
        return self::findBy(['network_id' => $networkId, 'ip' => $ip]);
    }

    /**
     * Claim the lowest free address, atomically.
     *
     * The UPDATE ... ORDER BY ... LIMIT 1 form lets MySQL take the row lock and
     * do the assignment in one statement; two concurrent approvals therefore
     * serialise on the row rather than both reading "10.50.0.7 is free".
     *
     * @return string|null the assigned IP, or null when the pool is exhausted
     */
    public static function claimNext(int $networkId, int $deviceId): ?string
    {
        $updated = DB::execute(
            'UPDATE ' . self::tableName() . '
             SET device_id = :dev, released_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE network_id = :net AND device_id IS NULL AND reserved = 0
             ORDER BY ip_numeric ASC
             LIMIT 1',
            ['net' => $networkId, 'dev' => $deviceId]
        )->rowCount();

        if ($updated === 0) {
            return null;
        }

        $ip = DB::scalar(
            'SELECT ip FROM ' . self::tableName() . ' WHERE network_id = :net AND device_id = :dev LIMIT 1',
            ['net' => $networkId, 'dev' => $deviceId]
        );

        return is_string($ip) ? $ip : null;
    }

    /**
     * Assign a specific address; false when it is taken or reserved.
     *
     * The device id is bound twice under two names: PDO with native prepares
     * (which this application uses) rejects a named placeholder that appears
     * more than once in one statement.
     */
    public static function claimSpecific(int $networkId, int $deviceId, string $ip): bool
    {
        return DB::execute(
            'UPDATE ' . self::tableName() . '
             SET device_id = :dev_set, released_at = NULL, updated_at = UTC_TIMESTAMP()
             WHERE network_id = :net AND ip = :ip AND reserved = 0
               AND (device_id IS NULL OR device_id = :dev_match)',
            ['net' => $networkId, 'dev_set' => $deviceId, 'dev_match' => $deviceId, 'ip' => $ip]
        )->rowCount() > 0;
    }

    /** Return a device's address to the pool. */
    public static function release(int $deviceId): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET device_id = NULL, released_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE device_id = :dev',
            ['dev' => $deviceId]
        );
    }

    /** @return array{total:int,used:int,reserved:int,free:int} */
    public static function poolStats(int $networkId): array
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN device_id IS NOT NULL THEN 1 ELSE 0 END) AS used,
                    SUM(CASE WHEN reserved = 1 THEN 1 ELSE 0 END) AS reserved
             FROM ' . self::tableName() . ' WHERE network_id = :net',
            ['net' => $networkId]
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);
        $used = (int) ($row['used'] ?? 0);
        $reserved = (int) ($row['reserved'] ?? 0);

        return [
            'total'    => $total,
            'used'     => $used,
            'reserved' => $reserved,
            'free'     => max(0, $total - $used - $reserved),
        ];
    }

    /**
     * Bulk-insert a network's address pool.
     *
     * Chunked so a /16 (65k rows) does not build one enormous statement.
     *
     * @param list<array{ip:string,num:int}> $addresses
     */
    public static function seedPool(int $tenantId, int $networkId, array $addresses, int $chunkSize = 500): int
    {
        $inserted = 0;
        foreach (array_chunk($addresses, $chunkSize) as $chunk) {
            $values = [];
            $bindings = [];
            foreach ($chunk as $i => $address) {
                $values[] = "(:t{$i}, :n{$i}, :ip{$i}, :num{$i}, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
                $bindings["t{$i}"] = $tenantId;
                $bindings["n{$i}"] = $networkId;
                $bindings["ip{$i}"] = $address['ip'];
                $bindings["num{$i}"] = $address['num'];
            }

            $inserted += DB::execute(
                'INSERT IGNORE INTO ' . self::tableName() . ' (tenant_id, network_id, ip, ip_numeric, created_at, updated_at)
                 VALUES ' . implode(', ', $values),
                $bindings
            )->rowCount();
        }

        return $inserted;
    }
}
