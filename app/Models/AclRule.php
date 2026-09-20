<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class AclRule extends Model
{
    protected static string $table = 'acl_rules';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['priority', 'id', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'network_id', 'priority', 'description',
        'src_type', 'src_value', 'dst_type', 'dst_value',
        'protocol', 'port_from', 'port_to', 'action', 'enabled',
    ];

    /**
     * Rules in evaluation order: lowest priority number wins, ties broken by
     * insertion order so the list is stable across reloads.
     *
     * @return list<array<string,mixed>>
     */
    public static function forNetwork(int $networkId, bool $enabledOnly = true): array
    {
        $sql = 'SELECT * FROM ' . self::tableName() . ' WHERE network_id = :n AND deleted_at IS NULL';
        if ($enabledOnly) {
            $sql .= ' AND enabled = 1';
        }
        $sql .= ' ORDER BY priority ASC, id ASC';

        return DB::select($sql, ['n' => $networkId]);
    }

    public static function nextPriority(int $networkId): int
    {
        $max = DB::scalar(
            'SELECT MAX(priority) FROM ' . self::tableName() . ' WHERE network_id = :n AND deleted_at IS NULL',
            ['n' => $networkId]
        );

        return $max === null ? 100 : ((int) $max + 10);
    }
}
