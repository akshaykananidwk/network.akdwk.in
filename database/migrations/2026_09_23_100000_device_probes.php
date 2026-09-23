<?php

declare(strict_types=1);

/**
 * Reachability tests an administrator asks a device to run.
 *
 * "Is the camera recorder reachable from the shop PC?" is the question this
 * product exists to answer, and until now the only way to answer it was to
 * telephone somebody and ask them to open a command prompt. A support person
 * with the panel open and no access to the machines could see that two devices
 * were Online and nothing at all about whether they could reach each other.
 *
 * A row here is one request and its answer. The panel writes it pending, the
 * agent collects it on its next poll, runs it, and reports back on the
 * heartbeat it was going to send anyway. Nothing is pushed to the device and
 * no port is opened on it: the direction of travel is the same as every other
 * instruction the agent takes.
 *
 * Kept rather than deleted once answered, because "it worked at 11:04 and not
 * at 11:40" is the useful form of this information, and because an audit trail
 * of who asked a device to probe what belongs in the database rather than in
 * somebody's memory.
 *
 * @return array{up: callable, down: callable}
 */
return [
    'up' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'device_probes');
        $devices = str_replace('`', '', $prefix . 'devices');

        // @sql-identifier Table names are identifiers and cannot be bound;
        // the prefix is sanitised at install.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT UNSIGNED NOT NULL,
                `device_id` BIGINT UNSIGNED NOT NULL,
                `target` VARCHAR(64) NOT NULL,
                `label` VARCHAR(128) NULL,
                `state` ENUM(\'pending\',\'ok\',\'failed\') NOT NULL DEFAULT \'pending\',
                `latency_ms` INT NULL,
                `method` VARCHAR(16) NULL,
                `error` VARCHAR(255) NULL,
                `requested_by` BIGINT UNSIGNED NULL,
                `requested_at` DATETIME NOT NULL,
                `answered_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_probe_device` (`device_id`, `id`),
                KEY `idx_probe_tenant` (`tenant_id`),
                CONSTRAINT `fk_' . $table . '_device` FOREIGN KEY (`device_id`)
                    REFERENCES `' . $devices . '` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    },

    'down' => static function (PDO $pdo, string $prefix): void {
        $table = str_replace('`', '', $prefix . 'device_probes');

        // @sql-identifier As above.
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    },
];
