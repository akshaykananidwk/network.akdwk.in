<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/**
 * One row per update run. Also the mutual-exclusion lock: only one row may be
 * in a non-terminal state at a time (§9.4 step 1).
 */
final class AppUpdate extends Model
{
    protected static string $table = 'app_updates';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = false;
    protected static array $jsonColumns = ['manifest_json', 'applied_migrations_json', 'copied_migrations_json'];
    protected static array $sortable = ['id', 'created_at', 'status'];
    protected static array $fillable = [
        'from_version', 'to_version', 'from_commit', 'to_commit', 'status', 'step',
        'progress_pct', 'backup_id', 'log_path', 'journal_path', 'stage_path',
        'manifest_json', 'applied_migrations_json', 'copied_migrations_json', 'error_text',
        'started_by', 'trigger_source', 'started_at', 'finished_at',
    ];

    /** The ordered step machine from §9.4. */
    public const STEPS = [
        'PRECHECK', 'MAINTENANCE', 'BACKUP_FILES', 'BACKUP_DB', 'DOWNLOAD',
        'STAGE', 'MIGRATE', 'APPLY', 'POST', 'HEALTH', 'FINALISE',
    ];

    /** Statuses from which no further work will happen. */
    public const TERMINAL = ['success', 'failed', 'rolled_back'];

    public static function stepIndex(string $step): int
    {
        $index = array_search($step, self::STEPS, true);

        return $index === false ? 0 : (int) $index;
    }

    public static function progressFor(string $step): int
    {
        return (int) round((self::stepIndex($step) / (count(self::STEPS) - 1)) * 100);
    }

    /** @return array<string,mixed>|null an update that is still in flight */
    public static function running(): ?array
    {
        $placeholders = [];
        $bindings = [];
        foreach (self::TERMINAL as $i => $status) {
            $placeholders[] = ':s' . $i;
            $bindings['s' . $i] = $status;
        }

        $row = DB::selectOne(
            'SELECT * FROM ' . self::tableName() . '
             WHERE status NOT IN (' . implode(', ', $placeholders) . ')
             ORDER BY id DESC LIMIT 1',
            $bindings
        );

        if ($row === null) {
            return null;
        }

        // A run whose PHP process died mid-step would otherwise block every
        // future update. After the stall window it is force-failed so the
        // operator can retry (and, if files were touched, roll back).
        $stallMinutes = 30;
        $updatedAt = strtotime((string) $row['updated_at'] . ' UTC');
        if ($updatedAt !== false && (time() - $updatedAt) > $stallMinutes * 60) {
            self::fail((int) $row['id'], 'Update stalled: no progress for ' . $stallMinutes . ' minutes.');

            return null;
        }

        return self::decode($row);
    }

    /** @return array<string,mixed>|null */
    public static function findRow(int $id): ?array
    {
        $row = DB::selectOne('SELECT * FROM ' . self::tableName() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string,mixed>|null */
    public static function latest(): ?array
    {
        $row = DB::selectOne('SELECT * FROM ' . self::tableName() . ' ORDER BY id DESC LIMIT 1');

        return $row === null ? null : self::decode($row);
    }

    public static function advance(int $id, string $step, string $status): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET step = :step, status = :status, progress_pct = :pct, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'step' => $step, 'status' => $status, 'pct' => self::progressFor($step)]
        );
    }

    public static function fail(int $id, string $error): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET status = \'failed\', error_text = :e, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'e' => \App\Core\Logger::redactString($error)]
        );
    }

    public static function succeed(int $id): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET status = \'success\', step = \'FINALISE\', progress_pct = 100,
                 finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function markRolledBack(int $id, string $error): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . '
             SET status = \'rolled_back\', error_text = :e, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $id, 'e' => \App\Core\Logger::redactString($error)]
        );
    }

    /** @param list<string> $filenames */
    public static function recordAppliedMigrations(int $id, array $filenames): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET applied_migrations_json = :m, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id, 'm' => json_encode(array_values($filenames))]
        );
    }

    /**
     * The migration files this update copied into database/migrations.
     *
     * Recorded separately from the applied list: a rollback needs to remove
     * the files it introduced, which is not the same set as the migrations
     * that ran — a file already on disk is applied but not copied.
     *
     * @param list<string> $filenames
     */
    public static function recordCopiedMigrations(int $id, array $filenames): void
    {
        DB::execute(
            'UPDATE ' . self::tableName() . ' SET copied_migrations_json = :m, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id, 'm' => json_encode(array_values($filenames))]
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function history(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        $total = (int) DB::scalar('SELECT COUNT(*) FROM ' . self::tableName());

        $rows = DB::select(
            'SELECT u.*, usr.name AS started_by_name, usr.email AS started_by_email,
                    b.size_bytes AS backup_size, b.files_path AS backup_files_path
             FROM ' . self::tableName() . ' u
             LEFT JOIN ' . DB::table('users') . ' usr ON usr.id = u.started_by
             LEFT JOIN ' . DB::table('app_backups') . ' b ON b.id = u.backup_id
             ORDER BY u.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );

        return [
            'rows'     => array_map(self::decode(...), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['manifest_json', 'applied_migrations_json', 'copied_migrations_json'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        $row['rollback_available'] = self::journalExists($row);

        return $row;
    }

    /**
     * Is the per-file undo list still on disk?
     *
     * journal_path stays set for the life of the row, but the journal itself
     * is pruned with the backups and discarded once a rollback has consumed
     * it. Offering "roll back to this point" on the strength of the column
     * alone would present a button that reverses migrations and restores the
     * database while putting no files back — the exact failure this column was
     * meant to prevent.
     *
     * @param array<string,mixed> $row
     */
    private static function journalExists(array $row): bool
    {
        if (($row['status'] ?? '') !== 'success') {
            return false;
        }

        $relative = (string) ($row['journal_path'] ?? '');
        if ($relative === '' || !defined('APP_ROOT')) {
            return false;
        }

        return is_file(APP_ROOT . '/' . ltrim($relative, '/'));
    }
}
