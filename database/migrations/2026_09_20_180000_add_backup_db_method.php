<?php

declare(strict_types=1);

/**
 * Record which dumper produced a backup's database dump.
 *
 * Until 1.0.2 a dump could be produced by mysqldump even when the schema had
 * stored generated columns, and such a dump cannot be restored at all: the
 * server rejects the explicit value with error 1906. Backups taken before that
 * fix are still sitting on disk, indistinguishable from good ones.
 *
 * Recording the method makes the difference visible, so the recovery
 * instructions on an update's detail page can tell an operator whether the
 * backup in front of them is one the panel can replay.
 *
 * Reversible: down() drops the column, so a failed update can unwind this
 * without falling back to the database dump.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'app_backups';

        // Re-runnable: an operator who restores a backup mid-update should be
        // able to run the migration again without it failing on a column that
        // is already there.
        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'db_method'"
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 ADD COLUMN `db_method` VARCHAR(20) NULL DEFAULT NULL AFTER `db_sha256`'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'app_backups';

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'db_method'"
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '` DROP COLUMN `db_method`'
            );
        }
    },
];
