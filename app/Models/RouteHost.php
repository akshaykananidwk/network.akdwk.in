<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * A named machine behind a gateway (§18).
 *
 * Not a device: an NVR has no agent, no key and no enrolment. It is an address
 * inside an advertised range, with a label so a technician can type
 * `nvr.hotel-abc.acme.internal` rather than an address the panel invented.
 */
final class RouteHost extends Model
{
    protected static string $table = 'route_hosts';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['label', 'address', 'id'];
    protected static array $fillable = [
        'tenant_id', 'network_id', 'route_id', 'label', 'address', 'description',
    ];

    /**
     * Every named host in a network, with the route each one sits behind.
     *
     * The route is joined rather than looked up per host because building a
     * device's configuration needs both together — the real prefix, the mapped
     * prefix and the gateway — and one query is one query however many cameras
     * a hotel has.
     *
     * @return list<array<string,mixed>>
     */
    public static function forNetwork(int $networkId): array
    {
        return DB::select(
            'SELECT h.*, r.destination_cidr, r.mapped_cidr, r.approved, r.enabled,
                    d.name AS gateway_name, d.virtual_ip AS gateway_ip
             FROM ' . self::tableName() . ' h
             JOIN ' . DB::table('routes') . ' r ON r.id = h.route_id
             LEFT JOIN ' . DB::table('devices') . ' d ON d.id = r.via_device_id
             WHERE h.network_id = :n AND h.deleted_at IS NULL AND r.deleted_at IS NULL
             ORDER BY h.label ASC',
            ['n' => $networkId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forRoute(int $routeId): array
    {
        return DB::select(
            'SELECT * FROM ' . self::tableName() . '
             WHERE route_id = :r AND deleted_at IS NULL
             ORDER BY label ASC',
            ['r' => $routeId]
        );
    }
}
