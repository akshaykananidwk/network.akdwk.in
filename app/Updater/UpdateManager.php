<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Logger;
use App\Core\UpdateException;
use App\Models\AppUpdate;
use App\Models\UpdateSetting;
use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * The GitHub auto-update pipeline (§9).
 *
 * Structured as a resumable step machine rather than one long request: PHP on
 * shared hosting will not stay alive for a download, a database dump, a
 * migration run and a full file copy. Each call to step() does exactly one
 * unit of work, persists where it got to, and returns the next step. The web
 * UI drives it over AJAX; cli/update.php drives the identical machine in a
 * loop. There is no second implementation to keep in sync.
 *
 * Failure policy, which is the part that matters:
 *   * Before APPLY, a failure is cheap — nothing on disk has changed, so the
 *     run is simply marked failed and maintenance mode is lifted.
 *   * From APPLY onwards, a failure triggers an automatic rollback: the file
 *     journal is replayed in reverse, reversible migrations are reversed, and
 *     the database is restored from the pre-update dump.
 *   * If the rollback itself fails, maintenance mode deliberately stays on and
 *     the operator is shown exact recovery paths. A half-updated site must
 *     never be left live.
 */
final class UpdateManager
{
    private PathGuard $guard;
    private MaintenanceMode $maintenance;
    private UpdateSteps $steps;

    public function __construct(private readonly string $appRoot)
    {
        $this->guard = new PathGuard($appRoot, UpdateSetting::protectedPaths());
        $this->maintenance = new MaintenanceMode($appRoot);
        $this->steps = new UpdateSteps($appRoot, $this->guard, $this->maintenance);
    }

    public static function make(): self
    {
        return new self(APP_ROOT);
    }

    // ------------------------------------------------------------ §9.2 check

