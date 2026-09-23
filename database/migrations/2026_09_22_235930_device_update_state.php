<?php

declare(strict_types=1);

/**
 * What each device did about the last release, so a stalled fleet is visible.
 *
 * An all-in-one at a customer site stayed on 1.9.3 for days after 1.9.4 was
 * published, and nobody found out until somebody looked at the version column
 * and counted. Everything the agent does about an update — the check, the
 * offer, the download, the signature, the swap — it does silently, and every
 * failure path is a line in a log file on the customer's machine. From the
 * panel the machine that never checked and the machine that refused a bad
 * signature looked identical: both still on the old version.
 *
 * These four columns are the agent's own account of it. They are reported on
 * the ordinary heartbeat, so they cost nothing and arrive from a device that
 * is by definition reachable.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        // @sql-identifier The table name carries the configured prefix and
        // cannot be a bound parameter; the prefix is sanitised at install.
        $pdo->exec(
            'ALTER TABLE `' . $table . '`
             ADD COLUMN `update_state`
                 ENUM(\'idle\',\'offered\',\'downloading\',\'installed\',\'failed\')
                 NOT NULL DEFAULT \'idle\' AFTER `agent_version`,
             ADD COLUMN `update_version` VARCHAR(32) NULL AFTER `update_state`,
             ADD COLUMN `update_error` VARCHAR(255) NULL AFTER `update_version`,
             ADD COLUMN `update_checked_at` DATETIME NULL AFTER `update_error`'
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        // @sql-identifier As above — an identifier, not a value.
        $pdo->exec(
            'ALTER TABLE `' . $table . '`
             DROP COLUMN `update_state`,
             DROP COLUMN `update_version`,
             DROP COLUMN `update_error`,
             DROP COLUMN `update_checked_at`'
        );
    },
];
