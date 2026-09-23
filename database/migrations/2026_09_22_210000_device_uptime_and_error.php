<?php

declare(strict_types=1);

/**
 * Two questions a support call always starts with, answered on the page.
 *
 * "Did it restart?" and "what went wrong last?". Today an administrator can
 * see that a device is offline and nothing about why, so the next step is a
 * telephone call to a shopkeeper asking them to find a log file.
 *
 *   - **agent_started_at** is when the agent process came up, sent on every
 *     heartbeat. A device that reboots nightly and a device that has been up
 *     for three weeks look identical without it, and one of them is the
 *     machine somebody keeps switching off at the wall.
 *   - **update_requested_at** is an administrator having pressed "Update now"
 *     on a device's page. The agent carries it back on its next configuration
 *     poll; there is no channel from the panel into a PC behind a shop router,
 *     and inventing one would be a far bigger thing than this button.
 *   - **last_error** is the most recent thing the agent could not do, kept
 *     after it clears. problems_json holds what is wrong NOW and empties when
 *     it stops — which means the fault that was happening an hour ago, when
 *     the customer rang, has left no trace by the time anybody looks.
 *
 * Neither carries a credential. What goes in last_error is the agent's own
 * sentence about a rule Windows refused or a prefix that clashed, and the
 * agent redacts before it reports.
 *
 * Reversible: down() drops the columns.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        foreach ([
            'agent_started_at' => 'ADD COLUMN `agent_started_at` DATETIME NULL DEFAULT NULL',
            'last_error'       => 'ADD COLUMN `last_error` VARCHAR(500) NULL DEFAULT NULL',
            'last_error_at'    => 'ADD COLUMN `last_error_at` DATETIME NULL DEFAULT NULL',
            'update_requested_at' => 'ADD COLUMN `update_requested_at` DATETIME NULL DEFAULT NULL',
        ] as $column => $clause) {
            $exists = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ' . $pdo->quote($table) . '
                   AND COLUMN_NAME = ' . $pdo->quote($column)
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

        foreach (['update_requested_at', 'last_error_at', 'last_error', 'agent_started_at'] as $column) {
            $exists = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ' . $pdo->quote($table) . '
                   AND COLUMN_NAME = ' . $pdo->quote($column)
            )->fetchColumn();

            if ($exists > 0) {
                // @sql-identifier As above — an identifier, not a value.
                $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
            }
        }
    },
];
