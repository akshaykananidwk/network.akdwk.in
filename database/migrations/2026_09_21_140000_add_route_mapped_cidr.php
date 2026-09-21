<?php

declare(strict_types=1);

/**
 * A virtual prefix for every advertised LAN.
 *
 * Nearly every router sold in India hands out 192.168.1.0/24 or
 * 192.168.0.0/24. A support laptop sitting on one of them cannot reach a
 * customer whose LAN is the same range: one routing table cannot hold two
 * routes for one destination, and taking the customer's would cut the laptop
 * off from the printer beside it.
 *
 * So the overlay never sees the customer's real range. Each advertised LAN is
 * given a unique prefix out of a pool — 192.168.1.0/24 at a hotel appears as,
 * say, 10.201.5.0/24 — and the gateway rewrites one to the other, host part
 * preserved, so the NVR at 192.168.1.50 is reached at 10.201.5.50. Rules are
 * still written about 192.168.1.50, because that is the address on the sticker
 * on the recorder.
 *
 * NULL is allowed so that routes advertised before this migration keep working
 * unmapped until they are re-advertised; nothing infers a mapping that was
 * never allocated.
 *
 * Reversible: down() drops the column.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'routes';

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'mapped_cidr'"
        )->fetchColumn();

        if ($exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . str_replace('`', '', $table) . '`
                 ADD COLUMN `mapped_cidr` VARCHAR(20) NULL DEFAULT NULL AFTER `destination_cidr`'
            );
        }

        $safe = str_replace('`', '', $table);

        // Unique, not merely indexed. Allocation reads the prefixes already in
        // use and then writes, so two advertisements racing could both pick the
        // same one — and two LANs sharing a virtual prefix is exactly the
        // collision the virtual prefix exists to prevent, arrived at from the
        // other direction. The index turns that race into an error instead of
        // a silent duplicate. It also answers "is this taken in this network?"
        // without a scan, which is what allocation asks on every advertisement.
        //
        // MySQL permits many NULLs in a unique index, so routes from before
        // this migration coexist.
        $indexed = $pdo->query(
            'SELECT NON_UNIQUE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND INDEX_NAME = 'idx_routes_mapped'
             LIMIT 1"
        )->fetchColumn();

        if ($indexed !== false && (int) $indexed === 1) {
            // An earlier form of this migration created it non-unique.
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec('ALTER TABLE `' . $safe . '` DROP INDEX `idx_routes_mapped`');
            $indexed = false;
        }

        if ($indexed === false) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec(
                'ALTER TABLE `' . $safe . '`
                 ADD UNIQUE INDEX `idx_routes_mapped` (`network_id`, `mapped_cidr`)'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = $prefix . 'routes';
        $safe = str_replace('`', '', $table);

        $indexed = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND INDEX_NAME = 'idx_routes_mapped'"
        )->fetchColumn();

        if ($indexed > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec('ALTER TABLE `' . $safe . '` DROP INDEX `idx_routes_mapped`');
        }

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'mapped_cidr'"
        )->fetchColumn();

        if ($exists > 0) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec('ALTER TABLE `' . $safe . '` DROP COLUMN `mapped_cidr`');
        }
    },
];
