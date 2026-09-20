<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sliding-window rate limiter.
 *
 * Redis when configured (atomic, no DB load); MySQL otherwise so a single-box
 * aaPanel install still gets real limiting rather than none. Both backends use
 * the same window semantics: a counter per (key, window-start) bucket, with the
 * previous bucket weighted by how far we are into the current one.
 */
final class RateLimit
{
    private static ?\Redis $redis = null;
    private static bool $redisChecked = false;

    /**
     * @throws RateLimitException when the limit is exceeded
     */
    public static function enforce(string $key, int $maxAttempts, int $windowSeconds): void
    {
        $used = self::hit($key, $windowSeconds);
        if ($used > $maxAttempts) {
            $retryAfter = $windowSeconds - (time() % $windowSeconds);
            Logger::warning('security', 'Rate limit exceeded', ['key' => $key, 'used' => $used, 'limit' => $maxAttempts]);
            throw new RateLimitException($retryAfter);
        }
    }

    /** Record one hit and return the weighted count in the current window. */
    public static function hit(string $key, int $windowSeconds): int
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $elapsedRatio = ($now - $windowStart) / $windowSeconds;

        $redis = self::redis();
        if ($redis !== null) {
            $currentKey = "rl:{$key}:{$windowStart}";
            $previousKey = 'rl:' . $key . ':' . ($windowStart - $windowSeconds);
            $current = (int) $redis->incr($currentKey);
            if ($current === 1) {
                $redis->expire($currentKey, $windowSeconds * 2);
            }
            $previous = (int) ($redis->get($previousKey) ?: 0);

            return (int) ceil($current + $previous * (1 - $elapsedRatio));
        }

        DB::execute(
            'INSERT INTO ' . DB::table('rate_limits') . ' (bucket_key, window_start, hits, expires_at, created_at, updated_at)
             VALUES (:k, :ws, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE hits = hits + 1, updated_at = UTC_TIMESTAMP()',
            ['k' => $key, 'ws' => $windowStart, 'ttl' => $windowSeconds * 2]
        );

        $current = (int) (DB::scalar(
            'SELECT hits FROM ' . DB::table('rate_limits') . ' WHERE bucket_key = :k AND window_start = :ws',
            ['k' => $key, 'ws' => $windowStart]
        ) ?? 0);
        $previous = (int) (DB::scalar(
            'SELECT hits FROM ' . DB::table('rate_limits') . ' WHERE bucket_key = :k AND window_start = :ws',
            ['k' => $key, 'ws' => $windowStart - $windowSeconds]
        ) ?? 0);

        return (int) ceil($current + $previous * (1 - $elapsedRatio));
    }

    /** Current weighted count without recording a hit. */
    public static function peek(string $key, int $windowSeconds): int
    {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);

        $redis = self::redis();
        if ($redis !== null) {
            return (int) ($redis->get("rl:{$key}:{$windowStart}") ?: 0);
        }

        return (int) (DB::scalar(
            'SELECT hits FROM ' . DB::table('rate_limits') . ' WHERE bucket_key = :k AND window_start = :ws',
            ['k' => $key, 'ws' => $windowStart]
        ) ?? 0);
    }

    public static function clear(string $key): void
    {
        $redis = self::redis();
        if ($redis !== null) {
            foreach ((array) $redis->keys("rl:{$key}:*") as $k) {
                $redis->del($k);
            }

            return;
        }
        DB::execute('DELETE FROM ' . DB::table('rate_limits') . ' WHERE bucket_key = :k', ['k' => $key]);
    }

    public static function prune(): int
    {
        if (self::redis() !== null) {
            return 0; // Redis expires its own keys.
        }

        return DB::execute('DELETE FROM ' . DB::table('rate_limits') . ' WHERE expires_at < UTC_TIMESTAMP()')->rowCount();
    }

    public static function redis(): ?\Redis
    {
        if (self::$redisChecked) {
            return self::$redis;
        }
        self::$redisChecked = true;

        if (!Config::get('redis.enabled', false) || !class_exists(\Redis::class)) {
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect(
                (string) Config::get('redis.host', '127.0.0.1'),
                (int) Config::get('redis.port', 6379),
                1.5
            );
            $password = (string) Config::get('redis.pass', '');
            if ($password !== '') {
                $redis->auth($password);
            }
            $redis->select((int) Config::get('redis.db', 0));
            self::$redis = $redis;
        } catch (\Throwable $e) {
            // Redis being down must never take the panel down; fall back to MySQL.
            Logger::warning('app', 'Redis unavailable, falling back to database', ['error' => $e->getMessage()]);
            self::$redis = null;
        }

        return self::$redis;
    }
}
