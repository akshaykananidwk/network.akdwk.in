<?php

declare(strict_types=1);

/**
 * A device on a network that carries no UDP at all.
 *
 * `relay` already meant "this device's traffic goes through our servers", and
 * that covered two situations an operator has to tell apart. One is a pair
 * behind symmetric NAT that could not punch through and may well manage it
 * tomorrow from a different address. The other is a machine on a network that
 * passes nothing but the port a browser uses — a hotel, a guest VLAN, an
 * office firewall that drops the replies — where the agent is carrying both
 * its control messages and its tunnel traffic over TCP 443 and will never
 * reach a peer directly from there however long anybody waits.
 *
 * The first clears itself. The second is a property of the building, and the
 * only useful response to it is to stop waiting for it to clear.
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
             ENUM(\'direct\',\'relay\',\'relay_https\',\'connecting\',\'offline\')
             NOT NULL DEFAULT \'offline\''
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'devices');

        // Folded back into the value it is a refinement of, so no row is left
        // holding a state the column can no longer express.
        // @sql-identifier As above — an identifier, not a value.
        $pdo->exec(
            'UPDATE `' . $table . '` SET `connection_type` = \'relay\'
             WHERE `connection_type` = \'relay_https\''
        );

        // @sql-identifier As above.
        $pdo->exec(
            'ALTER TABLE `' . $table . '`
             MODIFY COLUMN `connection_type`
             ENUM(\'direct\',\'relay\',\'connecting\',\'offline\') NOT NULL DEFAULT \'offline\''
        );
    },
];
