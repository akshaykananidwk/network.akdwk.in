<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\TenantScope;
use App\Models\Device;

/**
 * Server-Sent Events for live dashboard updates (§3.1).
 *
 * PHP cannot host a WebSocket server, and pretending otherwise would be the
 * kind of thing §19.3 warns against. SSE is the honest fit: one long-lived
 * HTTP response, server-to-client only, which is all the dashboard needs.
 * Clients that cannot use it fall back to 5-second polling of /stats.
 *
 * The connection is bounded (default 60s) and the session is closed for
 * writing immediately, because a held session lock would block every other
 * request from the same browser tab.
 */
final class StreamController
{
    private const MAX_SECONDS = 60;
    private const POLL_INTERVAL = 3;

    public function stream(Request $request): Response
    {
        $tenantId = Auth::tenantId();
        $isPlatform = TenantScope::isPlatformActor();

        if ($tenantId === null && !$isPlatform) {
            return Response::apiError('Not signed in.', 401);
        }

        // Release the session lock before blocking: without this, every other
        // request from the same browser queues behind this one.
        if (Session::started()) {
            session_write_close();
        }

        @set_time_limit(self::MAX_SECONDS + 10);
        ignore_user_abort(false);

        $response = Response::eventStream();
        $response->send();

        // Flush whatever buffering the SAPI has set up, or nothing reaches the
        // browser until the connection closes.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->emit('connected', ['at' => gmdate('c'), 'interval' => self::POLL_INTERVAL]);

        $deadline = time() + self::MAX_SECONDS;
        $lastSnapshot = null;

        while (time() < $deadline) {
            if (connection_aborted() === 1) {
                break;
            }

            $snapshot = $this->snapshot($tenantId);

            if ($lastSnapshot === null) {
                $this->emit('snapshot', $snapshot);
            } else {
                foreach ($this->diff($lastSnapshot, $snapshot) as $event => $payload) {
                    $this->emit($event, $payload);
                }
            }

            $lastSnapshot = $snapshot;

            // A comment line keeps proxies from closing an idle connection.
            echo ": keep-alive\n\n";
            flush();

            sleep(self::POLL_INTERVAL);
        }

        $this->emit('reconnect', ['reason' => 'connection recycled', 'after' => 1]);

        // The body has already been streamed; returning an empty 200 stops the
        // kernel from writing anything further.
        return Response::make('', 200);
    }

    /**
     * Cheap counters only. This runs every few seconds per connected browser,
     * so it must stay to indexed aggregates.
     *
     * @return array<string,mixed>
     */
    private function snapshot(?int $tenantId): array
    {
        $where = 'deleted_at IS NULL';
        $bindings = ['cutoff' => Device::onlineCutoffSql()];

        if ($tenantId !== null) {
            $where .= ' AND tenant_id = :t';
            $bindings['t'] = $tenantId;
        }

        $devices = DB::selectOne(
            // Online by the heartbeat, the same rule as everywhere else. It
            // was direct + relay, so a running device that had not needed a
            // peer yet was counted offline on the live dashboard.
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN last_seen_at IS NOT NULL AND last_seen_at >= :cutoff
                              AND connection_type <> \'offline\'
                              AND status NOT IN (\'revoked\', \'disabled\')
                             THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN connection_type = \'direct\' THEN 1 ELSE 0 END) AS direct,
                    SUM(CASE WHEN connection_type IN (\'relay\', \'relay_https\')
                             THEN 1 ELSE 0 END) AS relay,
                    SUM(CASE WHEN connection_type = \'offline\' THEN 1 ELSE 0 END) AS offline,
                    SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending
             FROM ' . DB::table('devices') . ' WHERE ' . $where,
            $bindings
        ) ?? [];

        return [
            'at'      => gmdate('c'),
            'total'   => (int) ($devices['total'] ?? 0),
            'online'  => (int) ($devices['online'] ?? 0),
            'direct'  => (int) ($devices['direct'] ?? 0),
            'relay'   => (int) ($devices['relay'] ?? 0),
            'offline' => (int) ($devices['offline'] ?? 0),
            'pending' => (int) ($devices['pending'] ?? 0),
        ];
    }

    /**
     * Turn two snapshots into the named events from §8.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array<string,mixed>>
     */
    private function diff(array $before, array $after): array
    {
        $events = [];

        $onlineBefore = (int) ($before['online'] ?? 0);
        $onlineAfter = (int) ($after['online'] ?? 0);

        if ($onlineAfter > $onlineBefore) {
            $events['device.online'] = ['count' => $onlineAfter - $onlineBefore, 'online' => $onlineAfter];
        } elseif ($onlineAfter < $onlineBefore) {
            $events['device.offline'] = ['count' => $onlineBefore - $onlineAfter, 'online' => $onlineAfter];
        }

        if ((int) $after['pending'] > (int) $before['pending']) {
            $events['device.pending'] = ['pending' => (int) $after['pending']];
        }

        if ((int) $after['direct'] !== (int) $before['direct'] || (int) $after['relay'] !== (int) $before['relay']) {
            $events['conn.changed'] = ['direct' => (int) $after['direct'], 'relay' => (int) $after['relay']];
        }

        if ($events !== []) {
            $events['snapshot'] = $after;
        }

        return $events;
    }

    /** @param array<string,mixed> $data */
    private function emit(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }
}
