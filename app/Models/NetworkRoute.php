<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * A subnet reachable through a gateway device (subnet-router mode).
 *
 * Rule R1 lives partly here: validation refuses 0.0.0.0/0, so a default route
 * cannot be advertised into the tunnel even by an administrator who wants one.
 */
final class NetworkRoute extends Model
{
    protected static string $table = 'routes';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['metric', 'destination_cidr', 'id'];
    protected static array $fillable = [
        'tenant_id', 'network_id', 'destination_cidr', 'via_device_id',
        'metric', 'description', 'approved', 'enabled',
    ];

    /** @return list<array<string,mixed>> */
    public static function forNetwork(int $networkId, bool $enabledOnly = true): array
    {
        $sql = 'SELECT r.*, d.name AS via_device_name, d.virtual_ip AS via_device_ip
                FROM ' . self::tableName() . ' r
                LEFT JOIN ' . DB::table('devices') . ' d ON d.id = r.via_device_id
                WHERE r.network_id = :n AND r.deleted_at IS NULL';
        if ($enabledOnly) {
            $sql .= ' AND r.enabled = 1 AND r.approved = 1';
        }
        $sql .= ' ORDER BY r.metric ASC, r.id ASC';

        return DB::select($sql, ['n' => $networkId]);
    }

    /**
     * Routes advertised by one gateway device, used when building its peers'
     * allowed_ips.
     *
     * @return list<string>
     */
    public static function cidrsViaDevice(int $deviceId): array
    {
        $rows = DB::select(
            'SELECT destination_cidr FROM ' . self::tableName() . '
             WHERE via_device_id = :d AND enabled = 1 AND approved = 1 AND deleted_at IS NULL',
            ['d' => $deviceId]
        );

        return array_map(static fn (array $r): string => (string) $r['destination_cidr'], $rows);
    }
}
