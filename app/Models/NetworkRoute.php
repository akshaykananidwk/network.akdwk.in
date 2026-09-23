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
        'tenant_id', 'network_id', 'destination_cidr', 'mapped_cidr', 'via_device_id',
        'metric', 'description', 'approved', 'enabled',
    ];

    /** @return list<array<string,mixed>> */
    public static function forNetwork(int $networkId, bool $enabledOnly = true): array
    {
        // The uid and the deletion state come with the name, because the name
        // alone is not an identity: a PC reinstalled and re-enrolled has the
        // same hostname as the row it replaced, so "already advertised
        // through DESKTOP-EKH1Q30" named two different devices at once and
        // sent somebody looking for a route they could not see.
        $sql = 'SELECT r.*, d.name AS via_device_name, d.virtual_ip AS via_device_ip,
                       d.device_uid AS via_device_uid, d.deleted_at AS via_device_deleted_at,
                       d.status AS via_device_status
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
     * The **mapped** prefix, because that is the address space the overlay
     * uses. The customer's real range never appears on the wire between two
     * devices: it exists on the gateway's own LAN and nowhere else.
     *
     * @return list<string>
     */
    public static function cidrsViaDevice(int $deviceId): array
    {
        $rows = DB::select(
            'SELECT destination_cidr, mapped_cidr FROM ' . self::tableName() . '
             WHERE via_device_id = :d AND enabled = 1 AND approved = 1 AND deleted_at IS NULL',
            ['d' => $deviceId]
        );

        return array_map(
            static fn (array $r): string => (string) ($r['mapped_cidr'] ?: $r['destination_cidr']),
            $rows
        );
    }

    /**
     * The routes one gateway device carries, real prefix and mapped prefix
     * together.
     *
     * Both, because the gateway is the one place that needs each: the real
     * range to forward into and NAT for, the mapped range to recognise on the
     * tunnel and to compile rules against.
     *
     * @return list<array<string,mixed>>
     */
    public static function servedByDevice(int $deviceId): array
    {
        return DB::select(
            'SELECT destination_cidr, mapped_cidr FROM ' . self::tableName() . '
             WHERE via_device_id = :d AND enabled = 1 AND approved = 1 AND deleted_at IS NULL
             ORDER BY id ASC',
            ['d' => $deviceId]
        );
    }

    /**
     * Mapped prefixes already in use in one network.
     *
     * Deleted and unapproved routes count: a prefix handed to a route that is
     * waiting for approval must not be handed to a second one, and a prefix
     * freed by a deletion is better left alone than reissued to a different
     * LAN while agents may still hold the old configuration.
     *
     * @return list<string>
     */
    public static function mappedInNetwork(int $networkId): array
    {
        $rows = DB::select(
            'SELECT mapped_cidr FROM ' . self::tableName() . '
             WHERE network_id = :n AND mapped_cidr IS NOT NULL',
            ['n' => $networkId]
        );

        return array_map(static fn (array $r): string => (string) $r['mapped_cidr'], $rows);
    }
}
