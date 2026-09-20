#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scheduled maintenance. Intended to run every five minutes from cron; the
 * exact crontab line is printed by the installer and repeated in README.md
 * (it cannot be written here — its slash-star sequence would close this
 * comment).
 *
 * Each task decides for itself whether it is due, so the schedule lives here
 * rather than in six separate crontab lines. A task that throws is logged and
 * the others still run — one broken job must not stop the offline sweep.
 */

require __DIR__ . '/_bootstrap.php';

use App\Core\Config;
use App\Core\DbSessionHandler;
use App\Core\Logger;
use App\Core\RateLimit;
use App\Middleware\TenantScope;
use App\Models\AppUpdate;
use App\Models\Device;
use App\Models\Job;
use App\Models\JoinCode;
use App\Models\PasswordReset;
use App\Models\Relay;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\UpdateSetting;
use App\Models\UsageCounter;
use App\Services\NotificationService;
use App\Updater\BackupManager;
use App\Updater\UpdateManager;

$options = cli_options($argv);
$verbose = isset($options['verbose']);

$lock = cli_lock('worker');
if ($lock === false) {
    // A previous run is still going; that is normal for a slow backup.
    exit(0);
}

$started = microtime(true);
$results = [];

/** Run one task, catching its failures so the rest still run. */
function task(string $name, callable $callback, array &$results, bool $verbose): void
{
    $taskStart = microtime(true);

    try {
        $summary = $callback();
        $results[$name] = ['ok' => true, 'summary' => $summary, 'ms' => (int) round((microtime(true) - $taskStart) * 1000)];

        if ($verbose && $summary !== null && $summary !== '') {
            cli_ok(sprintf('%-22s %s', $name, $summary));
        }
    } catch (Throwable $e) {
        $results[$name] = ['ok' => false, 'summary' => $e->getMessage(), 'ms' => (int) round((microtime(true) - $taskStart) * 1000)];
        Logger::error('app', 'Worker task failed', ['task' => $name, 'error' => $e->getMessage()]);

        if ($verbose) {
            cli_fail(sprintf('%-22s %s', $name, $e->getMessage()));
        }
    }
}

if ($verbose) {
    cli_heading('Worker run at ' . gmdate('c'));
}

// ------------------------------------------------------------- presence

task('devices.offline', static function (): string {
    // Three missed heartbeats before a device is called offline, so a single
    // dropped packet does not flap the dashboard.
    $marked = Device::markStaleOffline(90);

    return $marked > 0 ? $marked . ' device(s) marked offline' : 'no change';
}, $results, $verbose);

task('relays.stale', static function (): string {
    $marked = Relay::markStaleDown(180);

    if ($marked > 0) {
        NotificationService::notifySuperAdmins(
            'critical',
            $marked . ' relay(s) stopped responding',
            'Relays that miss their heartbeat are marked down and agents stop selecting them. '
            . 'Check the relay hosts.',
            'admin/relays',
            'relay'
        );
    }

    return $marked > 0 ? $marked . ' relay(s) marked down' : 'all healthy';
}, $results, $verbose);

// ---------------------------------------------------------------- queue

task('jobs.process', static function (): string {
    $workerId = gethostname() . ':' . getmypid();
    $processed = 0;
    $deadline = time() + 120; // leave room for the other tasks

    while (time() < $deadline) {
        $job = Job::reserve('default', $workerId);
        if ($job === null) {
            break;
        }

        try {
            // Job types are registered here rather than looked up dynamically:
            // a payload must never be able to name the class it runs.
            $payload = (array) ($job['payload_json'] ?? []);

            match ((string) $job['job_type']) {
                'notify.email' => NotificationService::notifyUser(
                    (int) ($payload['user_id'] ?? 0),
                    (string) ($payload['level'] ?? 'info'),
                    (string) ($payload['title'] ?? ''),
                    (string) ($payload['body'] ?? ''),
                    (string) ($payload['link'] ?? ''),
                    (string) ($payload['category'] ?? ''),
                    true
                ),
                default => throw new RuntimeException('Unknown job type: ' . $job['job_type']),
            };

            Job::complete((int) $job['id']);
            $processed++;
        } catch (Throwable $e) {
            Job::fail((int) $job['id'], (int) $job['attempts'], (int) $job['max_attempts'], $e->getMessage());
        }
    }

    return $processed > 0 ? $processed . ' job(s) processed' : 'queue empty';
}, $results, $verbose);

