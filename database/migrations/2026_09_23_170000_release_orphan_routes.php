<?php

declare(strict_types=1);

/**
 * Free the ranges held by devices that no longer exist.
 *
 * Two ways a range became permanently unavailable, both fixed in code by
 * 1.9.7-dev.12 and both leaving rows behind on every panel that ran an
 * earlier build:
 *
 *   - a route whose gateway device was deleted or revoked. Deleting a device
 *     did not withdraw its shares, so the route stayed live, still holding
 *     the customer's range and the mapped prefix allocated to it. A PC
 *     reinstalled and re-enrolled was refused permission to share the network
 *     it had been sharing an hour earlier, told the range belonged to a
 *     device with its own hostname that appeared in no list.
 *   - a soft-deleted route. `routes` carries a generated unique column made
 *     of network, destination and device which does not include deleted_at,
 *     so a withdrawn route went on reserving that combination for ever.
 *
 * Both are hard-deleted here. A route through a device that does not exist
 * cannot carry a packet, and a withdrawn one is configuration that was turned
 * off — the audit log holds what was withdrawn, by whom and when, which is
 * where that history belongs.
 *
 * Deliberately NOT touching routes whose device is alive. A range shared by a
 * working gateway is in use, whatever anybody thinks of it.
 *
 * @return array{up: callable, down: callable}
 */
return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $routes = str_replace('`', '', $prefix . 'routes');
        $devices = str_replace('`', '', $prefix . 'devices');

        // @sql-identifier Table names are identifiers and cannot be bound;
        // the prefix is sanitised at install.
        $pdo->exec(
            'DELETE r FROM `' . $routes . '` r
             LEFT JOIN `' . $devices . '` d ON d.id = r.via_device_id
             WHERE d.id IS NULL
                OR d.deleted_at IS NOT NULL
                OR d.status = \'revoked\''
        );

        // @sql-identifier As above.
        $pdo->exec('DELETE FROM `' . $routes . '` WHERE deleted_at IS NOT NULL');
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        // Nothing to restore: these rows described routes that could not
        // carry a packet, and the ranges they held are the point of removing
        // them. Re-creating them would re-block the shares somebody has since
        // made.
    },
];
