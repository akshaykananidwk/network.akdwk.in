<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Relay fleet. Platform-level: relays are shared across all tenants. */
final class Relay extends Model
{
    protected static string $table = 'relays';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['name', 'region', 'status', 'last_heartbeat_at'];
    protected static array $fillable = [
        'name', 'region', 'host', 'port', 'tcp_port', 'public_key',
        'capacity_mbps', 'current_sessions', 'status',
    ];

    /**
     * Relays handed to an agent. Ordered by region match then load, but the
     * agent makes the final choice by measured RTT — the server cannot know
     * which is closest to a device behind CGNAT.
     *
     * @return list<array<string,mixed>>
     */
    public static function availableFor(string $preferredRegion = ''): array
    {
        return DB::select(
            'SELECT id, name, region, host, port, tcp_port, public_key, capacity_mbps, current_sessions
             FROM ' . self::tableName() . '
             WHERE status = \'active\' AND deleted_at IS NULL
               AND (last_heartbeat_at IS NULL OR last_heartbeat_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND))
             ORDER BY (region = :r) DESC, current_sessions ASC, id ASC
             LIMIT 8',
            ['r' => $preferredRegion]
        );
    }

    public static function heartbeat(int $relayId, int $sessions): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET last_heartbeat_at = UTC_TIMESTAMP(), current_sessions = :s, status = IF(status = \'down\', \'active\', status), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $relayId, 's' => $sessions]
        );
    }

    /** Relays that stopped reporting are marked down so agents stop choosing them. */
    public static function markStaleDown(int $staleSeconds = 180): int
    {
        return DB::execute(
            'UPDATE ' . self::tableName() . '
             SET status = \'down\', updated_at = UTC_TIMESTAMP()
             WHERE status = \'active\' AND deleted_at IS NULL
               AND last_heartbeat_at IS NOT NULL
               AND last_heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :s SECOND)',
            ['s' => $staleSeconds]
        )->rowCount();
    }
}
