<?php

declare(strict_types=1);

/**
 * Add the "edge" update channel.
 *
 * Until now the channel was stored, shown in a dropdown and validated — and
 * then never consulted. The updater always installed the head of the
 * configured branch, so an operator who had chosen "Stable" was running
 * whatever had been pushed most recently. Unfinished work reached a live panel
 * that way, which is what this exists to stop.
 *
 * Stable and beta now mean tagged releases. Following the branch is still
 * available and is still sometimes what you want, but it becomes a third thing
 * chosen on purpose rather than what the other two silently did.
 *
 * Existing rows keep whatever they say. A panel on "stable" simply stops being
 * offered untagged commits the moment this lands.
 *
 * Reversible: down() moves anything on `edge` back to `stable` first, because
 * the column cannot hold a value the narrowed enum does not have.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'update_settings');

        // @sql-identifier The table name carries the configured prefix and
        // cannot be a bound parameter; the prefix is sanitised at install.
        $pdo->exec(
            'ALTER TABLE `' . $table . '`
             MODIFY COLUMN `channel` ENUM(\'stable\',\'beta\',\'edge\')
             NOT NULL DEFAULT \'stable\''
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'update_settings');

        // @sql-identifier As above.
        $pdo->exec('UPDATE `' . $table . '` SET `channel` = \'stable\' WHERE `channel` = \'edge\'');

        $pdo->exec(
            'ALTER TABLE `' . $table . '`
             MODIFY COLUMN `channel` ENUM(\'stable\',\'beta\')
             NOT NULL DEFAULT \'stable\''
        );
    },
];
