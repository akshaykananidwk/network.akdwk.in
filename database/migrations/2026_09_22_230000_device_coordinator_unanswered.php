<?php

declare(strict_types=1);

/**
 * When the coordinator's replies are not reaching a device.
 *
 * From the field: a laptop on an office Wi-Fi announced itself every few
 * seconds all afternoon. Every announcement arrived at the coordinator and was
 * answered; not one answer arrived back. The panel showed the device Online
 * with a blue "connecting" dot the whole time, because from the panel's side
 * everything looked busy and healthy.
 *
 * Only the coordinator can see this. It has both halves — the announcements
 * arriving, and the device re-announcing as though none had been answered —
 * and an agent that hears nothing does not know a reply was ever sent, so it
 * can only ever report "still waiting" about the same fault.
 *
 * NULL means the coordinator has not said so, or has said it is over.
 *
 * Reversible: down() drops the column.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . '
               AND COLUMN_NAME = ' . $pdo->quote('coordinator_unanswered_at')
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec('ALTER TABLE `' . $table . '`
                ADD COLUMN `coordinator_unanswered_at` DATETIME NULL DEFAULT NULL');
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . '
               AND COLUMN_NAME = ' . $pdo->quote('coordinator_unanswered_at')
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `coordinator_unanswered_at`');
        }
    },
];
