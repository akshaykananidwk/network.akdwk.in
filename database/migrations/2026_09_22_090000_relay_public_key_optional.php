<?php

declare(strict_types=1);

/**
 * The relay has no public key, so stop demanding one.
 *
 * A relay authorises a session from the HMAC ticket the coordinator signed,
 * using the shared secret in `AKCONNECT_RELAY_SECRET`. It has no Curve25519
 * keypair, generates none and is never asked for one. The column, the NOT NULL
 * and the unique index on it are all left over from an earlier design where
 * agents were going to talk to relays directly.
 *
 * The form required it anyway, so registering a real relay meant inventing a
 * key — which a production deployment did, with a random placeholder, because
 * there was nothing else to put there. A unique index over invented values is
 * a collision waiting to happen and a field nobody can fill in correctly is
 * worse than no field.
 *
 * The column stays, nullable, rather than being dropped: an agent that has
 * cached a configuration still expects the key in the JSON, and a column that
 * is NULL reads back as an empty string there. Dropping it is a separate
 * change for a release where no old agent is in the field.
 *
 * Reversible: down() puts the NOT NULL back, filling any NULLs with a
 * placeholder so the constraint can be applied.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'relays');

        $nullable = $pdo->query(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'public_key'"
        )->fetchColumn();

        if ($nullable === 'NO') {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . $table . '` MODIFY COLUMN `public_key` VARCHAR(64) NULL DEFAULT NULL'
            );
        }

        // Placeholders somebody had to invent to get past the old form. They
        // are not keys, they are in a unique index, and leaving them there
        // means the next relay registered with the same trick collides.
        $pdo->exec(
            // @sql-identifier As above — an identifier, not a value.
            'UPDATE `' . $table . '` SET `public_key` = NULL
             WHERE `public_key` IS NOT NULL AND `public_key` <> \'\''
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'relays');
        $safe = str_replace('`', '', $table);

        // The constraint cannot go back over NULLs, and two relays cannot
        // share one placeholder because the index is unique — so each gets its
        // own, derived from its id.
        // @sql-identifier As above — an identifier, not a value.
        $pdo->exec(
            'UPDATE `' . $safe . '` SET `public_key` = CONCAT(\'legacy-\', `id`) WHERE `public_key` IS NULL'
        );
        // @sql-identifier As above.
        $pdo->exec('ALTER TABLE `' . $safe . '` MODIFY COLUMN `public_key` VARCHAR(64) NOT NULL');
    },
];
