<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * An enrolled machine.
 *
 * Rule R4: a new row is always `pending` with no virtual_ip and no token — the
 * agent that created it receives nothing it could use to join until an admin
 * approves. Rule R5: `public_key` is the only key material stored.
 */
final class Device extends Model
{
    protected static string $table = 'devices';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['tags_json'];
    protected static array $sortable = ['id', 'name', 'status', 'last_seen_at', 'virtual_ip', 'created_at', 'agent_version'];
    protected static array $fillable = [
        'tenant_id', 'network_id', 'device_uid', 'name', 'hostname', 'os', 'os_version',
        'arch', 'agent_version', 'public_key', 'hw_fingerprint', 'virtual_ip', 'status',
        'approved_by', 'approved_at', 'revoked_at', 'connection_type', 'relay_id',
        'latency_ms', 'tags_json', 'is_gateway',
    ];

    public static function generateUid(): string
    {
        do {
            $uid = 'dev_' . Crypto::randomHex(10);
        } while (DB::scalar('SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE device_uid = :u', ['u' => $uid]) > 0);

        return $uid;
    }

    /** @return array<string,mixed>|null */
    public static function findByUid(string $uid): ?array
    {
        return self::findBy(['device_uid' => $uid]);
    }

    /**
     * Resolve a device from its bearer token.
     *
     * Unscoped by necessity — the agent has no session and no tenant header —
     * so the caller must adopt the returned tenant_id as its scope, which
     * ApiKeyMiddleware::authenticateDevice() does.
     *
     * @return array<string,mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        $hash = Crypto::hashToken($token);

        return TenantScope::acrossAllTenants('agent token authentication', static function () use ($hash): ?array {
            $row = DB::selectOne(
                'SELECT * FROM ' . self::tableName() . '
                 WHERE token_hash = :h AND status = \'authorized\' AND deleted_at IS NULL LIMIT 1',
                ['h' => $hash]
            );

            return $row === null ? null : $row;
        });
    }

    /**
     * An agent re-running enrolment with the same key must find its existing
     * row rather than creating a duplicate; the public key is globally unique.
     *
     * @return array<string,mixed>|null
     */
    public static function findByPublicKey(string $publicKey): ?array
    {
        return TenantScope::acrossAllTenants('enrolment idempotency check', static fn (): ?array => DB::selectOne(
            'SELECT * FROM ' . self::tableName() . ' WHERE public_key = :k AND deleted_at IS NULL LIMIT 1',
            ['k' => $publicKey]
        ));
    }