// --------------------------------------------------------------- updates

task('updates.check', static function (): string {
    $settings = UpdateSetting::current();

    if ((int) $settings['auto_check'] !== 1 || !UpdateSetting::isConfigured()) {
        return 'auto-check off';
    }

    $intervalHours = max(1, (int) $settings['check_interval_hours']);
    $lastChecked = $settings['last_checked_at'] !== null ? strtotime((string) $settings['last_checked_at'] . ' UTC') : 0;

    if ($lastChecked > 0 && (time() - $lastChecked) < $intervalHours * 3600) {
        return 'not due yet';
    }

    $manager = UpdateManager::make();
    $check = $manager->checkForUpdate(true);

    if (($check['up_to_date'] ?? true) || !($check['configured'] ?? false)) {
        return 'up to date';
    }

    $version = (string) $check['new_version'];

    // Notify once per version rather than every interval.
    if (Setting::get('update.notified_version') !== $version) {
        NotificationService::notifySuperAdmins(
            $check['breaking'] ? 'warning' : 'info',
            'Update available: ' . $version,
            sprintf(
                "Version %s is available (currently %s).\n%s%s\n\nOpen System → Updates to review and apply it.",
                $version,
                $check['current_version'],
                $check['breaking'] ? "\nThis release contains BREAKING CHANGES.\n" : '',
                $check['notes'] ?? ''
            ),
            'admin/updates',
            'update'
        );
        Setting::set('update.notified_version', $version);
    }

    // Auto-apply is opt-in and deliberately conservative: never on a breaking
    // release, never when the server fails the release's requirements.
    if ((int) $settings['auto_apply'] === 1) {
        if ($check['breaking']) {
            return $version . ' available — auto-apply skipped (breaking release)';
        }
        if (!($check['requirements']['ok'] ?? true)) {
            return $version . ' available — auto-apply skipped (requirements not met)';
        }
        if (AppUpdate::running() !== null) {
            return 'another update is already running';
        }

        $begin = $manager->begin('schedule');
        $final = $manager->runToCompletion((int) $begin['update_id']);

        return sprintf('auto-applied %s: %s', $version, $final['status']);
    }

    return $version . ' available';
}, $results, $verbose);

// --------------------------------------------------------------- backups

task('backups.scheduled', static function (): string {
    $schedule = Setting::get('backup.schedule', null, 'daily');
    if ($schedule === 'off') {
        return 'disabled';
    }

    $intervalSeconds = $schedule === 'weekly' ? 604800 : 86400;
    $lastRun = (int) Setting::getInt('backup.last_run_ts', null, 0);

    if ($lastRun > 0 && (time() - $lastRun) < $intervalSeconds) {
        return 'not due yet';
    }

    try {
        $backup = BackupManager::make()->createFull('scheduled');
        Setting::set('backup.last_run_ts', (string) time());

        return sprintf('backup #%d taken (%s)', $backup['id'], format_bytes((int) $backup['size_bytes']));
    } catch (Throwable $e) {
        NotificationService::notifySuperAdmins(
            'critical',
            'Scheduled backup failed',
            "The scheduled backup did not complete.\n\n" . $e->getMessage()
            . "\n\nWithout a recent backup, an update has nothing to roll back to.",
            'admin/backups',
            'backup'
        );
        throw $e;
    }
}, $results, $verbose);

task('backups.prune', static function (): string {
    $keep = (int) UpdateSetting::current()['backup_retention'];
    $result = BackupManager::make()->prune($keep);

    return $result['removed'] > 0
        ? sprintf('%d removed, %s freed', $result['removed'], format_bytes($result['freed_bytes']))
        : 'nothing to prune';
}, $results, $verbose);

// ------------------------------------------------------------- housekeeping

