<?php

declare(strict_types=1);

/**
 * How each device reaches each of its peers: directly, or through the server.
 *
 * The agent has sent this on every heartbeat since 1.9.2 — one entry per peer,
 * with the path it is using — and the panel threw it away. What it showed
 * instead was one word for the whole device, taken from whether any WireGuard
 * handshake had happened lately: "connecting", in blue, next to "Online", for
 * a device that was running and heartbeating and simply had not needed a
 * peer. That word was a pair fact dressed up as a device fact.
 *
 * Now the device's own status is Online or Offline and nothing else, and the
 * path is kept here per pair and shown as a small label: "direct" or "via
 * server". `since_at` moves only when the path changes, so the page can say
 * how long a pair has been on the path it is on.
 *
 * @return array{up: callable, down: callable}
 */
return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'device_links');
        $devices = str_replace('`', '', $prefix . 'devices');

        // @sql-identifier Table names are identifiers and cannot be bound;
        // the prefix is sanitised at install.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT UNSIGNED NOT NULL,
                `device_id` BIGINT UNSIGNED NOT NULL,
                `peer_device_id` BIGINT UNSIGNED NOT NULL,
                `path` ENUM(\'direct\',\'server\',\'none\') NOT NULL DEFAULT \'none\',
                `transport` VARCHAR(16) NULL,
                `latency_ms` INT NULL,
                `since_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_link_pair` (`device_id`, `peer_device_id`),
                KEY `idx_link_tenant` (`tenant_id`),
                KEY `idx_link_peer` (`peer_device_id`),
                CONSTRAINT `fk_' . $table . '_device` FOREIGN KEY (`device_id`)
                    REFERENCES `' . $devices . '` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_' . $table . '_peer` FOREIGN KEY (`peer_device_id`)
                    REFERENCES `' . $devices . '` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'device_links');

        // @sql-identifier As above.
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    },
];
