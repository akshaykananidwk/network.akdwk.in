<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * How one device reaches one peer, as the device last said.
 *
 * Written from the heartbeat, which has carried the per-peer path since 1.9.2
 * and was ignored. The device's own status is Online or Offline and comes from
 * the heartbeat alone; this is the other, separate fact — "direct" or "via
 * server" — shown beside it per pair.
 */
final class DeviceLink extends Model
{
    protected static string $table = 'device_links';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = false;
    protected static array $sortable = ['id', 'updated_at'];
    protected static array $fillable = [
        'tenant_id', 'device_id', 'peer_device_id', 'path', 'transport', 'latency_ms', 'since_at', 'updated_at',
    ];

    /**
     * A link not re-reported for this long is not shown: the device stopped
     * mentioning that peer (it went away, or the device stopped heartbeating).
     */
    public const FRESH_SECONDS = 120;

    /**
     * The paths an agent reports, in the words the panel uses.
     *
     * "relay-udp", "relay-https" and "hub" all carry the pair through our
     * server; the difference between them is how, which is `transport`.
     */
    public static function normalise(string $agentPath): array
    {
        return match ($agentPath) {
            'direct'      => ['direct', 'udp'],
            'relay-udp'   => ['server', 'udp'],
            'relay-https' => ['server', 'https'],
            'hub'         => ['server', 'udp'],
            'hub-https'   => ['server', 'https'],
            default       => ['none', null],
        };
    }

    /**
     * Record what a device said about its peers.
     *
     * Peers are named by device uid and resolved inside the device's own
     * network and tenant, so a device cannot write a link for a machine that
     * is not its peer. One statement, whatever the number of peers.
     *
     * @param list<array{uid:string,path:string,latency_ms:?int}> $links
     */
    public static function record(int $tenantId, int $deviceId, int $networkId, array $links): void
    {
        if ($links === []) {
            return;
        }

        $byUid = [];
        foreach ($links as $link) {
            $byUid[$link['uid']] = $link;
        }

        $placeholders = [];
        $bindings = ['t' => $tenantId, 'n' => $networkId, 'self' => $deviceId];
        foreach (array_keys($byUid) as $i => $uid) {
            $placeholders[] = ':u' . $i;
            $bindings['u' . $i] = $uid;
        }

        $peers = DB::select(
            'SELECT id, device_uid FROM ' . DB::table('devices') . '
             WHERE tenant_id = :t AND network_id = :n AND id <> :self AND deleted_at IS NULL
               AND device_uid IN (' . implode(', ', $placeholders) . ')',
            $bindings
        );

        if ($peers === []) {
            return;
        }

        $rows = [];
        $values = [];
        foreach ($peers as $i => $peer) {
            $link = $byUid[(string) $peer['device_uid']];
            [$path, $transport] = self::normalise($link['path']);

            $rows[] = '(:t' . $i . ', :d' . $i . ', :p' . $i . ', :path' . $i . ', :tr' . $i . ', :lat' . $i
                . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())';
            $values += [
                't' . $i    => $tenantId,
                'd' . $i    => $deviceId,
                'p' . $i    => (int) $peer['id'],
                'path' . $i => $path,
                'tr' . $i   => $transport,
                'lat' . $i  => $link['latency_ms'],
            ];
        }

        // since_at is assigned first, deliberately: MySQL evaluates the
        // assignments left to right, so `path` and `updated_at` in the IF are
        // still the old row's. It moves when the path changed, and when the
        // row had gone stale — a pair that was down all night and comes back
        // on the same path has not been on it "since yesterday".
        DB::execute(
            'INSERT INTO ' . self::tableName() . '
                (tenant_id, device_id, peer_device_id, path, transport, latency_ms, since_at, updated_at)
             VALUES ' . implode(', ', $rows) . '
             ON DUPLICATE KEY UPDATE
                since_at = IF(path <> VALUES(path)
                    OR updated_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::FRESH_SECONDS . ' SECOND),
                    UTC_TIMESTAMP(), since_at),
                path = VALUES(path),
                transport = VALUES(transport),
                latency_ms = VALUES(latency_ms),
                updated_at = UTC_TIMESTAMP()',
            $values
        );
    }

    /**
     * One device's links, fresh ones only, keyed by the peer's device id.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forDevice(int $tenantId, int $deviceId): array
    {
        $rows = DB::select(
            'SELECT * FROM ' . self::tableName() . '
             WHERE tenant_id = :t AND device_id = :d
               AND updated_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::FRESH_SECONDS . ' SECOND)',
            ['t' => $tenantId, 'd' => $deviceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['peer_device_id']] = $row;
        }

        return $out;
    }

    /**
     * How many of each device's peers are reached directly and how many
     * through the server, for the list views. One query for the whole page.
     *
     * @param list<int> $deviceIds
     * @return array<int,array{direct:int,server:int}>
     */
    public static function summaries(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach (array_values(array_unique($deviceIds)) as $i => $id) {
            $placeholders[] = ':d' . $i;
            $bindings['d' . $i] = (int) $id;
        }

        $rows = DB::select(
            'SELECT device_id,
                    SUM(path = \'direct\') AS direct,
                    SUM(path = \'server\') AS server
             FROM ' . self::tableName() . '
             WHERE device_id IN (' . implode(', ', $placeholders) . ')
               AND updated_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::FRESH_SECONDS . ' SECOND)
             GROUP BY device_id',
            $bindings
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['device_id']] = ['direct' => (int) $row['direct'], 'server' => (int) $row['server']];
        }

        return $out;
    }
}
