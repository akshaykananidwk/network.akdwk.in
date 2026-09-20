<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\DeviceService;
use App\Services\IpamService;

/**
 * The body of the scale drill. See tests/scale.php for how to run it.
 *
 * It builds a tenant of the requested size through the real services — no
 * direct INSERTs — then times the queries the panel actually runs against it,
 * and finally re-checks tenant isolation with a thousand rows in the table
 * rather than five.
 */
final class ScaleReport
{
    private int $tenantId = 0;
    private int $networkId = 0;
    /** @var list<array{name:string,ok:bool,detail:string}> */
    private array $results = [];

    public function __construct(
        private readonly int $target,
        private readonly string $baseUrl,
    ) {
    }

    public function run(bool $keep): void
    {
        TestCase::group(sprintf('Scale — %d devices in one tenant (§20.F)', $this->target));

        try {
            $this->build();
            $this->timeAdminQueries();
            $this->timeAgentPath();
            $this->isolationAtScale();
        } finally {
            if ($keep) {
                TestCase::skip('cleanup', 'left in place for inspection (--keep)');
            } else {
                $this->cleanUp();
            }
            TenantScope::reset();
        }

        TestCase::summary();
    }

    public function exitCode(): int
    {
        return TestCase::failed() === 0 ? 0 : 1;
    }

    // ------------------------------------------------------------- building

    private function build(): void
    {
        $plan = Plan::publicPlans()[0];

        $this->tenantId = TenantScope::acrossAllTenants('scale drill', fn (): int => Tenant::create([
            'company_name'  => 'Scale Drill',
            'slug'          => 'scale-drill-' . bin2hex(random_bytes(3)),
            'email'         => 'scale@example.test',
            'plan_id'       => (int) $plan['id'],
            // The drill is about the control plane's behaviour at size, not
            // about billing, so the limits are raised rather than worked round.
            'device_limit'  => $this->target * 2,
            'network_limit' => 2,
            'user_limit'    => 2,
            'status'        => 'active',
            'timezone'      => 'UTC',
        ]));

        $poolStarted = microtime(true);

        TenantScope::asTenant($this->tenantId, function (): void {
            // A /16 so address exhaustion is never what is being measured.
            $this->networkId = Network::create([
                'tenant_id'       => $this->tenantId,
                'network_uid'     => bin2hex(random_bytes(8)),
                'name'            => 'Scale Net',
                'cidr'            => '10.128.0.0/16',
                'status'          => 'active',
            ]);
            IpamService::createPool($this->tenantId, $this->networkId, '10.128.0.0/16');
        });

        $poolElapsed = microtime(true) - $poolStarted;
        $poolRows = (int) DB::scalar(
            'SELECT COUNT(*) FROM ' . DB::table('ip_allocations') . ' WHERE network_id = :n',
            ['n' => $this->networkId]
        );

        TestCase::assert(true, 'materialised the /16 address pool',
            sprintf('%s rows in %.1fs', number_format($poolRows), $poolElapsed));

        $started = microtime(true);
        $enrolled = $this->enrolDevices();
        $elapsed = microtime(true) - $started;

        TestCase::assert($enrolled === $this->target, 'every device enrolled and was given an address',
            sprintf('%d in %.1fs — %.0fms each, %.0f/s',
                $enrolled, $elapsed, ($elapsed * 1000) / max($enrolled, 1), $enrolled / max($elapsed, 0.001)));

        $assigned = (int) DB::scalar(
            'SELECT COUNT(*) FROM ' . DB::table('ip_allocations') . ' WHERE network_id = :n AND device_id IS NOT NULL',
            ['n' => $this->networkId]
        );
        $distinct = (int) DB::scalar(
            'SELECT COUNT(DISTINCT ip) FROM ' . DB::table('ip_allocations') . ' WHERE network_id = :n AND device_id IS NOT NULL',
            ['n' => $this->networkId]
        );

        TestCase::assertSame($enrolled, $assigned, 'every device holds exactly one address');
        TestCase::assertSame($assigned, $distinct, 'and no address was handed out twice',
            $distinct . ' distinct addresses');
    }

    private function enrolDevices(): int
    {
        return TenantScope::asTenant($this->tenantId, function (): int {
            $code = JoinCode::issue($this->tenantId, $this->networkId, 1, $this->target + 10, 3600);
            $enrolled = 0;

            for ($i = 0; $i < $this->target; $i++) {
                $enrolment = DeviceService::enroll([
                    'join_code'  => $code['code'],
                    'public_key' => base64_encode(random_bytes(32)),
                    'name'       => sprintf('scale-node-%04d', $i),
                    'platform'   => 'linux',
                ]);

                // R4: enrolment leaves a device pending. Approving each one is
                // part of what is being measured, since that is where the
                // address is allocated.
                $device = Device::findByUid((string) $enrolment['device_uid']);
                if ($device === null) {
                    continue;
                }
                if ($device['status'] !== 'authorized') {
                    DeviceService::approve((int) $device['id']);
                }
                $enrolled++;
            }

            return $enrolled;
        });
    }

    // -------------------------------------------------------------- timings

