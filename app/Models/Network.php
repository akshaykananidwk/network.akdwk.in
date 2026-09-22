<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/**
 * An overlay network: one CIDR, one peer group, one ACL set.
 */
final class Network extends Model
{
    protected static string $table = 'networks';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['dns_json'];
    protected static array $sortable = ['id', 'name', 'cidr', 'status', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'name', 'network_uid', 'description', 'cidr', 'mapped_pool', 'dns_json',
        'search_domain', 'mtu', 'keepalive_seconds', 'auto_assign_ip',
        'auto_approve_devices', 'private', 'acl_default_action', 'status', 'created_by',
    ];

    /** 16 hex chars, globally unique — this is what an agent quotes in support. */
    public static function generateUid(): string
    {
        do {
            $uid = Crypto::randomHex(8);
        } while (DB::scalar('SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE network_uid = :u', ['u' => $uid]) > 0);

        return $uid;
    }

    /** @return array<string,mixed>|null */
    public static function findByUid(string $uid): ?array
    {
        return self::findBy(['network_uid' => $uid]);
    }

    /**
     * Bump the revision so agents polling /agent/config know to re-read.
     *
     * Every change that affects what an agent should do — peers, ACL, routes,
     * DNS — calls this. The agent compares revisions rather than diffing config.
     */
    public static function bumpRevision(int $networkId): int
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET config_revision = config_revision + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $networkId]
        );

        return (int) DB::scalar('SELECT config_revision FROM ' . self::tableName() . ' WHERE id = :id', ['id' => $networkId]);
    }

    /**
     * Device counts for the network list, in one query rather than N.
     *
     * @param list<int> $networkIds
     * @return array<int,array{total:int,online:int,pending:int}>
     */
    public static function deviceCounts(array $networkIds): array
    {
        if ($networkIds === []) {
            return [];
        }
        $placeholders = [];
        $bindings = [];
        foreach (array_values($networkIds) as $i => $id) {
            $placeholders[] = ':n' . $i;
            $bindings['n' . $i] = $id;
        }

        $rows = DB::select(
            'SELECT network_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN last_seen_at IS NOT NULL
                              AND last_seen_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                                  . Device::OFFLINE_AFTER_SECONDS . ' SECOND)
                             THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending
             FROM ' . DB::table('devices') . '
             WHERE network_id IN (' . implode(', ', $placeholders) . ') AND deleted_at IS NULL
             GROUP BY network_id',
            $bindings
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['network_id']] = [
                'total'   => (int) $row['total'],
                'online'  => (int) $row['online'],
                'pending' => (int) $row['pending'],
            ];
        }

        return $out;
    }
}
