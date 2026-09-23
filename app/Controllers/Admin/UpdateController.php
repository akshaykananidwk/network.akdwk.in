<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\UpdateException;
use App\Core\Validator;
use App\Models\AppUpdate;
use App\Models\MigrationRecord;
use App\Models\UpdateSetting;
use App\Services\AuditService;
use App\Updater\UpdateLog;
use App\Updater\UpdateManager;

/**
 * System → Updates (§9).
 *
 * The controller is thin by design: every decision lives in UpdateManager so
 * that the web UI and cli/update.php drive identical logic. What is specific
 * here is the handling of the token field, which is write-only in both
 * directions — never rendered, never returned, and left unchanged when the
 * form submits it empty.
 */
final class UpdateController extends Controller
{
    public function index(Request $request): Response
    {
        $running = AppUpdate::running();

        return $this->view('admin.updates', [
            'title'      => 'System updates',
            'settings'   => UpdateSetting::toArray(),
            'running'    => $running,
            'latest'     => AppUpdate::latest(),
            'history'    => AppUpdate::history(1, 10),
            'migrations' => MigrationRecord::all(),
            'version'    => trim((string) @file_get_contents(APP_ROOT . '/VERSION')),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'repo_owner'           => 'required|string|max:120',
            'repo_name'            => 'required|string|max:120',
            'branch'               => 'required|string|max:120',
            'channel'              => 'required|in:stable,beta,edge',
            'check_interval_hours' => 'nullable|int|between:1,168',
            'backup_retention'     => 'nullable|int|between:1,50',
        ], [
            'repo_owner' => 'GitHub owner',
            'repo_name'  => 'Repository name',
        ]);

        $before = UpdateSetting::toArray();

        UpdateSetting::save([
            'repo_owner'           => $data['repo_owner'],
            'repo_name'            => $data['repo_name'],
            'branch'               => $data['branch'],
            'channel'              => $data['channel'],
            'auto_check'           => (int) $request->boolean('auto_check'),
            'auto_apply'           => (int) $request->boolean('auto_apply'),
            'check_interval_hours' => (int) ($data['check_interval_hours'] ?? 6),
            'verify_signature'     => (int) $request->boolean('verify_signature'),
            'backup_retention'     => (int) ($data['backup_retention'] ?? 5),
            'protected_json'       => $this->parseProtectedPaths((string) $request->input('protected', '')),
            'maintenance_allowlist_json' => $this->parseIpList((string) $request->input('maintenance_allowlist', '')),
        ]);

        // An empty token field means "leave it alone", not "clear it" — the
        // field is write-only, so the operator never sees what is stored and
        // must not have it wiped by saving an unrelated setting.
        $token = $request->input('token');
        if (is_string($token) && trim($token) !== '') {
            UpdateSetting::setToken(trim($token));
        }

        $after = UpdateSetting::toArray();
        // toArray() already masks the token, so this audit entry cannot leak it.
        AuditService::logChange('update.settings', 'update_settings', 1, $before, $after);

        return $this->redirect('admin/updates', 'Update settings saved.');
    }

    public function testConnection(Request $request): Response
    {
        $result = UpdateManager::make()->testConnection(
            $request->input('repo_owner'),
            $request->input('repo_name'),
            $request->input('branch'),
            $request->input('token')
        );

        AuditService::log('update.test_connection', 'update_settings', 1, null, [
            'ok' => $result['ok'],
        ], $result['ok'] ? 'success' : 'failure');

        return Response::api($result);
    }

    public function check(Request $request): Response
    {
        $force = $request->query('force') === '1';

        try {
            return Response::api(UpdateManager::make()->checkForUpdate($force));
        } catch (UpdateException $e) {
            return Response::apiError($e->getMessage(), 400, 'update_check_failed');
        }
    }

    /** Start a run and return the first step; the UI then polls step(). */
    public function start(Request $request): Response
    {
        try {
            $result = UpdateManager::make()->begin('web');

            return Response::api($result);
        } catch (UpdateException $e) {
            return Response::apiError($e->getMessage(), 409, 'update_start_failed');
        }
    }

    /** Execute exactly one step. Called repeatedly by the progress UI. */
    public function step(Request $request): Response
    {
        $updateId = (int) $request->input('update_id', '0');
        if ($updateId <= 0) {
            return Response::apiError('An update id is required.', 400);
        }

        // A long step (a database dump, a large copy) must not be cut off by
        // the default time limit; each step is still one bounded unit of work.
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        try {
            return Response::api(UpdateManager::make()->step($updateId));
        } catch (UpdateException $e) {
            return Response::apiError($e->getMessage(), 500, 'update_step_failed');
        }
    }

    public function status(Request $request): Response
    {
        $updateId = (int) $request->query('update_id', '0');
        if ($updateId <= 0) {
            $running = AppUpdate::running();
            if ($running === null) {
                return Response::api(['running' => false]);
            }
            $updateId = (int) $running['id'];
        }

        try {
            return Response::api(UpdateManager::make()->status($updateId));
        } catch (UpdateException $e) {
            return Response::apiError($e->getMessage(), 404);
        }
    }

    public function rollback(Request $request): Response
    {
        $updateId = (int) $request->input('update_id', '0');
        if ($updateId <= 0) {
            return Response::apiError('An update id is required.', 400);
        }

        @set_time_limit(900);

        try {
            $result = UpdateManager::make()->rollback(
                $updateId,
                'Manual rollback requested by ' . (Auth::user()['email'] ?? 'an administrator')
            );

            return Response::api($result, [], $result['ok'] ? 200 : 500);
        } catch (UpdateException $e) {
            return Response::apiError($e->getMessage(), 500, 'rollback_failed');
        }
    }

    public function history(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', '1'));

        return $this->view('admin.update_history', [
            'title'   => 'Update history',
            'history' => AppUpdate::history($page, 20),
        ]);
    }

    /** @param array<string,string> $params */
    public function detail(Request $request, array $params): Response
    {
        $updateId = (int) $params['id'];
        $update = AppUpdate::findRow($updateId);

        if ($update === null) {
            return $this->redirect('admin/updates', '', 'That update record no longer exists.');
        }

        return $this->view('admin.update_detail', [
            'title'  => 'Update #' . $updateId,
            'update' => $update,
            'log'    => UpdateLog::forUpdate($updateId)->tail(1000),
        ]);
    }

    /** Raw log download, for attaching to a support ticket. */
    public function downloadLog(Request $request, array $params): Response
    {
        $updateId = (int) $params['id'];
        if (AppUpdate::findRow($updateId) === null) {
            return Response::apiError('Update not found.', 404);
        }

        $log = UpdateLog::forUpdate($updateId);

        return Response::text($log->contents())
            ->header('Content-Disposition', sprintf('attachment; filename="update-%d.log"', $updateId));
    }

    /**
     * @return list<string>
     */
    private function parseProtectedPaths(string $raw): array
    {
        $lines = preg_split('/[\r\n,]+/', trim($raw)) ?: [];
        $paths = array_values(array_filter(array_map('trim', $lines), static fn (string $p): bool => $p !== ''));

        // UpdateSetting::protectedPaths() re-adds the mandatory defaults, so an
        // operator can extend the list but never shorten it below safe.
        return $paths === [] ? UpdateSetting::DEFAULT_PROTECTED : $paths;
    }

    /** @return list<string> */
    private function parseIpList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter(
            $parts,
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
    }
}
