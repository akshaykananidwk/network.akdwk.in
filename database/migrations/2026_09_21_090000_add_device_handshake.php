<?php

declare(strict_types=1);

/**
 * Record the last WireGuard handshake separately from the control-plane
 * heartbeat.
 *
 * `last_seen_at` says "the agent called the panel". That is not the same as
 * "the tunnel is alive": an agent can be reaching the panel over ordinary
 * HTTPS while its peer link is down, and it can equally be passing traffic
 * while the panel is unreachable (R6). Conflating them makes the dashboard
 * lie in both directions, so Phase 2 needs its own column.
 *
 * Reversible: down() drops the column, so a failed update can unwind this
 * without falling back to the database dump.
 *
 * @return array{up: callable, down: callable}
 */

use App\Core\DB;

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'devices';

        // Re-runnable: an operator who restores a backup mid-update should be
        // able to run the migration again without it failing on a column that
        // is already there.
        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'last_handshake_at'"
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 ADD COLUMN `last_handshake_at` DATETIME NULL DEFAULT NULL AFTER `last_seen_at`,
                 ADD INDEX `idx_devices_handshake` (`last_handshake_at`)'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'devices';

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'last_handshake_at'"
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 DROP INDEX `idx_devices_handshake`,
                 DROP COLUMN `last_handshake_at`'
            );
        }
    },
];