    /**
     * Check GitHub for a newer revision.
     *
     * @param bool $force bypass the 15-minute result cache
     * @return array<string,mixed>
     */
    public function checkForUpdate(bool $force = false): array
    {
        $settings = UpdateSetting::current();

        if (empty($settings['repo_owner']) || empty($settings['repo_name'])) {
            return [
                'configured' => false,
                'message'    => 'Set a GitHub repository under System → Updates before checking.',
            ];
        }

        $cacheFile = $this->appRoot . '/storage/cache/update-check.json';
        $cacheMinutes = (int) Config::get('update.cache_minutes', 15);

        if (!$force && is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheMinutes * 60) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $cached['from_cache'] = true;

                return $cached;
            }
        }

        $client = new GithubClient(
            (string) $settings['repo_owner'],
            (string) $settings['repo_name'],
            UpdateSetting::token()
        );

        $branch = (string) ($settings['branch'] ?: 'main');
        $latest = $client->latestCommit($branch);
        $currentCommit = (string) ($settings['current_commit'] ?? '');
        $currentVersion = UpdateEnv::currentVersion($this->appRoot);

        $manifestJson = $client->fileAtRef('update.json', $latest['sha']);
        $manifest = $manifestJson !== null
            ? Manifest::fromJson($manifestJson)
            : Manifest::fallback($currentVersion);

        $upToDate = $currentCommit !== '' && hash_equals($currentCommit, $latest['sha']);

        $comparison = null;
        if (!$upToDate && $currentCommit !== '') {
            try {
                $comparison = $client->compare($currentCommit, $latest['sha']);
            } catch (UpdateException $e) {
                // A force-push or a rewritten history makes the base unknown.
                // That is not a failure to check; it just means we cannot show
                // a file-level diff.
                Logger::warning('update', 'Commit comparison unavailable', ['error' => $e->getMessage()]);
            }
        }

        $requirements = $manifest->checkRequirements(UpdateEnv::mysqlVersion());

        $signatureOk = null;
        if ((int) $settings['verify_signature'] === 1) {
            $signatureOk = $manifest->verifySignature();
        }

        $result = [
            'configured'       => true,
            'up_to_date'       => $upToDate,
            'checked_at'       => gmdate('c'),
            'from_cache'       => false,
            'repo'             => $settings['repo_owner'] . '/' . $settings['repo_name'],
            'branch'           => $branch,
            'current_version'  => $currentVersion,
            'current_commit'   => $currentCommit,
            'latest_commit'    => $latest,
            'new_version'      => $manifest->version(),
            'released_at'      => $manifest->releasedAt(),
            'notes'            => $manifest->notes(),
            'breaking'         => $manifest->isBreaking(),
            'manifest_present' => !$manifest->isSynthetic(),
            'migrations'       => $manifest->migrations(),
            'deletions'        => $manifest->deletions($this->guard),
            'commits_behind'   => $comparison['ahead_by'] ?? null,
            'commits'          => $comparison['commits'] ?? [],
            'files'            => $comparison['files'] ?? null,
            'estimated_kb'     => $comparison !== null ? $comparison['total_size'] : null,
            'requirements'     => $requirements,
            'signature'        => [
                'required' => (int) $settings['verify_signature'] === 1,
                'present'  => $manifest->signature() !== null,
                'valid'    => $signatureOk,
            ],
            'rate_limit'       => $client->rateLimit(),
        ];

        UpdateSetting::markChecked($manifest->version(), $latest['sha']);

        @file_put_contents($cacheFile, (string) json_encode($result, JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /** Validate the saved repository credentials (§9.1 "Test Connection"). */
    public function testConnection(?string $owner = null, ?string $repo = null, ?string $branch = null, ?string $token = null): array
    {
        $settings = UpdateSetting::current();

        $client = new GithubClient(
            $owner ?? (string) $settings['repo_owner'],
            $repo ?? (string) $settings['repo_name'],
            // An empty token here means "use the stored one"; the form sends
            // nothing when the operator is not changing it.
            ($token !== null && $token !== '') ? $token : UpdateSetting::token()
        );

        return $client->testConnection($branch ?? (string) ($settings['branch'] ?: 'main'));
    }

    // ------------------------------------------------------------ §9.4 apply

    /**
     * Begin an update run.
     *
     * @return array{update_id:int,step:string,progress:int}
     * @throws UpdateException when another run is already in flight
     */
    public function begin(string $triggerSource = 'web'): array
    {
        $running = AppUpdate::running();
        if ($running !== null) {
            throw new UpdateException(sprintf(
                'Update #%d is already running (step %s). Wait for it to finish or roll it back.',
                $running['id'],
                $running['step']
            ));
        }

        $check = $this->checkForUpdate(true);

        if (!($check['configured'] ?? false)) {
            throw new UpdateException((string) ($check['message'] ?? 'Updates are not configured.'));
        }
        if ($check['up_to_date'] ?? false) {
            throw new UpdateException('You are already on the latest version.');
        }

        $settings = UpdateSetting::current();
        if ((int) $settings['verify_signature'] === 1 && ($check['signature']['valid'] ?? false) !== true) {
            throw new UpdateException(
                'Signature verification is enabled and this release\'s manifest signature is missing or invalid. Update refused.',
                'PRECHECK'
            );
        }

        $updateId = AppUpdate::create([
            'from_version'   => UpdateEnv::currentVersion($this->appRoot),
            'to_version'     => (string) $check['new_version'],
            'from_commit'    => (string) $check['current_commit'],
            'to_commit'      => (string) $check['latest_commit']['sha'],
            'status'         => 'checking',
            'step'           => 'PRECHECK',
            'manifest_json'  => $check,
            'started_by'     => Auth::id(),
            'trigger_source' => $triggerSource,
            'started_at'     => gmdate('Y-m-d H:i:s'),
        ]);

        $log = UpdateLog::forUpdate($updateId);
        AppUpdate::update($updateId, ['log_path' => $log->relativePath()]);

        $log->info(sprintf(
            'Update #%d started: %s (%s) -> %s (%s)',
            $updateId,
            $check['current_version'],
            substr((string) $check['current_commit'], 0, 7) ?: 'unknown',
            $check['new_version'],
            substr((string) $check['latest_commit']['sha'], 0, 7)
        ));

        AuditService::log('update.start', 'app_update', $updateId, null, [
            'from' => $check['current_version'],
            'to'   => $check['new_version'],
        ]);

        return ['update_id' => $updateId, 'step' => 'PRECHECK', 'progress' => 0];
    }

    /**
     * Execute one step and report the next.
     *
     * @return array{update_id:int,step:string,next_step:string|null,progress:int,status:string,message:string,done:bool,log:list<string>}
     */
    public function step(int $updateId): array
    {
        $update = AppUpdate::findRow($updateId);
        if ($update === null) {
            throw new UpdateException('Update #' . $updateId . ' not found.');
        }

        if (in_array($update['status'], AppUpdate::TERMINAL, true)) {
            return $this->result($updateId, (string) $update['step'], null, (string) $update['status'], 'This update has already finished.', true);
        }

        $log = UpdateLog::forUpdate($updateId);
        $step = (string) $update['step'];

        try {
            $message = $this->steps->execute($step, $update, $log);

            if ($step === 'FINALISE') {
                return $this->result($updateId, 'FINALISE', null, 'success', $message, true);
            }

            $next = $this->nextStep($step);
            AppUpdate::advance($updateId, $next, $this->statusForStep($next));

            return $this->result($updateId, $step, $next, $this->statusForStep($next), $message, false);
        } catch (\Throwable $e) {
            return $this->handleFailure($updateId, $step, $e, $log);
        }
    }

    /**
     * Run every remaining step to completion. Used by the CLI, and by the web
     * UI as a fallback when a host blocks repeated AJAX calls.
     *
     * @param callable(array<string,mixed>):void|null $progress
     * @return array<string,mixed> the final step result
     */
    public function runToCompletion(int $updateId, ?callable $progress = null): array
    {
        $guardCounter = 0;

        while (true) {
            $result = $this->step($updateId);

            if ($progress !== null) {
                $progress($result);
            }

            if ($result['done'] || in_array($result['status'], AppUpdate::TERMINAL, true)) {
                return $result;
            }

            if (++$guardCounter > count(AppUpdate::STEPS) + 5) {
                throw new UpdateException('Update did not converge; aborting to avoid a loop.');
            }
        }
    }

    // -------------------------------------------------------------- steps

    // ------------------------------------------------------------ §9.6 rollback

    /**
     * Undo an update. Delegates to RollbackManager, which owns the ordering
     * and the "leave maintenance on if recovery is incomplete" rule (§9.6).
     *
     * @return array<string,mixed>
     */
    public function rollback(int $updateId, string $reason = 'Manual rollback'): array
    {
        return (new RollbackManager($this->appRoot, $this->guard, $this->maintenance))
            ->rollback($updateId, $reason);
    }

    /** Current progress, for polling and for the History screen. */
    public function status(int $updateId): array
    {
        $update = AppUpdate::findRow($updateId);
        if ($update === null) {
            throw new UpdateException('Update #' . $updateId . ' not found.');
        }

        return [
            'update_id' => $updateId,
            'status'    => $update['status'],
            'step'      => $update['step'],
            'progress'  => (int) $update['progress_pct'],
            'error'     => $update['error_text'],
            'started'   => $update['started_at'],
            'finished'  => $update['finished_at'],
            'done'      => in_array($update['status'], AppUpdate::TERMINAL, true),
            'log'       => UpdateLog::forUpdate($updateId)->tail(200),
        ];
    }

    // ------------------------------------------------------------- internals

    private function handleFailure(int $updateId, string $step, \Throwable $e, UpdateLog $log): array
    {
        $message = $e->getMessage();
        $log->error(sprintf('Step %s failed: %s', $step, $message));

        // Nothing on disk has changed before APPLY, so there is nothing to undo.
        $needsRollback = AppUpdate::stepIndex($step) >= AppUpdate::stepIndex('APPLY');

        if (!$needsRollback) {
            AppUpdate::fail($updateId, $message);
            $this->maintenance->disable();

            AuditService::log('update.failed', 'app_update', $updateId, null, ['step' => $step, 'error' => $message], 'failure');
            NotificationService::notifySuperAdmins(
                'critical',
                'Update failed at ' . $step,
                sprintf("The update stopped at %s.\n\n%s\n\nNothing was changed; the panel is back online.", $step, $message),
                'admin/updates/' . $updateId,
                'update'
            );

            return $this->result($updateId, $step, null, 'failed', $message, true);
        }

        $log->error('Files had already been modified; starting automatic rollback.');

        try {
            $rollback = $this->rollback($updateId, 'Automatic rollback after failure at ' . $step);

            return $this->result(
                $updateId,
                $step,
                null,
                $rollback['ok'] ? 'rolled_back' : 'failed',
                $rollback['ok']
                    ? $message . ' — the previous version has been restored.'
                    : $message . ' — ROLLBACK INCOMPLETE. The panel is in maintenance mode; see the recovery steps.',
                true
            );
        } catch (\Throwable $rollbackError) {
            AppUpdate::fail($updateId, $message . ' | rollback error: ' . $rollbackError->getMessage());
            $log->error('Rollback threw: ' . $rollbackError->getMessage());

            return $this->result(
                $updateId,
                $step,
                null,
                'failed',
                $message . ' — and the rollback itself failed. Maintenance mode is ON.',
                true
            );
        }
    }

    /** @return array<string,mixed> */
    private function result(int $updateId, string $step, ?string $next, string $status, string $message, bool $done): array
    {
        return [
            'update_id' => $updateId,
            'step'      => $step,
            'next_step' => $next,
            'progress'  => $next === null ? ($status === 'success' ? 100 : AppUpdate::progressFor($step)) : AppUpdate::progressFor($next),
            'status'    => $status,
            'message'   => $message,
            'done'      => $done,
            'log'       => UpdateLog::forUpdate($updateId)->tail(50),
        ];
    }

    private function nextStep(string $step): string
    {
        $index = AppUpdate::stepIndex($step);

        return AppUpdate::STEPS[$index + 1] ?? 'FINALISE';
    }

    private function statusForStep(string $step): string
    {
        return match ($step) {
            'PRECHECK', 'MAINTENANCE' => 'checking',
            'BACKUP_FILES', 'BACKUP_DB' => 'backing_up',
            'DOWNLOAD', 'STAGE' => 'downloading',
            'MIGRATE' => 'migrating',
            'APPLY', 'POST' => 'applying',
            'HEALTH', 'FINALISE' => 'verifying',
            default => 'checking',
        };
    }
}
