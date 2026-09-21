<?php

declare(strict_types=1);

/**
 * Names for the machines behind a gateway.
 *
 * §18 asks for `nvr.hotel-abc.<network>.internal`. The overlay devices can be
 * named from the devices table, but the machines behind a gateway are not
 * devices — an NVR, a printer, a DVR have no agent, no key and no row anywhere.
 * They are addresses inside an advertised range, and this is where an operator
 * writes down which address is which.
 *
 * It matters more since subnet mapping. The address a technician actually
 * connects to is one the panel invented — 10.128.0.50 rather than
 * 192.168.1.50 — and expecting somebody to carry that in their head, per site,
 * is how a feature goes unused.
 *
 * The address stored is the **real** one, the one on the label on the machine.
 * The mapped address is derived, because the mapping can change if a route is
 * withdrawn and re-advertised and a stored copy would then be wrong.
 *
 * Reversible: down() drops the table.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'route_hosts');
        $routes = str_replace('`', '', $prefix . 'routes');

        // @sql-identifier Table names carry the configured prefix and cannot be
        // bound parameters; the prefix is sanitised at install.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT UNSIGNED NOT NULL,
                `network_id` BIGINT UNSIGNED NOT NULL,
                `route_id` BIGINT UNSIGNED NOT NULL,
                `label` VARCHAR(63) NOT NULL,
                `address` VARCHAR(45) NOT NULL,
                `description` VARCHAR(190) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                -- One label per route, and one row per address: two machines
                -- answering to one name, or one machine with two names, are
                -- both ways for a technician to reach the wrong box.
                UNIQUE KEY `uq_route_hosts_label` (`route_id`, `label`),
                UNIQUE KEY `uq_route_hosts_address` (`route_id`, `address`),
                KEY `idx_route_hosts_network` (`network_id`),
                KEY `idx_route_hosts_tenant` (`tenant_id`),
                CONSTRAINT `fk_route_hosts_route` FOREIGN KEY (`route_id`)
                    REFERENCES `' . $routes . '` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'route_hosts');

        // @sql-identifier As above — an identifier, not a value.
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    },
];
