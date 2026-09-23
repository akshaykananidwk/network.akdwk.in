<?php

declare(strict_types=1);

/**
 * A region hint on devices, to sit alongside the one relays already carry.
 *
 * It is a hint and nothing more. Relay choice is made on RTT the agent has
 * actually measured, because the only thing that knows how far a device is
 * from a relay is the device: a shop in Ahmedabad and a laptop roaming on 4G
 * can share a region label and have nothing else in common, and a device
 * behind CGNAT has no address we could infer a location from anyway.
 *
 * The column exists so an operator can pin a device's search to one region
 * when they have a reason to, and so the dashboard can group by it. Empty is
 * the normal value and selection must work perfectly well without it.
 *
 * Reversible: down() drops the column.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'devices';

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'region'"
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 ADD COLUMN `region` VARCHAR(32) NOT NULL DEFAULT \'\' AFTER `arch`'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'devices';

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'region'"
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 DROP COLUMN `region`'
            );
        }
    },
];