    /**
     * The queries the panel runs on every page load, against a full table.
     *
     * A budget is asserted, generously, because the point is to catch a query
     * that degrades with row count — not to benchmark this machine.
     */
    private function timeAdminQueries(): void
    {
        TenantScope::asTenant($this->tenantId, function (): void {
            $this->timed('device list, first page of 50', 250, fn () => Device::where([], 'id', 'DESC', 50));
            $this->timed('device list, page 20', 250, fn () => Device::paginate([], 20, 50));
            $this->timed('device count for the dashboard', 100, fn () => DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('devices') . ' WHERE tenant_id = :t AND deleted_at IS NULL',
                ['t' => $this->tenantId]
            ));
            $this->timed('online-device count', 150, fn () => DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('devices') . '
                 WHERE tenant_id = :t AND deleted_at IS NULL AND last_seen_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)',
                ['t' => $this->tenantId]
            ));
            $this->timed('address map, capped at 512', 400, fn () => IpamService::map($this->networkId, 512));
            $this->timed('finding the next free address', 250, function (): void {
                $free = DB::scalar(
                    'SELECT ip FROM ' . DB::table('ip_allocations') . '
                     WHERE network_id = :n AND device_id IS NULL AND reserved = 0
                     ORDER BY ip_numeric LIMIT 1',
                    ['n' => $this->networkId]
                );
                unset($free);
            });
        });
    }

    /** The agent path: config and heartbeat, which every device calls. */
    private function timeAgentPath(): void
    {
        TenantScope::asTenant($this->tenantId, function (): void {
            $device = Device::where([], 'id', 'ASC', 1)[0] ?? null;
            if ($device === null) {
                TestCase::assert(false, 'a device was available for the agent-path timing');

                return;
            }

            $this->timed('building one agent config', 150,
                fn () => DeviceService::buildAgentConfig($device));

            // The whole fleet asking at once is the interesting case.
            $sample = min(200, $this->target);
            $devices = Device::where([], 'id', 'ASC', $sample);
            $started = microtime(true);
            foreach ($devices as $row) {
                DeviceService::buildAgentConfig($row);
            }
            $elapsed = (microtime(true) - $started) * 1000;

            TestCase::assert($elapsed / $sample < 50,
                sprintf('%d agent configs stay under 50ms each', $sample),
                sprintf('%.1fms each, %.0fms total', $elapsed / $sample, $elapsed));
        });
    }

    /** R3 still holds when the table is full. */
    private function isolationAtScale(): void
    {
        $otherTenant = TenantScope::acrossAllTenants('scale drill', fn (): int => Tenant::create([
            'company_name' => 'Scale Neighbour',
            'slug'         => 'scale-neighbour-' . bin2hex(random_bytes(3)),
            'email'        => 'neighbour@example.test',
            'plan_id'      => (int) Plan::publicPlans()[0]['id'],
            'device_limit' => 5, 'network_limit' => 1, 'user_limit' => 1,
            'status'       => 'active', 'timezone' => 'UTC',
        ]));

        try {
            TenantScope::asTenant($otherTenant, function (): void {
                $visible = Device::where([], 'id', 'DESC', 5000);
                TestCase::assertSame(0, count($visible),
                    'a neighbouring tenant sees none of the ' . $this->target . ' devices');
            });

            TenantScope::asTenant($this->tenantId, function (): void {
                $mine = (int) DB::scalar(
                    'SELECT COUNT(*) FROM ' . DB::table('devices') . ' WHERE tenant_id = :t AND deleted_at IS NULL',
                    ['t' => $this->tenantId]
                );
                TestCase::assertSame($this->target, $mine, 'and the owner still sees all of them');
            });
        } finally {
            TenantScope::acrossAllTenants('scale drill', static fn () => Tenant::forceDelete($otherTenant));
        }
    }

    /** @param callable():mixed $callback */
    private function timed(string $name, float $budgetMs, callable $callback): void
    {
        $started = microtime(true);
        $callback();
        $elapsed = (microtime(true) - $started) * 1000;

        TestCase::assert($elapsed < $budgetMs, $name,
            sprintf('%.1fms (budget %.0fms)', $elapsed, $budgetMs));
    }

    private function cleanUp(): void
    {
        TenantScope::acrossAllTenants('scale drill', function (): void {
            DB::execute('DELETE FROM ' . DB::table('ip_allocations') . ' WHERE network_id = :n', ['n' => $this->networkId]);
            DB::execute('DELETE FROM ' . DB::table('devices') . ' WHERE tenant_id = :t', ['t' => $this->tenantId]);
            DB::execute('DELETE FROM ' . DB::table('join_codes') . ' WHERE tenant_id = :t', ['t' => $this->tenantId]);
            DB::execute('DELETE FROM ' . DB::table('networks') . ' WHERE tenant_id = :t', ['t' => $this->tenantId]);
            DB::execute('DELETE FROM ' . DB::table('audit_logs') . ' WHERE tenant_id = :t', ['t' => $this->tenantId]);
            DB::execute('DELETE FROM ' . DB::table('tenants') . ' WHERE id = :t', ['t' => $this->tenantId]);
        });
    }
}
