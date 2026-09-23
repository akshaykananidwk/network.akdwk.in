<?php

declare(strict_types=1);

/**
 * Record which migration files an update copied into database/migrations.
 *
 * MIGRATE copies the new release's migration files into place before running
 * them, so the ledger and the files on disk agree even if a later step fails.
 * Nothing recorded which files were new, so a rollback reversed the migration
 * in the database but left its file behind — and `migrate.php --status` then
 * reported it as pending, inviting an operator to re-apply a migration from
 * the very version they had just rolled back from.
 *
 * With the filenames recorded, the rollback can remove exactly the files it
 * put there and nothing else.
 *
 * Reversible: down() drops the column.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'app_updates';

        // Re-runnable: an operator who restores a backup mid-update should be
        // able to run the migration again without it failing on a column that
        // is already there.
        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'copied_migrations_json'"
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 ADD COLUMN `copied_migrations_json` JSON NULL AFTER `applied_migrations_json`'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'app_updates';

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'copied_migrations_json'"
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '` DROP COLUMN `copied_migrations_json`'
            );
        }
    },
];
