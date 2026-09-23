<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * One reachability test: what was asked, and what came back.
 *
 * Tenant-scoped like everything else that names a customer's equipment. A
 * probe row carries an address on somebody's private network and who asked
 * for it, which is not something one customer may read about another.
 */
final class DeviceProbe extends Model
{
    protected static string $table = 'device_probes';
    protected static bool $tenantScoped = true;

    // Not soft-deleted, and the table has no deleted_at column.
    //
    // Model defaults this to true, so every finder appended
    // "AND deleted_at IS NULL" to a table without that column and the Test
    // button answered 500. A probe is an event — it happened, at a time, with
    // a result — and an event is not something anybody edits or retracts
    // later. Rows age out with the device they belong to, through the
    // foreign key.
    protected static bool $softDeletes = false;
    protected static array $sortable = ['id', 'requested_at'];
    protected static array $fillable = [
        'tenant_id', 'device_id', 'target', 'label', 'state',
        'latency_ms', 'method', 'error', 'requested_by', 'requested_at', 'answered_at',
    ];

    /**
     * The probes a device has been asked for and not yet answered.
     *
     * Ordered oldest first and capped, so a device that was offline while
     * somebody clicked Test twenty times does not come back and run twenty
     * probes at once.
     *
     * @return list<array<string,mixed>>
     */
    public static function pendingFor(int $deviceId, int $limit = 8): array
    {
        return DB::select(
            'SELECT id, target, label FROM ' . self::tableName() . '
             WHERE device_id = :d AND state = :s
             ORDER BY id ASC LIMIT ' . max(1, min($limit, 50)),
            ['d' => $deviceId, 's' => 'pending']
        );
    }

    /**
     * The most recent answer for each target on a device.
     *
     * One row per target, because the page is asking "can it reach this
     * address now" and a list of every attempt answers a different question.
     *
     * @return array<string,array<string,mixed>> keyed by target
     */
    public static function latestByTarget(int $deviceId): array
    {
        $rows = DB::select(
            'SELECT p.* FROM ' . self::tableName() . ' p
             JOIN (
                 SELECT target, MAX(id) AS newest
                 FROM ' . self::tableName() . '
                 WHERE device_id = :d
                 GROUP BY target
             ) newest ON newest.newest = p.id
             WHERE p.device_id = :d2',
            ['d' => $deviceId, 'd2' => $deviceId]
        );

        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[(string) $row['target']] = $row;
        }

        return $byTarget;
    }

    /**
     * Record what the agent found.
     *
     * Scoped to the device as well as the probe id, so an agent cannot answer
     * a probe that was never addressed to it.
     */
    public static function answer(
        int $probeId,
        int $deviceId,
        bool $ok,
        ?int $latencyMs,
        ?string $method,
        ?string $error
    ): void {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET state = :s, latency_ms = :l, method = :m, error = :e,
                 answered_at = UTC_TIMESTAMP()
             WHERE id = :id AND device_id = :d AND state = :pending',
            [
                's'       => $ok ? 'ok' : 'failed',
                'l'       => $latencyMs,
                'm'       => $method !== null ? substr($method, 0, 64) : null,
                'e'       => $error !== null ? substr($error, 0, 255) : null,
                'id'      => $probeId,
                'd'       => $deviceId,
                'pending' => 'pending',
            ]
        );
    }

    /**
     * How many probes this device has been asked for recently.
     *
     * The rate limit. A Test button is one click and a ping sweep is not, and
     * nothing on the panel side should be able to turn a support screen into
     * a way of generating traffic on a customer's network.
     */
    public static function countSince(int $deviceId, int $seconds): int
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS n FROM ' . self::tableName() . '
             WHERE device_id = :d AND requested_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :s SECOND)',
            ['d' => $deviceId, 's' => $seconds]
        );

        return (int) ($rows[0]['n'] ?? 0);
    }
}
