<?php

declare(strict_types=1);

/**
 * A mapping pool per network.
 *
 * The default is 10.128.0.0/10, chosen because it is the half of 10/8 that
 * almost nobody numbers a LAN out of. "Almost nobody" is not "nobody": plenty
 * of offices, and most ISP-managed networks in this market, use 10.x
 * internally, and a customer who does needs a way out that is not a code
 * change or a global setting that moves every other customer with it.
 *
 * NULL means "use the configured default", so existing networks keep working
 * and a change to the default still reaches them.
 *
 * Reversible: down() drops the column.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'networks');

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'mapped_pool'"
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . $table . '`
                 ADD COLUMN `mapped_pool` VARCHAR(20) NULL DEFAULT NULL AFTER `cidr`'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'networks');

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'mapped_pool'"
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `mapped_pool`');
        }
    },
];