    /** @return string the plaintext token — stored only as a hash */
    public static function issueToken(int $deviceId): string
    {
        $token = Crypto::randomToken(32);
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET token_hash = :h, token_rotated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $deviceId, 'h' => Crypto::hashToken($token)]
        );

        return $token;
    }

    public static function clearToken(int $deviceId): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET token_hash = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $deviceId]
        );
    }

    /**
     * Heartbeat write.
     *
     * Deliberately a single UPDATE with no surrounding SELECT: at 10k devices
     * this is the hottest write in the system (§12). Byte counters are deltas
     * applied in place so two heartbeats cannot lose each other's increment.
     */
    public static function heartbeat(
        int $deviceId,
        string $endpoint,
        ?string $lanEndpoint,
        string $connectionType,
        ?int $relayId,
        ?int $latencyMs,
        int $rxDelta,
        int $txDelta,
        ?string $agentVersion
    ): void {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET last_seen_at = UTC_TIMESTAMP(),
                 last_endpoint = :ep,
                 last_lan_endpoint = :lan,
                 connection_type = :ct,
                 relay_id = :relay,
                 latency_ms = :lat,
                 rx_bytes = rx_bytes + :rx,
                 tx_bytes = tx_bytes + :tx,
                 agent_version = COALESCE(:av, agent_version),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id'    => $deviceId,
                'ep'    => substr($endpoint, 0, 64),
                'lan'   => $lanEndpoint !== null ? substr($lanEndpoint, 0, 64) : null,
                'ct'    => $connectionType,
                'relay' => $relayId,
                'lat'   => $latencyMs,
                'rx'    => max(0, $rxDelta),
                'tx'    => max(0, $txDelta),
                'av'    => $agentVersion,
            ]
        );
    }

    /**
     * Peers an authorized device may talk to: same network, authorized, not
     * itself. ACL narrowing happens on top of this in AclService.
     *
     * @return list<array<string,mixed>>
     */
    public static function peersFor(int $networkId, int $exceptDeviceId): array
    {
        return DB::select(
            'SELECT id, device_uid, name, public_key, virtual_ip, last_endpoint, last_lan_endpoint,
                    connection_type, is_gateway, tags_json, last_seen_at
             FROM ' . self::tableName() . '
             WHERE network_id = :n AND id <> :me AND status = \'authorized\' AND deleted_at IS NULL',
            ['n' => $networkId, 'me' => $exceptDeviceId]
        );
    }

    /**
     * Record what a device says it could not do.
     *
     * Replaced wholesale on every heartbeat rather than appended to, because
     * the agent reports its *current* problems: one that has been fixed stops
     * being sent, and a list that only grew would need somebody to clear it by
     * hand.
     *
     * @param list<array{code: string, detail: string}> $problems
     */
    public static function recordProblems(int $deviceId, array $problems): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET problems_json = :p, problems_at = :at
             WHERE id = :id',
            [
                'p'  => $problems === [] ? null : json_encode(array_values($problems)),
                'at' => $problems === [] ? null : gmdate('Y-m-d H:i:s'),
                'id' => $deviceId,
            ]
        );

        // Kept after it clears, because problems_json holds what is wrong NOW
        // and empties when it stops. The fault that was happening an hour ago,
        // when the customer rang, otherwise leaves no trace by the time
        // anybody looks at the page.
        if ($problems !== []) {
            $first = $problems[array_key_first($problems)];
            $detail = is_array($first) ? (string) ($first['detail'] ?? '') : '';

            if ($detail !== '') {
                DB::execute(
                    'UPDATE ' . self::tableName() . '
                     SET last_error = :e, last_error_at = :at
                     WHERE id = :id',
                    [
                        'e'  => mb_substr($detail, 0, 500),
                        'at' => gmdate('Y-m-d H:i:s'),
                        'id' => $deviceId,
                    ]
                );
            }
        }
    }

    /**
     * Ask a device to check for an update now, instead of in six hours.
     *
     * Recorded rather than pushed. The agent asks the panel for its
     * configuration on a short cycle and carries the request back on that —
     * there is no channel from here to a PC behind a shop router, and
     * inventing one would mean the panel could reach into customer machines.
     */
    public static function requestUpdate(int $deviceId): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET update_requested_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $deviceId]
        );
    }

    /**
     * Whether a request is outstanding, WITHOUT consuming it.
     *
     * The configuration endpoint answers "nothing has changed" whenever the
     * agent's revision matches the network's, and it builds no configuration
     * on that path. A request recorded here is not a change to the network, so
     * without this the agent would be told nothing had changed and the
     * administrator's click would sit unseen until something else moved.
     *
     * Not the same call as updateRequested(), which clears as it reads:
     * clearing on the not-changed path would throw the request away and send
     * nothing.
     */
    public static function hasUpdateRequest(int $deviceId): bool
    {
        $at = DB::scalar(
            'SELECT update_requested_at FROM ' . self::tableName() . ' WHERE id = :id',
            ['id' => $deviceId]
        );

        return $at !== null && $at !== '';
    }

    /**
     * Whether a request is outstanding, and consuming it if so.
     *
     * Cleared as it is read, because the alternative is an agent that checks
     * for an update on every configuration poll for ever after one click.
     * A request that is lost — the configuration fetched by an agent that then
     * died — costs the administrator one more click, which is the right way
     * for this to fail.
     */
    public static function updateRequested(int $deviceId): bool
    {
        $at = DB::scalar(
            'SELECT update_requested_at FROM ' . self::tableName() . ' WHERE id = :id',
            ['id' => $deviceId]
        );

        if ($at === null || $at === '') {
            return false;
        }

        DB::execute(
            'UPDATE ' . self::tableName() . ' SET update_requested_at = NULL WHERE id = :id',
            ['id' => $deviceId]
        );

        return true;
    }

    /**
     * When the agent process came up, as the agent reports it.
     *
     * "Has it restarted?" is where a support call starts, and a device that
     * reboots nightly looks exactly like one that has been up for three weeks
     * without this. Clamped: a machine with a wrong clock, or an agent that
     * reports nonsense, must not put a date in the next century on the page.
     */
    public static function recordAgentStart(int $deviceId, int $uptimeSeconds): void
    {
        if ($uptimeSeconds <= 0 || $uptimeSeconds > 86400 * 365) {
            return;
        }

        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET agent_started_at = :at
             WHERE id = :id',
            [
                'at' => gmdate('Y-m-d H:i:s', time() - $uptimeSeconds),
                'id' => $deviceId,
            ]
        );
    }

    /**
     * Every authorized device in a network, including the one asking.
     *
     * peersFor() deliberately excludes the caller, because a device is not its
     * own peer. A name, though, is a name: a technician typing
     * `laptop.acme.internal` on the laptop itself should get an answer, not
     * NXDOMAIN.
     *
     * @return list<array<string,mixed>>
     */
    public static function activeInNetwork(int $networkId): array
    {
        return DB::select(
            'SELECT id, device_uid, name, virtual_ip, is_gateway
             FROM ' . self::tableName() . '
             WHERE network_id = :n AND status = \'authorized\'
               AND virtual_ip IS NOT NULL AND deleted_at IS NULL
             ORDER BY id ASC',
            ['n' => $networkId]
        );
    }

    /**
     * Mark devices that stopped heartbeating as offline.
     *
     * Runs in the worker rather than on read so the dashboard never has to
     * compute staleness per row.
     */
    /**
     * How long without a heartbeat before a device is offline.
     *
     * One number, used by the sweep that writes the column and by the view
     * that reads it, because two numbers would disagree and the disagreement
     * would show as a device that is offline in the list and online on its own
     * page. The agent heartbeats every ten seconds, so ninety is nine missed
     * in a row.
     */
    public const OFFLINE_AFTER_SECONDS = 90;

    public static function markStaleOffline(int $staleSeconds): int
    {
        return TenantScope::acrossAllTenants('offline sweep', static fn (): int => DB::execute(
            'UPDATE ' . self::tableName() . '
             SET connection_type = \'offline\', updated_at = UTC_TIMESTAMP()
             WHERE connection_type <> \'offline\'
               AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :s SECOND))',
            ['s' => $staleSeconds]
        )->rowCount());
    }

    /** @return array{total:int,online:int,direct:int,relay:int,pending:int} */
    public static function statusSummary(?int $tenantId = null): array
    {
        $where = 'deleted_at IS NULL';
        $params = [];
        if ($tenantId !== null) {
            $where .= ' AND tenant_id = :t';
            $params['t'] = $tenantId;
        }

        $row = DB::selectOne(
            // Online is measured from the heartbeat, not from the path. A
            // device that is running and has not yet found a peer is online;
            // counting it as offline is what made a working pair look dead.
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN last_seen_at IS NOT NULL
                              AND last_seen_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                                  . self::OFFLINE_AFTER_SECONDS . ' SECOND)
                             THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN connection_type = \'direct\' THEN 1 ELSE 0 END) AS direct,
                    SUM(CASE WHEN connection_type = \'relay\' THEN 1 ELSE 0 END) AS relay,
                    SUM(CASE WHEN connection_type = \'connecting\' THEN 1 ELSE 0 END) AS connecting,
                    SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending
             FROM ' . self::tableName() . ' WHERE ' . $where,
            $params
        ) ?? [];

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'online'     => (int) ($row['online'] ?? 0),
            'direct'     => (int) ($row['direct'] ?? 0),
            'relay'      => (int) ($row['relay'] ?? 0),
            'connecting' => (int) ($row['connecting'] ?? 0),
            'pending'    => (int) ($row['pending'] ?? 0),
        ];
    }
}
