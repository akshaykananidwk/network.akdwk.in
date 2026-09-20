<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\AppBackup;
use App\Models\UpdateSetting;
use App\Services\AuditService;
use App\Updater\BackupManager;
use App\Updater\PathGuard;

/**
 * System → Backups. Create, verify, download, restore and prune.
 */
final class BackupController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('admin.backups', [
            'title'      => 'Backups',
            'backups'    => AppBackup::recent(50),
            'total_size' => AppBackup::totalSize(),
            'retention'  => (int) UpdateSetting::current()['backup_retention'],
            'free_disk'  => @disk_free_space(APP_ROOT) ?: 0,
        ]);
    }

    public function create(Request $request): Response
    {
        @set_time_limit(900);

        $backup = BackupManager::make()->createFull('manual');

        $message = sprintf('Backup #%d created (%s).', $backup['id'], format_bytes((int) $backup['size_bytes']));
        if ((int) $backup['uploads_included'] !== 1) {
            $message .= ' Note: uploads/ was not included — ' . (string) $backup['uploads_skipped_reason'];
        }

        if ($request->wantsJson()) {
            return Response::api($backup);
        }

        return $this->redirect('admin/backups', $message);
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params): Response
    {
        $result = BackupManager::make()->verify((int) $params['id']);

        AuditService::log('backup.verify', 'backup', (int) $params['id'], null, [
            'ok' => $result['ok'],
        ], $result['ok'] ? 'success' : 'failure');

        if ($request->wantsJson()) {
            return Response::api($result);
        }

        $detail = implode('; ', array_map(
            static fn (array $c): string => $c['name'] . ': ' . $c['detail'],
            $result['checks']
        ));

        return $result['ok']
            ? $this->redirect('admin/backups', 'Backup verified. ' . $detail)
            : $this->redirect('admin/backups', '', 'Verification failed. ' . $detail);
    }

    /**
     * Restore from a backup.
     *
     * Deliberately requires the backup id to be typed into a confirmation
     * field: this overwrites the live application and database, and a stray
     * click should not be able to trigger it.
     */
    public function restore(Request $request, array $params): Response
    {
        $backupId = (int) $params['id'];
        $confirmation = trim((string) $request->input('confirm', ''));

        if ($confirmation !== (string) $backupId) {
            return $this->redirect('admin/backups', '', 'Type the backup number to confirm a restore.');
        }

        @set_time_limit(1800);

        $manager = BackupManager::make();
        $guard = new PathGuard(APP_ROOT, UpdateSetting::protectedPaths());

        $verification = $manager->verify($backupId);
        if (!$verification['ok']) {
            return $this->redirect('admin/backups', '', 'Refusing to restore: this backup failed verification.');
        }

        try {
            $files = $manager->restoreFiles($backupId, $guard);
            $statements = $manager->restoreDatabase($backupId);

            AuditService::log('backup.restore', 'backup', $backupId, null, [
                'files_restored' => $files['restored'],
                'files_skipped'  => $files['skipped'],
                'statements'     => $statements,
            ]);

            return $this->redirect('admin/backups', sprintf(
                'Restored backup #%d: %d file(s) written, %d protected path(s) left alone, %d SQL statements applied.',
                $backupId,
                $files['restored'],
                $files['skipped'],
                $statements
            ));
        } catch (\Throwable $e) {
            AuditService::log('backup.restore', 'backup', $backupId, null, ['error' => $e->getMessage()], 'failure');

            return $this->redirect('admin/backups', '', 'Restore failed: ' . $e->getMessage());
        }
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params): Response
    {
        $backup = AppBackup::findRow((int) $params['id']);
        if ($backup === null) {
            return $this->redirect('admin/backups', '', 'Backup not found.');
        }

        $which = (string) $request->query('part', 'files');
        $relative = $which === 'db' ? ($backup['db_path'] ?? '') : ($backup['files_path'] ?? '');

        if (!is_string($relative) || $relative === '') {
            return $this->redirect('admin/backups', '', 'That part of the backup was not recorded.');
        }

        // Backups live under storage/, which is not web accessible; the guard
        // keeps a crafted path from turning this into an arbitrary file read.
        $guard = new PathGuard(APP_ROOT, []);
        $absolute = $guard->resolve($relative);

        if (!is_file($absolute) || !str_starts_with($absolute, $guard->root() . '/storage/backups/')) {
            return $this->redirect('admin/backups', '', 'Backup file is missing.');
        }

        AuditService::log('backup.download', 'backup', (int) $params['id'], null, ['part' => $which]);

        $contents = file_get_contents($absolute);

        return Response::make($contents === false ? '' : $contents)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Length', (string) filesize($absolute))
            ->header('Content-Disposition', sprintf(
                'attachment; filename="backup-%d-%s"',
                (int) $params['id'],
                basename($absolute)
            ));
    }

    public function prune(Request $request): Response
    {
        $keep = (int) UpdateSetting::current()['backup_retention'];
        $result = BackupManager::make()->prune($keep);

        AuditService::log('backup.prune', 'backup', null, null, $result);

        return $this->redirect('admin/backups', sprintf(
            '%d backup(s) removed, %s freed.',
            $result['removed'],
            format_bytes($result['freed_bytes'])
        ));
    }
}
