<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Stores PHP sessions in MySQL rather than on local disk.
 *
 * This is what makes the web tier stateless behind a load balancer (§12): any
 * node can serve any request. It also gives the admin UI a real "active
 * sessions" list and lets a revoked user be logged out everywhere at once.
 */
final class DbSessionHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = DB::selectOne(
            'SELECT payload FROM ' . DB::table('sessions') . ' WHERE id = :id AND expires_at > UTC_TIMESTAMP() LIMIT 1',
            ['id' => $id]
        );

        return $row === null ? '' : (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        $request = Request::current();
        $lifetime = (int) Config::get('session.lifetime_minutes', 720) * 60;

        DB::execute(
            'INSERT INTO ' . DB::table('sessions') . '
                (id, user_id, tenant_id, ip, user_agent, payload, last_activity, expires_at, created_at, updated_at)
             VALUES
                (:id, :user_id, :tenant_id, :ip, :ua, :payload, UTC_TIMESTAMP(),
                 DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id), tenant_id = VALUES(tenant_id), ip = VALUES(ip),
                user_agent = VALUES(user_agent), payload = VALUES(payload),
                last_activity = UTC_TIMESTAMP(), expires_at = VALUES(expires_at), updated_at = UTC_TIMESTAMP()',
            [
                'id'        => $id,
                'user_id'   => $_SESSION['user_id'] ?? null,
                'tenant_id' => $_SESSION['tenant_id'] ?? null,
                'ip'        => $request?->ip() ?? '',
                'ua'        => $request?->userAgent() ?? '',
                'payload'   => $data,
                'ttl'       => $lifetime,
            ]
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        DB::execute('DELETE FROM ' . DB::table('sessions') . ' WHERE id = :id', ['id' => $id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return DB::execute('DELETE FROM ' . DB::table('sessions') . ' WHERE expires_at < UTC_TIMESTAMP()')->rowCount();
    }

    /** Kill every session belonging to a user — used on revoke and role change. */
    public static function destroyForUser(int $userId): int
    {
        return DB::execute(
            'DELETE FROM ' . DB::table('sessions') . ' WHERE user_id = :uid',
            ['uid' => $userId]
        )->rowCount();
    }
}
