<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * Ledger of applied migrations.
 *
 * The unique index on filename is what makes re-running the runner a no-op —
 * the property §20.B asks us to demonstrate. The checksum catches the worse
 * case: a migration file edited after it was applied.
 */
final class MigrationRecord extends Model
{
    protected static string $table = 'migrations';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = false;
    protected static array $fillable = ['filename', 'batch', 'checksum', 'execution_ms', 'success', 'error_text'];

    /** @return list<string> */
    public static function appliedFilenames(): array
    {
        $rows = DB::select('SELECT filename FROM ' . self::tableName() . ' WHERE success = 1 ORDER BY id');

        return array_map(static fn (array $r): string => (string) $r['filename'], $rows);
    }

    public static function isApplied(string $filename): bool
    {
        return (int) DB::scalar(
            'SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE filename = :f AND success = 1',
            ['f' => $filename]
        ) > 0;
    }

    /** @return array<string,mixed>|null */
    public static function findByFilename(string $filename): ?array
    {
        return DB::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE filename = :f', ['f' => $filename]);
    }

    public static function nextBatch(): int
    {
        return (int) (DB::scalar('SELECT COALESCE(MAX(batch), 0) FROM ' . self::tableName()) ?? 0) + 1;
    }

    public static function record(string $filename, int $batch, string $checksum, int $ms, bool $success, ?string $error = null): void
    {
        DB::execute(
            'INSERT INTO ' . self::tableName() . ' (filename, batch, checksum, execution_ms, success, error_text, executed_at)
             VALUES (:f, :b, :c, :ms, :s, :e, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                batch = VALUES(batch), checksum = VALUES(checksum), execution_ms = VALUES(execution_ms),
                success = VALUES(success), error_text = VALUES(error_text), executed_at = UTC_TIMESTAMP()',
            ['f' => $filename, 'b' => $batch, 'c' => $checksum, 'ms' => $ms, 's' => $success ? 1 : 0, 'e' => $error]
        );
    }

    public static function forget(string $filename): void
    {
        DB::execute('DELETE FROM ' . self::tableName() . ' WHERE filename = :f', ['f' => $filename]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return DB::select('SELECT * FROM ' . self::tableName() . ' ORDER BY id DESC');
    }
}
