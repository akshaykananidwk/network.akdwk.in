#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Apply updates from the command line.
 *
 * Drives the identical step machine the web UI uses (UpdateManager), so there
 * is no second implementation to keep in sync. Useful for large installs where
 * a browser tab would time out, and for scripted deployments.
 *
 * Usage:
 *   php cli/update.php --check          report whether an update is available
 *   php cli/update.php --apply          apply it, with backup and rollback
 *   php cli/update.php --apply --yes    apply without the confirmation prompt
 *   php cli/update.php --rollback=12    roll back update #12
 *   php cli/update.php --status         show the state of the current run
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\UpdateException;
use App\Models\AppUpdate;
use App\Models\UpdateSetting;
use App\Updater\UpdateManager;

$options = cli_options($argv);
$manager = UpdateManager::make();

try {
    if (isset($options['status'])) {
        $running = AppUpdate::running();
        if ($running === null) {
            $latest = AppUpdate::latest();
            cli_out($latest === null
                ? 'No updates have been run on this installation.'
                : sprintf('No update running. Last: #%d %s → %s (%s)',
                    $latest['id'], $latest['from_version'], $latest['to_version'], $latest['status']));
            exit(0);
        }

        $status = $manager->status((int) $running['id']);
        cli_out(sprintf('Update #%d is at %s (%d%%), status %s',
            $status['update_id'], $status['step'], $status['progress'], $status['status']));
        foreach (array_slice($status['log'], -20) as $line) {
            cli_out(cli_colour('  ' . $line, 'grey'));
        }
        exit(0);
    }

    if (isset($options['rollback'])) {
        $updateId = (int) $options['rollback'];
        if ($updateId <= 0) {
            cli_fail('Usage: --rollback=<update id>');
            exit(1);
        }

        if (!isset($options['yes']) && !cli_confirm(sprintf(
            'Roll back update #%d? Files are restored from its journal and the database from its backup.',
            $updateId
        ))) {
            cli_out('Cancelled.');
            exit(0);
        }

        cli_heading('Rolling back update #' . $updateId);
        $result = $manager->rollback($updateId, 'Rollback requested from the command line');

        foreach ($result['steps'] as $stepMessage) {
            cli_ok($stepMessage);
        }
        foreach ($result['failures'] as $failure) {
            cli_fail($failure);
        }

        if ($result['ok']) {
            cli_out('');
            cli_ok('Rollback complete. The previous version is live again.');
            exit(0);
        }

        cli_out('');
        cli_fail('Rollback incomplete. Maintenance mode has been left ON deliberately.');
        cli_out('');
        cli_out(cli_colour('Recover manually:', 'bold'));
        foreach ((array) ($result['recovery']['manual_steps'] ?? []) as $command) {
            cli_out('  ' . $command);
        }
        exit(1);
    }

    // ------------------------------------------------------------- check

    if (!UpdateSetting::isConfigured()) {
        cli_fail('Updates are not configured. Set the repository and token under System → Updates.');
        exit(1);
    }

    cli_heading('Checking for updates');
    $check = $manager->checkForUpdate(true);

    if (!($check['configured'] ?? false)) {
        cli_fail((string) ($check['message'] ?? 'Not configured.'));
        exit(1);
    }

    cli_out(sprintf('  Repository : %s@%s', $check['repo'], $check['branch']));
    cli_out(sprintf('  Installed  : %s (%s)', $check['current_version'],
        substr((string) $check['current_commit'], 0, 7) ?: 'unknown commit'));

    if ($check['up_to_date']) {
        cli_out('');
        cli_ok('You are on the latest version.');
        exit(0);
    }

    cli_out(sprintf('  Available  : %s (%s)', $check['new_version'], substr((string) $check['latest_commit']['sha'], 0, 7)));

    if ($check['commits_behind'] !== null) {
        cli_out(sprintf('  Behind by  : %d commit(s)', $check['commits_behind']));
    }
    if (($check['notes'] ?? '') !== '') {
        cli_out(sprintf('  Notes      : %s', $check['notes']));
    }
    if ($check['files'] !== null) {
        cli_out(sprintf('  Files      : %d added, %d modified, %d removed',
            $check['files']['added'], $check['files']['modified'], $check['files']['removed']));
    }
    if (($check['migrations'] ?? []) !== []) {
        cli_out('  Migrations : ' . implode(', ', $check['migrations']));
    }
    if ($check['breaking']) {
        cli_out('');
        cli_warn('This release contains BREAKING CHANGES. Read the release notes first.');
    }
    if (!($check['requirements']['ok'] ?? true)) {
        cli_out('');
        foreach ($check['requirements']['failures'] as $failure) {
            cli_fail($failure);
        }
        exit(1);
    }
    if (($check['signature']['required'] ?? false) && ($check['signature']['valid'] ?? false) !== true) {
        cli_out('');
        cli_fail('Signature verification is enabled and this manifest is unsigned or invalid. Refusing.');
        exit(1);
    }

    if (!isset($options['apply'])) {
        cli_out('');
        cli_out('Run with --apply to install it.');
        exit(0);
    }

    // ------------------------------------------------------------- apply

    if (!isset($options['yes']) && !cli_confirm(sprintf(
        'Apply %s → %s? A full backup is taken first and a failure rolls back automatically.',
        $check['current_version'],
        $check['new_version']
    ))) {
        cli_out('Cancelled.');
        exit(0);
    }

    cli_heading('Applying update');
    $begin = $manager->begin('cli');
    cli_out(cli_colour(sprintf('  Update #%d started.', $begin['update_id']), 'grey'));
    cli_out('');

    $final = $manager->runToCompletion($begin['update_id'], static function (array $result): void {
        $marker = $result['status'] === 'failed' ? '✗' : '✓';
        $colour = $result['status'] === 'failed' ? 'red' : 'green';
        cli_out(sprintf('  %s %-14s %s',
            cli_colour($marker, $colour),
            $result['step'],
            $result['message']
        ));
    });

    cli_out('');

    if ($final['status'] === 'success') {
        cli_ok('Update complete. Now running ' . $check['new_version'] . '.');
        exit(0);
    }

    if ($final['status'] === 'rolled_back') {
        cli_warn('Update failed and was rolled back. The previous version is live.');
        cli_out('  ' . $final['message']);
        exit(2);
    }

    cli_fail('Update failed: ' . $final['message']);
    cli_out('  See: php cli/update.php --status');
    exit(1);
} catch (UpdateException $e) {
    cli_fail($e->getMessage());
    exit(1);
} catch (Throwable $e) {
    cli_fail('Unexpected error: ' . $e->getMessage());
    \App\Core\Logger::error('update', 'CLI update failed', ['error' => $e->getMessage()]);
    exit(1);
}

function cli_confirm(string $question): bool
{
    cli_out('');
    fwrite(STDOUT, $question . ' [y/N] ');
    $answer = trim((string) fgets(STDIN));

    return in_array(strtolower($answer), ['y', 'yes'], true);
}
