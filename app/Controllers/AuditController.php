<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;

/**
 * Audit log browser. Tenant users see only their own tenant's entries; a
 * platform super admin sees everything, including platform-level actions with
 * no tenant.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'action'      => (string) $request->query('action', ''),
            'result'      => (string) $request->query('result', ''),
            'target_type' => (string) $request->query('target_type', ''),
            'user_id'     => (int) $request->query('user_id', '0'),
            'from'        => (string) $request->query('from', ''),
            'to'          => (string) $request->query('to', ''),
            'q'           => trim((string) $request->query('q', '')),
        ];

        $page = max(1, (int) $request->query('page', '1'));
        $result = AuditLog::search(array_filter($filters), $page, 50);

        return $this->view('settings.audit', [
            'title'   => 'Audit log',
            'result'  => $result,
            'filters' => $filters,
            'actions' => AuditLog::distinctActions(),
        ]);
    }

    /**
     * CSV export of the current filter.
     *
     * Streamed rather than built in memory, and capped: an export is for
     * evidence, not for bulk extraction of the whole table.
     */
    public function export(Request $request): Response
    {
        $filters = array_filter([
            'action' => (string) $request->query('action', ''),
            'result' => (string) $request->query('result', ''),
            'from'   => (string) $request->query('from', ''),
            'to'     => (string) $request->query('to', ''),
        ]);

        $result = AuditLog::search($filters, 1, 5000);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return Response::apiError('Could not build the export.', 500);
        }

        fputcsv($handle, ['Timestamp (UTC)', 'User', 'Action', 'Target', 'Target ID', 'Result', 'IP', 'Impersonator']);

        foreach ($result['rows'] as $row) {
            fputcsv($handle, [
                $row['created_at'],
                $row['user_email'] ?? '',
                $row['action'],
                $row['target_type'] ?? '',
                $row['target_id'] ?? '',
                $row['result'],
                $row['ip'] ?? '',
                $row['impersonator_email'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        \App\Services\AuditService::log('audit.export', 'audit_log', null, null, [
            'rows'    => count($result['rows']),
            'filters' => $filters,
        ]);

        return Response::make($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="audit-' . gmdate('Ymd-His') . '.csv"');
    }
}
