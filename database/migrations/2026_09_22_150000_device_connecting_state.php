<?php

declare(strict_types=1);

/**
 * "Offline" must mean "not heartbeating".
 *
 * It did not. The agent reported `connection_type` as a judgement about its
 * *peers* — "offline" when no peer had handshaked recently — and the panel
 * rendered that as a red dot labelled Offline on the device itself. So two
 * machines that were both running, both heartbeating every ten seconds, both
 * reporting their endpoints and both saying the coordinator was reachable
 * showed as offline, because they had not yet found each other.
 *
 * An administrator looking at that page cannot tell "this machine is switched
 * off" from "these two machines have not connected to each other yet", and
 * those need completely different responses.
 *
 * So the column becomes what it always described — the path to the peers — and
 * gains the value it was missing: `connecting`, which is what an agent that is
 * running and has not yet established a path actually is. Whether the *device*
 * is online is answered by `last_seen_at`, which is the only thing that can
 * answer it.
 *
 * `offline` stays in the enum, and stays meaningful: it is what the staleness
 * sweep writes when a device has stopped heartbeating altogether. Nothing an
 * agent sends can produce it any more.
 *
 * Reversible: down() maps `connecting` back to `offline` before narrowing the
 * enum, since there is nowhere else for those rows to go.
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
             MODIFY COLUMN `connection_type`
             ENUM(\'direct\',\'relay\',\'connecting\',\'offline\') NOT NULL DEFAULT \'offline\''
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        // @sql-identifier As above — an identifier, not a value.
        $pdo->exec(
            'UPDATE `' . $table . '` SET `connection_type` = \'offline\' WHERE `connection_type` = \'connecting\''
        );

        // @sql-identifier As above.
        $pdo->exec(
            'ALTER TABLE `' . $table . '`
             MODIFY COLUMN `connection_type`
             ENUM(\'direct\',\'relay\',\'offline\') NOT NULL DEFAULT \'offline\''
        );
    },
];
