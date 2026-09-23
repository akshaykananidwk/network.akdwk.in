<?php

declare(strict_types=1);

/**
 * A join code an administrator has decided to trust in advance.
 *
 * R4 says no device is trusted until an administrator approves it, and that
 * stays true: what changes is *when* the administrator decides. A pre-approved
 * code is an explicit, audited, revocable decision taken before the device
 * exists — "the next machine to present this code, once, in the next fifteen
 * minutes, is mine" — rather than a click after it appears.
 *
 * It exists because the alternative was worse. The acceptance test for a
 * customer install is one double-click and nothing else, and an approval click
 * in between means the customer sits looking at a machine that says it is
 * waiting, with no way to know for how long. Pre-approval moves the human
 * decision to the moment the technician issues the code, which is when they
 * are actually present and paying attention.
 *
 * The guardrails are the point, so they are columns rather than conventions:
 * an issuing administrator is recorded (created_by already), the code is
 * single- or limited-use (max_uses already), short-lived (expires_at already)
 * and revocable (revoked_at already). This migration adds only the flag.
 *
 * Reversible.
 *
 * @return array{up: callable, down: callable}
 */

return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'join_codes');

        $exists = $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'pre_approved'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            // @sql-identifier The table name carries the configured prefix and
            // cannot be a bound parameter; the prefix is sanitised at install.
            $pdo->exec(
                'ALTER TABLE `' . $table . '` ADD COLUMN `pre_approved` TINYINT(1) NOT NULL DEFAULT 0
                 AFTER `max_uses`'
            );
        }
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'join_codes');

        $exists = $pdo->query(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ' . $pdo->quote($table) . "
               AND COLUMN_NAME = 'pre_approved'"
        )->fetchColumn();

        if ((int) $exists === 1) {
            // @sql-identifier As above — an identifier, not a value.
            $pdo->exec('ALTER TABLE `' . $table . '` DROP COLUMN `pre_approved`');
        }
    },
];
