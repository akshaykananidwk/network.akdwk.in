<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\TenantScope;
use App\Models\AppUpdate;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Job;
use App\Models\Network;
use App\Models\Relay;
use App\Models\Tenant;
use App\Services\BillingService;

/**
 * The landing screen. Renders the platform view for a super admin and the
 * tenant view for everyone else — different data, same route.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Auth::isSuperAdmin()
            ? $this->view('dashboard.platform', $this->platformData())
            : $this->view('dashboard.tenant', $this->tenantData());
    }

    /** Summary counters for the header cards, polled by the dashboard. */
    public function stats(Request $request): Response
    {
        return Response::api(
            Auth::isSuperAdmin()
                ? $this->platformData()['stats']
                : $this->tenantData()['stats']
        );
    }

    /** @return array<string,mixed> */
    private function platformData(): array
    {
        return TenantScope::acrossAllTenants('platform dashboard', static function (): array {
            $devices = Device::statusSummary();

            $tenants = DB::selectOne(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN status = \'trial\' THEN 1 ELSE 0 END) AS trial,
                        SUM(CASE WHEN status = \'suspended\' THEN 1 ELSE 0 END) AS suspended
                 FROM ' . DB::table('tenants') . ' WHERE deleted_at IS NULL'
            ) ?? [];

            $relays = DB::select(
                'SELECT id, name, region, status, current_sessions, capacity_mbps, last_heartbeat_at
                 FROM ' . DB::table('relays') . ' WHERE deleted_at IS NULL ORDER BY region, name'
            );

            $networks = (int) DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('networks') . ' WHERE deleted_at IS NULL AND status <> \'archived\''
            );

            $agentVersions = DB::select(
                'SELECT COALESCE(NULLIF(agent_version, \'\'), \'unknown\') AS version, COUNT(*) AS devices
                 FROM ' . DB::table('devices') . '
                 WHERE deleted_at IS NULL AND status = \'authorized\'
                 GROUP BY version ORDER BY devices DESC LIMIT 8'
            );

            return [
                'title' => 'Platform overview',
                'stats' => [
                    'tenants' => [
                        'total'     => (int) ($tenants['total'] ?? 0),
                        'active'    => (int) ($tenants['active'] ?? 0),
                        'trial'     => (int) ($tenants['trial'] ?? 0),
                        'suspended' => (int) ($tenants['suspended'] ?? 0),
                    ],
                    'networks' => $networks,
                    'devices'  => $devices,
                    'relays'   => [
                        'total' => count($relays),
                        'up'    => count(array_filter($relays, static fn (array $r): bool => $r['status'] === 'active')),
                    ],
                    'jobs'     => Job::stats(),
                ],
                'relays'         => $relays,
                'agent_versions' => $agentVersions,
                'recent_tenants' => DB::select(
                    'SELECT id, company_name, slug, status, created_at FROM ' . DB::table('tenants') . '
                     WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 8'
                ),
                'last_update'    => AppUpdate::latest(),
                'recent_audit'   => AuditLog::search([], 1, 10)['rows'],
            ];
        });
    }

    /** @return array<string,mixed> */
    private function tenantData(): array
    {
        $tenantId = Auth::tenantId();
        if ($tenantId === null) {
            return ['title' => 'Dashboard', 'stats' => [], 'networks' => [], 'pending_devices' => []];
        }

        $devices = Device::statusSummary($tenantId);
        $networks = Network::where(['tenant_id' => $tenantId], 'name', 'ASC', 25);
        $counts = Network::deviceCounts(array_map(static fn (array $n): int => (int) $n['id'], $networks));

        foreach ($networks as $index => $network) {
            $networks[$index]['device_counts'] = $counts[(int) $network['id']] ?? ['total' => 0, 'online' => 0, 'pending' => 0];
        }

        $tenant = TenantScope::acrossAllTenants('dashboard tenant lookup', static fn (): ?array => Tenant::find($tenantId));

        return [
            'title' => 'Dashboard',
            'stats' => [
                'devices'  => $devices,
                'networks' => count($networks),
                'meters'   => BillingService::usageMeters($tenantId),
            ],
            'networks'        => $networks,
            'pending_devices' => Device::where(['tenant_id' => $tenantId, 'status' => 'pending'], 'created_at', 'DESC', 10),
            'recent_devices'  => Device::where(['tenant_id' => $tenantId], 'last_seen_at', 'DESC', 8),
            'tenant_row'      => $tenant,
            'recent_audit'    => AuditLog::search([], 1, 8)['rows'],
        ];
    }
}