task('housekeeping', static function (): string {
    $counts = [];

    $counts[] = (new DbSessionHandler())->gc(0) . ' expired session(s)';
    $counts[] = PasswordReset::pruneExpired() . ' old reset token(s)';
    $counts[] = JoinCode::pruneExpired() . ' expired join code(s)';
    $counts[] = RateLimit::prune() . ' rate-limit bucket(s)';
    $counts[] = Job::pruneCompleted(7) . ' finished job(s)';
    $counts[] = Logger::prune((int) Config::get('logging.retention_days', 30)) . ' rotated log file(s)';
    $counts[] = \App\Models\AuditLog::prune(Setting::getInt('audit.retention_days', null, 365)) . ' old audit row(s)';

    return implode(', ', $counts);
}, $results, $verbose);

// ---------------------------------------------------------------- metering

task('usage.rollup', static function (): string {
    // Peak concurrent devices per tenant, for the usage graphs and for
    // spotting an account that has outgrown its plan.
    $rows = TenantScope::acrossAllTenants('usage rollup', static fn (): array => \App\Core\DB::select(
        'SELECT tenant_id, COUNT(*) AS online
         FROM ' . \App\Core\DB::table('devices') . '
         WHERE deleted_at IS NULL AND connection_type <> \'offline\'
         GROUP BY tenant_id'
    ));

    foreach ($rows as $row) {
        UsageCounter::setPeak((int) $row['tenant_id'], UsageCounter::METRIC_PEAK_DEVICES, (int) $row['online']);
    }

    return count($rows) . ' tenant(s) rolled up';
}, $results, $verbose);

// ------------------------------------------------------------------ alerts

task('alerts.expiring', static function (): string {
    // Once a day is plenty for a renewal reminder.
    $lastRun = Setting::getInt('alerts.expiry_last_ts', null, 0);
    if ($lastRun > 0 && (time() - $lastRun) < 86400) {
        return 'not due yet';
    }

    $expiring = Subscription::expiringWithin(7);
    foreach ($expiring as $subscription) {
        NotificationService::notifyTenantAdmins(
            (int) $subscription['tenant_id'],
            'warning',
            'Your subscription expires soon',
            sprintf(
                "Your subscription ends on %s.\n\nExisting tunnels keep working during the grace period, "
                . "but you will not be able to add devices or networks after it.",
                $subscription['current_period_end']
            ),
            'settings/billing',
            'billing',
            true
        );
    }

    Setting::set('alerts.expiry_last_ts', (string) time());

    return count($expiring) . ' expiry notice(s) sent';
}, $results, $verbose);

task('alerts.offline_devices', static function (): string {
    $threshold = Setting::getInt('alerts.device_offline_minutes', null, 30);
    if ($threshold <= 0) {
        return 'disabled';
    }

    $lastRun = Setting::getInt('alerts.offline_last_ts', null, 0);
    if ($lastRun > 0 && (time() - $lastRun) < 3600) {
        return 'not due yet';
    }

    $rows = TenantScope::acrossAllTenants('offline device alert', static fn (): array => \App\Core\DB::select(
        'SELECT tenant_id, COUNT(*) AS offline
         FROM ' . \App\Core\DB::table('devices') . '
         WHERE deleted_at IS NULL AND status = \'authorized\' AND connection_type = \'offline\'
           AND last_seen_at IS NOT NULL
           AND last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m MINUTE)
         GROUP BY tenant_id',
        ['m' => $threshold]
    ));

    foreach ($rows as $row) {
        NotificationService::notifyTenantAdmins(
            (int) $row['tenant_id'],
            'warning',
            sprintf('%d device(s) have been offline for over %d minutes', $row['offline'], $threshold),
            'Check whether those machines are powered on and can reach the internet.',
            'devices?status=authorized',
            'device'
        );
    }

    Setting::set('alerts.offline_last_ts', (string) time());

    return count($rows) . ' tenant(s) alerted';
}, $results, $verbose);

// ------------------------------------------------------------------ finish

$elapsed = (int) round((microtime(true) - $started) * 1000);
$failed = array_filter($results, static fn (array $r): bool => !$r['ok']);

Logger::info('app', 'Worker run complete', [
    'ms'      => $elapsed,
    'tasks'   => count($results),
    'failed'  => count($failed),
    'results' => array_map(static fn (array $r): string => (string) $r['summary'], $results),
]);

if ($verbose) {
    cli_out('');
    cli_out(sprintf('  %d task(s) in %dms, %d failed.', count($results), $elapsed, count($failed)));
}

if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($failed === [] ? 0 : 1);
