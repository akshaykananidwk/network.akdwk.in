#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Put THIS PANEL into maintenance, and take it out again.
 *
 * Usage:
 *   php cli/maintenance.php on      "Upgrading the panel"   [--allow=1.2.3.4]
 *   php cli/maintenance.php off
 *   php cli/maintenance.php status
 *
 * Why this exists
 * ---------------
 * The panel shares its server with other people's websites and their mail.
 * Every instruction for testing what happens when the panel is unavailable
 * must therefore affect the panel and nothing else — stopping Apache would
 * take down every site on the machine to test one of them, which is a
 * disproportionate way to answer a question and has no place in a runbook.
 *
 * This writes a flag file the panel's own middleware reads. Apache keeps
 * running, every other virtual host keeps serving, mail is untouched, and
 * requests to this panel get 503 with a Retry-After.
 *
 * What still works while it is on
 * -------------------------------
 * Nothing on the panel, by design — that is the point of the drill. The
 * coordinator, the relays and every agent keep running, and from 1.9.7-dev.14
 * a relayed pair carries traffic straight through a panel outage: the
 * coordinator keeps deciding from the last answer the panel gave it. That is
 * R6, and this command is how it is tested without touching anybody else.
 *
 * --allow keeps one address able to reach the panel, so whoever is running
 * the drill is not locked out of the thing they are testing.
 */

require __DIR__ . '/_bootstrap.php';

use App\Updater\MaintenanceMode;

$command = $argv[1] ?? 'status';
$reason = $argv[2] ?? 'Maintenance';

$allowed = [];
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--allow=')) {
        $allowed[] = substr($argument, strlen('--allow='));
    }
}

$maintenance = MaintenanceMode::make();

switch ($command) {
    case 'on':
        $token = $maintenance->enable($reason, $allowed, 300);

        echo "Panel maintenance is ON.\n";
        echo "  flag      : ", $maintenance->flagPath(), "\n";
        echo "  reason    : ", $reason, "\n";
        echo "  bypass    : ?maintenance_bypass=", $token, "\n";
        if ($allowed !== []) {
            echo "  allowed   : ", implode(', ', $allowed), "\n";
        }
        echo "\n";
        echo "Only this panel answers 503. Every other site on this server, and mail,\n";
        echo "are untouched — Apache has not been stopped and must never be, to test this.\n";
        echo "\nTurn it off with:  php cli/maintenance.php off\n";

        return 0;

    case 'off':
        $maintenance->disable();

        echo "Panel maintenance is OFF. The panel is answering normally again.\n";

        return 0;

    case 'status':
    default:
        if (!$maintenance->isEnabled()) {
            echo "Panel maintenance is OFF.\n";

            return 0;
        }

        $details = $maintenance->details() ?? [];

        echo "Panel maintenance is ON.\n";
        echo "  reason : ", (string) ($details['reason'] ?? 'unknown'), "\n";
        echo "  since  : ", (string) ($details['enabled_at'] ?? 'unknown'), "\n";
        echo "  flag   : ", $maintenance->flagPath(), "\n";

        return 0;
}
