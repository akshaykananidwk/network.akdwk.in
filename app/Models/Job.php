<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Logger;

/**
 * Database-backed job queue with retry, backoff and a dead-letter state.
 *
 * Reservation uses UPDATE ... LIMIT 1 with a worker id, then re-reads the row:
 * two workers polling the same queue cannot claim the same job, without needing
 * SELECT ... FOR UPDATE and an open transaction across the whole job.
 */
final class Job extends Model
{
    protected static string $table = 'jobs';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = false;
    protected static array $jsonColumns = ['payload_json'];
    protected static array $fillable = [
        'queue', 'job_type', 'payload_json', 'attempts', 'max_attempts',
        'available_at', 'reserved_at', 'reserved_by', 'completed_at', 'failed_at', 'last_error',
    ];

    /** @param array<string,mixed> $payload */
    public static function push(string $type, array $payload = [], string $queue = 'default', int $delaySeconds = 0): int
    {
        return self::create([
            'queue'        => $queue,
            'job_type'     => $type,
            'payload_json' => $payload,
            'available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function reserve(string $queue, string $workerId): ?array
    {
        $claimed = DB::execute(
            'UPDATE ' . self::tableName() . '
             SET reserved_at = UTC_TIMESTAMP(), reserved_by = :w, attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
             WHERE queue = :q
               AND completed_at IS NULL
               AND failed_at IS NULL
               AND available_at <= UTC_TIMESTAMP()
               AND (reserved_at IS NULL OR reserved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
             ORDER BY id ASC
             LIMIT 1',
            ['q' => $queue, 'w' => $workerId]
        )->rowCount();

        if ($claimed === 0) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT * FROM ' . self::tableName() . '
             WHERE reserved_by = :w AND completed_at IS NULL AND failed_at IS NULL
             ORDER BY reserved_at DESC LIMIT 1',
            ['w' => $workerId]
        );

        if ($row !== null && isset($row['payload_json']) && is_string($row['payload_json'])) {
            $decoded = json_decode($row['payload_json'], true);
            $row['payload_json'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    public static function complete(int $id): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET completed_at = UTC_TIMESTAMP(), reserved_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Release for retry with exponential backoff, or dead-letter once the
     * attempt budget is spent.
     */
    public static function fail(int $id, int $attempts, int $maxAttempts, string $error): void
    {
        $error = Logger::redactString($error);

        if ($attempts >= $maxAttempts) {
            DB::execute(
                'UPDATE ' . self::tableName() . '
                 SET failed_at = UTC_TIMESTAMP(), reserved_at = NULL, last_error = :e, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id',
                ['id' => $id, 'e' => $error]
            );
            Logger::error('app', 'Job dead-lettered', ['job_id' => $id, 'attempts' => $attempts]);

            return;
        }

        $backoff = min(3600, 30 * (2 ** ($attempts - 1)));
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET reserved_at = NULL, reserved_by = NULL, last_error = :e,
                 available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :b SECOND), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'e' => $error, 'b' => $backoff]
        );
    }

    /** @return array{pending:int,reserved:int,failed:int,completed_today:int} */
    public static function stats(): array
    {
        $row = DB::selectOne(
            'SELECT
                SUM(CASE WHEN completed_at IS NULL AND failed_at IS NULL AND reserved_at IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN reserved_at IS NOT NULL AND completed_at IS NULL AND failed_at IS NULL THEN 1 ELSE 0 END) AS reserved,
                SUM(CASE WHEN failed_at IS NOT NULL THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS completed_today
             FROM ' . self::tableName()
        ) ?? [];

        return [
            'pending'         => (int) ($row['pending'] ?? 0),
            'reserved'        => (int) ($row['reserved'] ?? 0),
            'failed'          => (int) ($row['failed'] ?? 0),
            'completed_today' => (int) ($row['completed_today'] ?? 0),
        ];
    }

    public static function pruneCompleted(int $days = 7): int
    {
        return DB::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY) LIMIT 5000',
            ['d' => $days]
        )->rowCount();
    }
}
