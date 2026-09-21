<?php

declare(strict_types=1);

/**
 * What a device could not do, reported where somebody will see it.
 *
 * Two things the agent cannot fix by itself, and which are invisible until a
 * customer rings up:
 *
 *   - **NRPT refused the DNS rule** on a locked-down Windows machine. The
 *     agent will not fall back to editing the hosts file there, because
 *     Defender flags that as HostsFileHijack and every antivirus in the
 *     country would turn one install into a support call.
 *   - **A prefix clashed with a network the machine is already on.** A mapped
 *     LAN, or the overlay itself, landing on top of an office range the agent
 *     must not take over. The fix is to change the network's CIDR or its
 *     mapping pool, and neither is something the agent can decide.
 *
 * Both used to be a line in a log file on the customer's machine, which is the
 * same as not being reported at all.
 *
 * Reversible: down() drops the columns.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        foreach ([
            'problems_json' => "ADD COLUMN `problems_json` JSON NULL DEFAULT NULL",
            'problems_at'   => "ADD COLUMN `problems_at` DATETIME NULL DEFAULT NULL",
        ] as $column => $clause) {
            $exists = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ' . $pdo->quote($table) . "
                   AND COLUMN_NAME = " . $pdo->quote($column)
            )->fetchColumn();

            if ($exists === 0) {
                // @sql-identifier The table name carries the configured prefix
                // and cannot be a bound parameter; the prefix is sanitised at
                // install, and the clause is a literal from the list above.
                $pdo->exec('ALTER TABLE `' . $table . '` ' . $clause);
            }
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        foreach (['problems_at', 'problems_json'] as $column) {
            $exists = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ' . $pdo->quote($table) . "
                   AND COLUMN_NAME = " . $pdo->quote($column)
            )->fetchColumn();

            if ($exists > 0) {
                // @sql-identifier As above — an identifier, not a value.
                $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
            }
        }
    },
];
