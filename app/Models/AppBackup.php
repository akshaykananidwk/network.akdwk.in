<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class AppBackup extends Model
{
    protected static string $table = 'app_backups';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['id', 'created_at', 'size_bytes'];
    protected static array $fillable = [
        'type', 'files_path', 'db_path', 'files_sha256', 'db_sha256', 'size_bytes',
        'app_version', 'app_commit', 'uploads_included', 'uploads_skipped_reason',
        'status', 'error_text', 'retained_until', 'created_by',
    ];

    /** @return array<string,mixed>|null */
    public static function findRow(int $id): ?array
    {
        return DB::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 25): array
    {
        return DB::select(
            'SELECT b.*, u.name AS created_by_name
             FROM ' . self::tableName() . ' b
             LEFT JOIN ' . DB::table('users') . ' u ON u.id = b.created_by
             WHERE b.deleted_at IS NULL
             ORDER BY b.id DESC LIMIT ' . max(1, min($limit, 200))
        );
    }

    /**
     * Backups beyond the retention count, newest first kept.
     *
     * A backup taken for an update that is still running is never a candidate:
     * it is the only way back.
     *
     * @return list<array<string,mixed>>
     */
    public static function prunable(int $keep): array
    {
        $keep = max(1, $keep);

        return DB::select(
            'SELECT b.* FROM ' . self::tableName() . ' b
             WHERE b.deleted_at IS NULL
               AND b.status = \'complete\'
               AND (b.retained_until IS NULL OR b.retained_until < UTC_TIMESTAMP())
               AND NOT EXISTS (
                   SELECT 1 FROM ' . DB::table('app_updates') . ' u
                   WHERE u.backup_id = b.id AND u.status NOT IN (\'success\',\'failed\',\'rolled_back\')
               )
             ORDER BY b.id DESC
             LIMIT 18446744073709551615 OFFSET ' . $keep
        );
    }

    public static function totalSize(): int
    {
        return (int) (DB::scalar('SELECT COALESCE(SUM(size_bytes), 0) FROM ' . self::tableName() . ' WHERE deleted_at IS NULL') ?? 0);
    }
}
