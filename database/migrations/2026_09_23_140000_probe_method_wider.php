<?php

declare(strict_types=1);

/**
 * Room for the probe to say WHERE it tested, not just how.
 *
 * A gateway asked to check the router on the network it shares was sending to
 * the mapped address — 10.128.5.1 rather than 192.168.10.1 — and its own
 * machine has no route for that: the mapping is applied to traffic arriving
 * from the overlay, not to traffic the gateway itself originates. It reported
 * the router unreachable while a browser on the same machine was showing that
 * router's login page.
 *
 * The agent translates now, and the result has to say so. "icmp" in sixteen
 * characters cannot carry "tested directly on the LAN", and a result that
 * surprises somebody has to explain itself or it will be argued with.
 *
 * @return array{up: callable, down: callable}
 */
return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'device_probes');

        // @sql-identifier A table name is an identifier and cannot be bound;
        // the prefix is sanitised at install.
        $pdo->exec('ALTER TABLE `' . $table . '` MODIFY COLUMN `method` VARCHAR(64) NULL');
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'device_probes');

        // @sql-identifier As above.
        $pdo->exec('ALTER TABLE `' . $table . '` MODIFY COLUMN `method` VARCHAR(16) NULL');
    },
];
