<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Models\AgentRelease;
use App\Models\UpdateSetting;
use App\Services\AgentUpdateStatus;
use App\Core\ForbiddenException;
use App\Core\LimitExceededException;
use App\Core\NotFoundException;
use App\Core\Rbac;
use App\Core\ValidationException;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\IpAllocation;
use App\Models\JoinCode;
use App\Models\MigrationRecord;
use App\Models\Network;
use App\Models\Plan;
use App\Models\Relay;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AclService;
use App\Services\BillingService;
use App\Services\DeviceService;
use App\Services\IpamService;
use App\Services\NetworkService;
use App\Updater\MigrationRunner;

/**
 * Database-backed tests.
 *
 * The centre of gravity is tenant isolation (R3, §20.C): two complete customer
 * accounts are built and then every cross-tenant access path is attempted.
 *
 * Fixtures are created inside a transaction that is always rolled back, so the
 * suite can be run repeatedly against a live installation without leaving
 * anything behind.
 */
final class DatabaseTests
{
    /** @var array<string,int> */
    private static array $fixtures = [];

    public static function run(): void
    {
        // Outside the transaction, deliberately: the runner applies real
        // migrations, and MySQL commits implicitly on DDL. Run inside, a
        // single pending migration would end the transaction and silently
        // commit every fixture created up to that point — the suite would
        // still pass while leaving rows behind in the database.
        self::migrationLedger();
        self::everyModelMatchesItsTable();

        $before = self::rowCensus();

        DB::begin();

        try {
            self::seedFixtures();
            self::tenantIsolation();
            self::ipamAllocation();
            self::deviceLifecycle();
            self::updateStatusNamesTheReason();
            self::splitTunnelGuarantee();
            self::planLimits();
            self::rbacMatrix();
            self::sqlInjectionResistance();
            // The two production defects that need a database to reproduce.
            self::superAdminCreatesNetwork();
            self::relayWithoutAPublicKey();
            // 1.9.2: the one-click install path.
            self::reinstallKeepsItsIdentity();
            self::preApprovedJoinCode();
        } finally {
            // Nothing this suite created survives.
            DB::rollback();
            Auth::reset();
            TenantScope::reset();
        }

        self::isolationHeld($before);
    }

    /**
     * Row counts for the tables the suite writes to.
     *
     * @return array<string,int>
     */
    private static function rowCensus(): array
    {
        $census = [];
        foreach (['tenants', 'users', 'networks', 'devices', 'ip_allocations', 'join_codes', 'api_keys'] as $table) {
            $census[$table] = (int) DB::scalar('SELECT COUNT(*) FROM ' . DB::table($table));
        }

        return $census;
    }

    /**
     * The suite's own guarantee, checked rather than asserted in a comment.
     *
     * A rolled-back transaction that quietly committed is worse than a failing
     * test: every later run starts from data it did not create and cannot
     * account for.
     *
     * @param array<string,int> $before
     */
    private static function isolationHeld(array $before): void
    {
        TestCase::group('Isolation — the suite leaves no trace');

        $after = self::rowCensus();
        $leaked = [];
        foreach ($before as $table => $count) {
            if (($after[$table] ?? $count) !== $count) {
                $leaked[] = sprintf('%s %+d', $table, ($after[$table] ?? $count) - $count);
            }
        }

        TestCase::assert($leaked === [], 'every row the suite created was rolled back',
            $leaked === [] ? count($before) . ' tables unchanged' : implode(', ', $leaked));
    }

    // ------------------------------------------------------------ fixtures

    private static function seedFixtures(): void
    {
        TestCase::group('Fixtures — two complete customer accounts');

        $plan = Plan::findBySlug('starter') ?? Plan::publicPlans()[0];

        foreach (['alpha' => 'Alpha Industries', 'beta' => 'Beta Traders'] as $key => $company) {
            $tenantId = TenantScope::acrossAllTenants('test fixture', static fn (): int => Tenant::create([
                'company_name'  => $company,
                'slug'          => $key . '-' . bin2hex(random_bytes(3)),
                'email'         => $key . '@example.test',
                'plan_id'       => (int) $plan['id'],
                'device_limit'  => 5,
                'network_limit' => 2,
                'user_limit'    => 3,
                'status'        => 'active',
                'timezone'      => 'Asia/Kolkata',
            ]));

            self::$fixtures[$key . '_tenant'] = $tenantId;

            self::$fixtures[$key . '_admin'] = TenantScope::asTenant($tenantId, static fn (): int => User::create([
                'tenant_id'     => $tenantId,
                'name'          => ucfirst($key) . ' Admin',
                'email'         => $key . '-admin-' . bin2hex(random_bytes(3)) . '@example.test',
                'password_hash' => Crypto::hashPassword('Fixture!Pass2026'),
                'role'          => Rbac::COMPANY_ADMIN,
                'status'        => 'active',
            ]));
        }

        TestCase::assert(self::$fixtures['alpha_tenant'] > 0, 'tenant Alpha created',
            '#' . self::$fixtures['alpha_tenant']);
        TestCase::assert(self::$fixtures['beta_tenant'] > 0, 'tenant Beta created',
            '#' . self::$fixtures['beta_tenant']);

        // A network and a device for each, created through the real services.
        foreach (['alpha' => '10.77.0.0/24', 'beta' => '10.88.0.0/24'] as $key => $cidr) {
            self::asTenantAdmin($key);

            $network = NetworkService::create([
                'name' => ucfirst($key) . ' HQ',
                'cidr' => $cidr,
            ]);
            self::$fixtures[$key . '_network'] = (int) $network['id'];

            $code = JoinCode::issue(self::$fixtures[$key . '_tenant'], (int) $network['id'], null, 0, 60);

            $enrolment = DeviceService::enroll([
                'join_code'     => $code['code'],
                'public_key'    => base64_encode(random_bytes(32)),
                'hostname'      => $key . '-pc-01',
                'os'            => 'windows',
                'os_version'    => '11',
                'agent_version' => '1.0.0',
            ]);

            $device = Device::findByUid($enrolment['device_uid']);
            self::$fixtures[$key . '_device'] = (int) $device['id'];
        }

        TestCase::assert(self::$fixtures['alpha_network'] > 0, 'network created for Alpha',
            '10.77.0.0/24');
        TestCase::assert(self::$fixtures['beta_network'] > 0, 'network created for Beta',
            '10.88.0.0/24');
        TestCase::assert(self::$fixtures['alpha_device'] > 0, 'device enrolled for Alpha');
        TestCase::assert(self::$fixtures['beta_device'] > 0, 'device enrolled for Beta');
    }

    /** Act as a tenant's administrator for the calls that follow. */
    private static function asTenantAdmin(string $key): void
    {
        Auth::reset();
        TenantScope::reset();

        $user = TenantScope::acrossAllTenants(
            'test actor lookup',
            static fn (): ?array => User::findActiveById(self::$fixtures[$key . '_admin'])
        );

        $_SESSION['user_id'] = self::$fixtures[$key . '_admin'];
        $_SESSION['tenant_id'] = self::$fixtures[$key . '_tenant'];
        $_SESSION['role'] = Rbac::COMPANY_ADMIN;

        Auth::reset();
        Auth::setApiActor($user, self::$fixtures[$key . '_tenant'], ['*']);
    }

    /**
     * A platform super admin: no tenant of their own, and the wildcard.
     *
     * Created with raw SQL because the User model refuses to insert into a
     * tenant-scoped table without a tenant_id — which is the right rule, and
     * exactly why a super admin is not an ordinary row.
     */
    private static function asSuperAdmin(): void
    {
        Auth::reset();
        TenantScope::reset();

        if (!isset(self::$fixtures['super_admin'])) {
            DB::execute(
                'INSERT INTO ' . DB::table('users')
                . ' (tenant_id, name, email, password_hash, role, status, timezone, created_at, updated_at)'
                . ' VALUES (NULL, :name, :email, :hash, :role, \'active\', \'Asia/Kolkata\','
                . ' UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [
                    'name'  => 'Platform Admin',
                    'email' => 'platform-' . bin2hex(random_bytes(4)) . '@example.test',
                    'hash'  => Crypto::hashPassword('Fixture!Pass2026'),
                    'role'  => Rbac::SUPER_ADMIN,
                ]
            );
            self::$fixtures['super_admin'] = (int) DB::lastInsertId();
        }

        $_SESSION['user_id'] = self::$fixtures['super_admin'];
        unset($_SESSION['tenant_id'], $_SESSION['impersonating']);
        $_SESSION['role'] = Rbac::SUPER_ADMIN;

        Auth::reset();
    }

    // ------------------------------------------------------------ migrations

/**
     * Every model's finder runs against the real migrated schema.
     *
     * 1.9.7-dev.4 shipped a DeviceProbe model over a table with no deleted_at
     * column. Model defaults $softDeletes to true, so every finder appended
     * "AND deleted_at IS NULL" and the feature answered 500 the first time
     * anybody pressed the button. Nothing caught it: the unit tests never
     * touched the database, the migration applied cleanly because it was
     * correct, and the model was correct too — they simply disagreed, and
     * nothing compared them.
     *
     * So this compares them. Every model in app/Models is asked for a row
     * through its own finder, against the schema the migrations actually
     * built. A column a model assumes and a table lacks is a PDOException
     * here rather than a 500 in somebody's hands.
     *
     * find() rather than a hand-written query on purpose: it exercises the
     * soft-delete clause, the tenant scope and the table name together, which
     * is the combination that was wrong.
     */
/**
     * The panel can say why a device is not being offered an update.
     *
     * A machine sat on 1.9.5 through 1.9.6 and six development builds with
     * working self-update code, and the panel said "up to date" the whole
     * time — which was true from the device's point of view. It asked, it was
     * told there was nothing, and it went back to sleep. Everything that could
     * have explained it lives on the panel's side and none of it was shown.
     *
     * Each of these reasons is a different action for whoever is looking.
     */
    private static function updateStatusNamesTheReason(): void
    {
        TestCase::group('Updates — the panel says why a device is not updating');

        TenantScope::asTenant((int) self::$fixtures['alpha_tenant'], static function (): void {
            $device = Device::findOrFail((int) self::$fixtures['alpha_device']);

            // Nothing published for this platform at all. This is the state
            // the field was in: the agent binary is published by
            // upgrade-edge.sh on the EDGE, and updating the panel publishes
            // nothing.
            $status = AgentUpdateStatus::forDevice($device);
            TestCase::assertContains('nothing published', $status['reason'],
                'with no release row, the reason is that nothing published one');
            TestCase::assertContains('channel', $status['reason'],
                'and it names the channel, because a panel on dev sees no stable-only fleet');
            TestCase::assertContains('upgrade-edge.sh', $status['detail'],
                'and it names what publishes one');

            // Published, but unsigned: every agent refuses it, silently.
            $id = AgentRelease::create([
                'version'         => '9.9.9',
                'channel'         => 'stable',
                'platform'        => (string) $device['os'],
                'arch'            => (string) $device['arch'],
                'file_path'       => '/tmp/agent.exe',
                'file_size'       => 1,
                'sha256'          => str_repeat('a', 64),
                'signature'       => '',
                'rollout_percent' => 100,
                'published_at'    => gmdate('Y-m-d H:i:s'),
            ]);

            $status = AgentUpdateStatus::forDevice($device);
            TestCase::assertSame('published unsigned', $status['reason'],
                'an unsigned release is named as such, not reported as up to date');

            // Signed, and now it is offered.
            AgentRelease::update($id, ['signature' => str_repeat('b', 64)]);

            $status = AgentUpdateStatus::forDevice($device);
            TestCase::assert($status['offered'], 'a signed, newer release is offered');
            TestCase::assertSame('9.9.9', $status['version'], 'and names the version');

            // The channel the panel is on decides what a device is offered.
            //
            // The agent sends no channel, so every device was treated as
            // Stable and a panel deliberately running development builds
            // offered its own machines nothing — for six releases, while
            // every publish on the edge reported success.
            AgentRelease::update($id, ['rollout_percent' => 100, 'channel' => 'dev', 'version' => '9.9.10']);

            $onStable = AgentUpdateStatus::forDevice($device);
            TestCase::assert(!$onStable['offered'],
                'a stable panel is not offered a development build');

            UpdateSetting::save(['channel' => 'edge'] + UpdateSetting::current());

            $onEdge = AgentUpdateStatus::forDevice($device);
            TestCase::assert($onEdge['offered'],
                'and a panel on Edge IS offered it — which is the defect that hid six releases');

            UpdateSetting::save(['channel' => 'stable'] + UpdateSetting::current());
            AgentRelease::update($id, ['channel' => 'stable', 'version' => '9.9.9']);

            // Held back by a partial rollout.
            AgentRelease::update($id, ['rollout_percent' => 0]);

            $status = AgentUpdateStatus::forDevice($device);
            TestCase::assertSame('held back by rollout', $status['reason'],
                'a rollout that excludes this device says so rather than saying nothing');
        });
    }

    private static function everyModelMatchesItsTable(): void
    {
        TestCase::group('Database — every model matches the table it was migrated onto');

        $files = glob(APP_ROOT . '/app/Models/*.php') ?: [];
        $checked = 0;

        foreach ($files as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            // Model itself is the base class; it has no table of its own.
            if ($class === 'App\\Models\\Model' || !class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf('App\\Models\\Model')) {
                continue;
            }

            $checked++;

            // An id that will not exist. The answer is not the point — the
            // point is that the query the model builds is one the schema can
            // answer at all.
            //
            // Inside a tenant, because a tenant-scoped model refuses to build
            // a query without one and that refusal would hide the very thing
            // being looked for.
            $failure = null;
            try {
                TenantScope::asTenant(1, static fn () => $class::find(PHP_INT_MAX));
            } catch (\Throwable $e) {
                $failure = $e->getMessage();
            }

            TestCase::assert(
                $failure === null,
                basename($file, '.php') . '::find() runs against its real table',
                $failure ?? ''
            );
        }

        TestCase::assert($checked > 10, $checked . ' models checked against the schema');
    }

    private static function migrationLedger(): void
    {
        TestCase::group('Migrations — ledger and idempotency (§20.B)');

        $runner = new MigrationRunner(APP_ROOT . '/database/migrations', DB::prefix());

        $before = MigrationRecord::appliedFilenames();
        $firstRun = $runner->run();
        $afterFirst = MigrationRecord::appliedFilenames();

        TestCase::assertSame(count($before) + count($firstRun['applied']), count($afterFirst),
            'each applied migration is recorded once');

        // The property that matters: re-running changes nothing.
        $secondRun = $runner->run();
        TestCase::assertSame(0, count($secondRun['applied']), 're-running the runner is a no-op');
        TestCase::assertSame(count($afterFirst), count(MigrationRecord::appliedFilenames()),
            'and does not duplicate ledger rows');

        TestCase::assertSame([], $runner->pending(), 'nothing is left pending');
        TestCase::assertSame([], $runner->driftedMigrations(), 'no applied migration file has been edited');
    }

    // ------------------------------------------------------- tenant isolation

    private static function tenantIsolation(): void
    {
        TestCase::group('Tenant isolation — R3 / §20.C');

        $alphaNetwork = self::$fixtures['alpha_network'];
        $betaNetwork = self::$fixtures['beta_network'];
        $alphaDevice = self::$fixtures['alpha_device'];
        $betaDevice = self::$fixtures['beta_device'];

        // --- Alpha attempts to reach Beta's rows by id -----------------------
        self::asTenantAdmin('alpha');

        TestCase::assert(Network::find($alphaNetwork) !== null, 'Alpha can read its own network');
        TestCase::assertSame(null, Network::find($betaNetwork),
            "Alpha cannot read Beta's network by id");
        TestCase::assertThrows(NotFoundException::class,
            static fn () => Network::findOrFail($betaNetwork),
            "findOrFail on Beta's network returns 404, not 403",
            'a 403 would confirm the id exists');

        TestCase::assert(Device::find($alphaDevice) !== null, 'Alpha can read its own device');
        TestCase::assertSame(null, Device::find($betaDevice), "Alpha cannot read Beta's device by id");

        // --- Writes are scoped too ------------------------------------------
        TestCase::assert(!Network::update($betaNetwork, ['name' => 'Hijacked']),
            "Alpha cannot rename Beta's network");
        TestCase::assert(!Device::update($betaDevice, ['name' => 'Hijacked']),
            "Alpha cannot rename Beta's device");
        TestCase::assert(!Network::delete($betaNetwork), "Alpha cannot delete Beta's network");
        TestCase::assert(!Device::delete($betaDevice), "Alpha cannot delete Beta's device");

        // --- Service-layer paths --------------------------------------------
        TestCase::assertThrows(NotFoundException::class,
            static fn () => DeviceService::approve($betaDevice),
            "Alpha cannot approve Beta's device");
        TestCase::assertThrows(NotFoundException::class,
            static fn () => DeviceService::revoke($betaDevice),
            "Alpha cannot revoke Beta's device");
        TestCase::assertThrows(NotFoundException::class,
            static fn () => NetworkService::update($betaNetwork, ['name' => 'Hijacked']),
            "Alpha cannot update Beta's network through the service");
        TestCase::assertThrows(NotFoundException::class,
            static fn () => AclService::createRule($betaNetwork, ['action' => 'deny']),
            "Alpha cannot add an ACL rule to Beta's network");

        // --- Listings never leak --------------------------------------------
        $networks = Network::where([]);
        $networkIds = array_map(static fn (array $n): int => (int) $n['id'], $networks);
        TestCase::assert(in_array($alphaNetwork, $networkIds, true), 'Alpha lists its own network');
        TestCase::assert(!in_array($betaNetwork, $networkIds, true), "Alpha's list excludes Beta's network");

        $devices = Device::where([]);
        $deviceIds = array_map(static fn (array $d): int => (int) $d['id'], $devices);
        TestCase::assert(!in_array($betaDevice, $deviceIds, true), "Alpha's device list excludes Beta's device");

        $paginated = Device::paginate([], 1, 100);
        $paginatedIds = array_map(static fn (array $d): int => (int) $d['id'], $paginated['rows']);
        TestCase::assert(!in_array($betaDevice, $paginatedIds, true), "pagination excludes Beta's device");

        $users = User::where([]);
        $userIds = array_map(static fn (array $u): int => (int) $u['id'], $users);
        TestCase::assert(!in_array(self::$fixtures['beta_admin'], $userIds, true),
            "Alpha's user list excludes Beta's admin");

        // --- Searching cannot be used to probe ------------------------------
        $search = Device::paginate([], 1, 100, 'id', 'DESC', 'beta-pc', ['name', 'hostname']);
        TestCase::assertSame(0, $search['total'], "searching for Beta's hostname finds nothing");

        // --- The reverse direction ------------------------------------------
        self::asTenantAdmin('beta');
        TestCase::assertSame(null, Network::find($alphaNetwork), "Beta cannot read Alpha's network");
        TestCase::assertSame(null, Device::find($alphaDevice), "Beta cannot read Alpha's device");
        TestCase::assert(Network::find($betaNetwork) !== null, 'Beta can still read its own network');

        // --- IP pools are separate -------------------------------------------
        self::asTenantAdmin('alpha');
        $alphaPool = IpAllocation::where(['network_id' => $alphaNetwork], 'ip_numeric', 'ASC', 5);
        TestCase::assert($alphaPool !== [], 'Alpha can read its own IP pool');
        TestCase::assertSame([], IpAllocation::where(['network_id' => $betaNetwork], 'ip_numeric', 'ASC', 5),
            "Alpha cannot read Beta's IP pool");

        // --- assertOwned catches a row fetched by a global key ---------------
        $betaRow = TenantScope::acrossAllTenants('test lookup', static fn (): ?array => Device::find($betaDevice));
        TestCase::assertThrows(ForbiddenException::class,
            static fn () => TenantScope::assertOwned($betaRow, 'device'),
            'assertOwned rejects a row belonging to another tenant');

        // --- No tenant context, no tenant-scoped query ------------------------
        // A real unauthenticated request has no session at all; the harness
        // has to clear the one it planted for the fixtures.
        $_SESSION = [];
        Auth::reset();
        TenantScope::reset();
        TestCase::assertThrows(ForbiddenException::class,
            static fn () => TenantScope::currentTenantId(),
            'a tenant-scoped query with no actor is refused, not run unscoped',
            'fails closed');

        // --- Every tenant-scoped model declares itself --------------------
        $scoped = 0;
        foreach (['Network', 'Device', 'IpAllocation', 'AclRule', 'NetworkRoute',
                  'User', 'ApiKey', 'AuditLog', 'JoinCode', 'Subscription', 'Invoice', 'UsageCounter'] as $model) {
            $class = 'App\\Models\\' . $model;
            if (class_exists($class) && $class::isTenantScoped()) {
                $scoped++;
            } else {
                TestCase::assert(false, $model . ' is tenant-scoped');
            }
        }
        TestCase::assertSame(12, $scoped, 'all 12 tenant-owned models are scoped');
    }

    // ------------------------------------------------------------------ IPAM

    private static function ipamAllocation(): void
    {
        TestCase::group('IPAM — allocation against a real pool');

        self::asTenantAdmin('alpha');
        $networkId = self::$fixtures['alpha_network'];

        $stats = IpAllocation::poolStats($networkId);
        TestCase::assertSame(253, $stats['total'], 'a /24 pool materialises 253 addresses');

        $deviceId = self::$fixtures['alpha_device'];
        $ip = IpamService::assign($networkId, $deviceId);
        TestCase::assertSame('10.77.0.2', $ip, 'the first device gets the lowest free address');

        // Re-assigning must not consume a second address.
        TestCase::assertSame($ip, IpamService::assign($networkId, $deviceId),
            'assigning twice is idempotent');
        TestCase::assertSame(1, IpAllocation::poolStats($networkId)['used'],
            'and only one address is marked used');

        // A second device gets the next address.
        $secondDevice = Device::create([
            'tenant_id'  => self::$fixtures['alpha_tenant'],
            'network_id' => $networkId,
            'device_uid' => Device::generateUid(),
            'name'       => 'alpha-pc-02',
            'public_key' => base64_encode(random_bytes(32)),
            'status'     => 'pending',
        ]);
        TestCase::assertSame('10.77.0.3', IpamService::assign($networkId, $secondDevice),
            'the next device gets the next address');

        // Reservations are respected.
        IpamService::reserve($networkId, '10.77.0.4', 'NVR');
        $thirdDevice = Device::create([
            'tenant_id'  => self::$fixtures['alpha_tenant'],
            'network_id' => $networkId,
            'device_uid' => Device::generateUid(),
            'name'       => 'alpha-pc-03',
            'public_key' => base64_encode(random_bytes(32)),
            'status'     => 'pending',
        ]);
        TestCase::assertSame('10.77.0.5', IpamService::assign($networkId, $thirdDevice),
            'a reserved address is skipped');

        // Releasing returns the address to the pool.
        IpamService::release($secondDevice);
        TestCase::assertSame(null, IpAllocation::findForDevice($secondDevice),
            'releasing clears the allocation');
        TestCase::assertSame(null, Device::find($secondDevice)['virtual_ip'],
            'and clears the device IP');

        // A specific request is honoured when free, refused when taken.
        TestCase::assertSame('10.77.0.3', IpamService::assign($networkId, $secondDevice, '10.77.0.3'),
            'a released address can be requested again');
        TestCase::assertThrows(\App\Core\ValidationException::class,
            static fn () => IpamService::assign($networkId, $thirdDevice, '10.77.0.3'),
            'requesting an address already in use is refused');
        TestCase::assertThrows(\App\Core\ValidationException::class,
            static fn () => IpamService::assign($networkId, $thirdDevice, '10.77.0.4'),
            'requesting a reserved address is refused');
    }

    // -------------------------------------------------------- device lifecycle

    private static function deviceLifecycle(): void
    {
        TestCase::group('Devices — enrolment, approval, revocation (R4, R5)');

        self::asTenantAdmin('beta');
        $networkId = self::$fixtures['beta_network'];
        $tenantId = self::$fixtures['beta_tenant'];

        $publicKey = base64_encode(random_bytes(32));
        $code = JoinCode::issue($tenantId, $networkId, null, 0, 60);

        $enrolment = DeviceService::enroll([
            'join_code'     => $code['code'],
            'public_key'    => $publicKey,
            'hostname'      => 'beta-nvr-01',
            'os'            => 'linux',
            'agent_version' => '1.0.0',
        ]);

        // R4: nothing usable is handed back before approval.
        TestCase::assertSame('pending', $enrolment['status'], 'a new device enrols as pending');
        $device = Device::findByUid($enrolment['device_uid']);
        TestCase::assertSame(null, $device['virtual_ip'], 'a pending device has no address');
        TestCase::assertSame(null, $device['token_hash'], 'a pending device has no token');

        // R5: only the public key is stored.
        TestCase::assertSame($publicKey, $device['public_key'], 'the public key is stored verbatim');
        $columns = array_keys($device);
        TestCase::assert(!in_array('private_key', $columns, true),
            'there is no column for a private key', 'R5');

        // Re-enrolling with the same key must not create a duplicate.
        $again = DeviceService::enroll([
            'join_code'  => JoinCode::issue($tenantId, $networkId, null, 0, 60)['code'],
            'public_key' => $publicKey,
            'hostname'   => 'beta-nvr-01',
            'os'         => 'linux',
        ]);
        TestCase::assertSame($enrolment['device_uid'], $again['device_uid'],
            're-enrolling with the same key returns the same device');

        // An invalid or expired code is refused.
        TestCase::assertThrows(\App\Core\ValidationException::class,
            static fn () => DeviceService::enroll(['join_code' => 'BADCODE12345', 'public_key' => base64_encode(random_bytes(32))]),
            'an unknown join code is refused');
        TestCase::assertThrows(\App\Core\ValidationException::class,
            static fn () => DeviceService::enroll(['join_code' => $code['code'], 'public_key' => 'not-a-key']),
            'a malformed public key is refused');

        // Approval issues an address and a token.
        $approval = DeviceService::approve((int) $device['id']);
        TestCase::assertSame('authorized', $approval['device']['status'], 'approval authorises the device');
        TestCase::assert($approval['virtual_ip'] !== '', 'approval assigns an address', $approval['virtual_ip']);
        TestCase::assert(strlen($approval['token']) >= 32, 'approval issues a token');

        $stored = Device::find((int) $device['id']);
        TestCase::assertNotContains($approval['token'], (string) $stored['token_hash'],
            'only the token hash is stored, never the token');
        TestCase::assertSame(Crypto::hashToken($approval['token']), $stored['token_hash'],
            'the stored hash matches the issued token');

        // The token authenticates.
        $byToken = Device::findByToken($approval['token']);
        TestCase::assert($byToken !== null && (int) $byToken['id'] === (int) $device['id'],
            'the issued token resolves to the device');
        TestCase::assertSame(null, Device::findByToken('wrong-token-entirely'),
            'a wrong token resolves to nothing');

        // Revocation destroys the token and releases the address within one
        // config revision — the ten-second guarantee in §7.5.
        $revisionBefore = (int) Network::find($networkId)['config_revision'];
        DeviceService::revoke((int) $device['id'], 'test');
        $revoked = Device::find((int) $device['id']);

        TestCase::assertSame('revoked', $revoked['status'], 'revocation sets the status');
        TestCase::assertSame(null, $revoked['token_hash'], 'revocation destroys the token');
        TestCase::assertSame(null, $revoked['virtual_ip'], 'revocation releases the address');
        TestCase::assertSame(null, Device::findByToken($approval['token']),
            'the old token no longer authenticates');
        TestCase::assert((int) Network::find($networkId)['config_revision'] > $revisionBefore,
            'revocation bumps the network revision so peers re-read');
    }

    // ---------------------------------------------------- split tunnel (R1)

    private static function splitTunnelGuarantee(): void
    {
        TestCase::group('Split tunnel — R1 enforced server-side (§20.D)');

        self::asTenantAdmin('alpha');
        $networkId = self::$fixtures['alpha_network'];
        $deviceId = self::$fixtures['alpha_device'];

        DeviceService::approve($deviceId);
        $device = Device::find($deviceId);

        $config = DeviceService::buildAgentConfig($device);

        TestCase::assert($config['policy']['split_tunnel_only'], 'config declares split-tunnel-only');
        TestCase::assert(!$config['policy']['allow_default_route'], 'config forbids a default route');

        $json = (string) json_encode($config);
        TestCase::assertNotContains('0.0.0.0/0', $json, 'no default route anywhere in the config');
        TestCase::assertNotContains('"::/0"', $json, 'no IPv6 default route either');

        foreach ($config['peers'] as $peer) {
            foreach ($peer['allowed_ips'] as $cidr) {
                TestCase::assert(
                    !in_array($cidr, ['0.0.0.0/0', '::/0', '0.0.0.0/1', '128.0.0.0/1'], true),
                    'peer allowed_ips excludes ' . $cidr
                );
            }
        }

        TestCase::assert($config['dns']['split_only'], 'DNS is split-only, not a full takeover');

        // The final guard strips a default route even if one were constructed.
        $poisoned = $config;
        $poisoned['peers'][] = [
            'uid' => 'dev_evil', 'allowed_ips' => ['0.0.0.0/0', '10.77.0.9/32'],
        ];
        $poisoned['routes'][] = ['destination' => '0.0.0.0/0', 'via' => null, 'metric' => 1];

        $cleaned = DeviceService::assertSplitTunnel($poisoned);
        $lastPeer = end($cleaned['peers']);
        TestCase::assert(!in_array('0.0.0.0/0', $lastPeer['allowed_ips'], true),
            'assertSplitTunnel strips a default route from allowed_ips');
        TestCase::assert(in_array('10.77.0.9/32', $lastPeer['allowed_ips'], true),
            'and leaves legitimate entries alone');
        TestCase::assertSame(0, count(array_filter($cleaned['routes'],
            static fn (array $r): bool => $r['destination'] === '0.0.0.0/0')),
            'assertSplitTunnel strips a default route from advertised routes');
    }

    // ------------------------------------------------------------ plan limits

    private static function planLimits(): void
    {
        TestCase::group('Plan limits — enforced at the action (§13)');

        self::asTenantAdmin('alpha');
        $tenantId = self::$fixtures['alpha_tenant'];
        $networkId = self::$fixtures['alpha_network'];

        TenantScope::acrossAllTenants('test limit setup',
            static fn () => Tenant::update($tenantId, ['device_limit' => 2, 'network_limit' => 1]));

        $usage = Tenant::usage($tenantId);
        TestCase::assert($usage['devices'] >= 1, 'usage counts authorised devices', (string) $usage['devices']);

        // Fill the device allowance, then confirm the next one is refused.
        $created = [];
        while (Tenant::usage($tenantId)['devices'] < 2) {
            $id = Device::create([
                'tenant_id'  => $tenantId,
                'network_id' => $networkId,
                'device_uid' => Device::generateUid(),
                'name'       => 'filler-' . count($created),
                'public_key' => base64_encode(random_bytes(32)),
                'status'     => 'authorized',
            ]);
            $created[] = $id;
        }

        TestCase::assertThrows(LimitExceededException::class,
            static fn () => BillingService::assertCanAddDevice($tenantId),
            'adding a device beyond the plan limit is refused');

        try {
            BillingService::assertCanAddDevice($tenantId);
        } catch (LimitExceededException $e) {
            TestCase::assertContains('plan allows', $e->userMessage(), 'the refusal explains the limit');
            TestCase::assert($e->upgradeHint() !== '', 'and suggests an upgrade', $e->upgradeHint());
            TestCase::assertSame(402, $e->statusCode(), 'and maps to HTTP 402');
        }

        TestCase::assertThrows(LimitExceededException::class,
            static fn () => BillingService::assertCanAddNetwork($tenantId),
            'creating a network beyond the plan limit is refused');

        TestCase::assertThrows(LimitExceededException::class,
            static fn () => NetworkService::create(['name' => 'Over limit', 'cidr' => '10.99.0.0/24']),
            'the network service honours the limit too');

        // A suspended tenant keeps its tunnels but cannot add capacity.
        TenantScope::acrossAllTenants('test suspend',
            static fn () => Tenant::update($tenantId, ['status' => 'suspended', 'device_limit' => 50]));

        TestCase::assertThrows(LimitExceededException::class,
            static fn () => BillingService::assertCanAddDevice($tenantId),
            'a suspended account cannot add devices');

        try {
            BillingService::assertCanAddDevice($tenantId);
        } catch (LimitExceededException $e) {
            TestCase::assertContains('Existing connections keep working', $e->userMessage(),
                'and the message says existing tunnels are unaffected (R6)');
        }

        TenantScope::acrossAllTenants('test restore',
            static fn () => Tenant::update($tenantId, ['status' => 'active', 'device_limit' => 5, 'network_limit' => 2]));
    }

    // ------------------------------------------------------------------ RBAC

    private static function rbacMatrix(): void
    {
        TestCase::group('RBAC — role permissions');

        TestCase::assert(Rbac::roleHas(Rbac::SUPER_ADMIN, 'update.manage'), 'super admin may manage updates');
        TestCase::assert(Rbac::roleHas(Rbac::SUPER_ADMIN, 'anything.at.all'), 'super admin holds the wildcard');

        TestCase::assert(Rbac::roleHas(Rbac::COMPANY_ADMIN, 'network.create'), 'company admin may create networks');
        TestCase::assert(!Rbac::roleHas(Rbac::COMPANY_ADMIN, 'update.manage'),
            'company admin may NOT manage platform updates');
        TestCase::assert(!Rbac::roleHas(Rbac::COMPANY_ADMIN, 'tenant.manage'),
            'company admin may NOT manage other customers');
        TestCase::assert(!Rbac::roleHas(Rbac::COMPANY_ADMIN, 'impersonate'),
            'company admin may NOT impersonate');

        TestCase::assert(Rbac::roleHas(Rbac::NETWORK_ADMIN, 'device.approve'), 'network admin may approve devices');
        TestCase::assert(!Rbac::roleHas(Rbac::NETWORK_ADMIN, 'user.delete'), 'network admin may NOT delete users');
        TestCase::assert(!Rbac::roleHas(Rbac::NETWORK_ADMIN, 'billing.manage'), 'network admin may NOT manage billing');

        TestCase::assert(Rbac::roleHas(Rbac::READ_ONLY, 'device.view'), 'read-only may view devices');
        foreach (['device.approve', 'device.revoke', 'network.create', 'acl.manage', 'user.create'] as $permission) {
            TestCase::assert(!Rbac::roleHas(Rbac::READ_ONLY, $permission), 'read-only may NOT ' . $permission);
        }

        TestCase::assert(!in_array(Rbac::SUPER_ADMIN, Rbac::assignableRoles(), true),
            'a tenant admin cannot assign the super-admin role');

        foreach (['tenant.manage', 'relay.manage', 'update.manage', 'backup.manage', 'impersonate'] as $permission) {
            TestCase::assert(Rbac::isPlatformPermission($permission), $permission . ' is platform-only');
        }
        TestCase::assert(!Rbac::isPlatformPermission('network.create'), 'network.create is not platform-only');

        // A tenant-scoped actor never holds a platform permission, whatever
        // their role string says.
        self::asTenantAdmin('alpha');
        TestCase::assert(!Auth::can('update.manage'), 'a tenant actor cannot hold update.manage');
        TestCase::assert(!Auth::can('tenant.manage'), 'a tenant actor cannot hold tenant.manage');
        TestCase::assert(Auth::can('network.create'), 'but can hold its own tenant permissions');
    }

    // ------------------------------------------------- injection resistance

    // ---------------------------------------- production defect 6 (1.9.1)

    /**
     * A super admin creating a network.
     *
     * On 1.9.0 this produced "Please correct the highlighted fields" with
     * nothing highlighted: NetworkService demanded a tenant_id, the actor had
     * none because that is what makes them a super admin, the form had no
     * field to supply one, and ValidationException's user-facing message threw
     * away the only sentence that said what was wrong.
     */
    private static function superAdminCreatesNetwork(): void
    {
        TestCase::group('Defect 6 — a super admin can create a network for a customer');

        self::asSuperAdmin();

        // 1. The message. A validation error on a field that is not on the
        //    form must still say what the problem is.
        try {
            NetworkService::create(['name' => 'Orphan', 'cidr' => '10.91.0.0/24']);
            TestCase::assert(false, 'creating a network with no customer is refused');
        } catch (ValidationException $e) {
            TestCase::assert(
                array_key_exists('tenant_id', $e->errors()),
                'the error names the tenant_id field'
            );
            TestCase::assert(
                !str_starts_with($e->userMessage(), 'Please correct the highlighted fields.')
                    || count($e->errors()) > 1,
                'a single error is shown as itself, not as "correct the highlighted fields"',
                $e->userMessage()
            );
            TestCase::assertContains('customer', strtolower($e->userMessage()),
                'and the message says a customer is needed');
        }

        // 2. An id that is not a customer is refused rather than silently
        //    creating a network nobody owns.
        TestCase::assertThrows(
            ValidationException::class,
            static fn () => NetworkService::create([
                'name'      => 'Ghost',
                'cidr'      => '10.92.0.0/24',
                'tenant_id' => 2147483600,
            ]),
            'a tenant_id that does not exist is refused'
        );

        // 3. The working path.
        self::asSuperAdmin();
        $network = NetworkService::create([
            'name'      => 'Platform-created HQ',
            'cidr'      => '10.93.0.0/24',
            'tenant_id' => self::$fixtures['beta_tenant'],
        ]);

        TestCase::assertSame(
            self::$fixtures['beta_tenant'],
            (int) $network['tenant_id'],
            'the network belongs to the customer that was chosen'
        );
        TestCase::assert((int) $network['id'] > 0, 'and it was actually written');

        // 4. And the customer sees it as theirs — the scope was not merely
        //    bypassed for the insert.
        self::asTenantAdmin('beta');
        TestCase::assert(
            Network::find((int) $network['id']) !== null,
            'Beta reads the network the platform admin created for it'
        );
        self::asTenantAdmin('alpha');
        TestCase::assertSame(
            null,
            Network::find((int) $network['id']),
            'and Alpha still cannot'
        );
    }

    // ---------------------------------------- one-click install (1.9.2)

    /**
     * Running the installer again over an existing install.
     *
     * The second PC's install was attempted several times, and each attempt
     * re-presented the same join code. A single-use code would have refused
     * the machine it had itself admitted ten seconds earlier, and a
     * pre-approved code is single-use by default — so this is the difference
     * between "upgrades in place" and "works exactly once".
     */
    private static function reinstallKeepsItsIdentity(): void
    {
        TestCase::group('Upgrade — the installer run again keeps the identity and the code');

        self::asTenantAdmin('alpha');

        $networkId = self::$fixtures['alpha_network'];
        $code = JoinCode::issue(self::$fixtures['alpha_tenant'], $networkId, null, 1, 30);

        $key = base64_encode(random_bytes(32));

        $first = DeviceService::enroll([
            'join_code'     => $code['code'],
            'public_key'    => $key,
            'hostname'      => 'upgrade-pc',
            'os'            => 'windows',
            'agent_version' => '1.9.1',
        ]);

        TestCase::assert($first['device_uid'] !== '', 'the first install enrols');

        $used = JoinCode::find((int) $code['id']);
        TestCase::assertSame(1, (int) $used['uses'], 'and consumes the code\'s single use');

        // The installer, run again on the same machine: same key, same code.
        $second = DeviceService::enroll([
            'join_code'     => $code['code'],
            'public_key'    => $key,
            'hostname'      => 'upgrade-pc',
            'os'            => 'windows',
            'agent_version' => '1.9.2',
        ]);

        TestCase::assertSame(
            $first['device_uid'],
            $second['device_uid'],
            'the second install is the same device, not a new one'
        );

        $after = JoinCode::find((int) $code['id']);
        TestCase::assertSame(
            1,
            (int) $after['uses'],
            'and it did not spend a second use on a machine already on the network'
        );

        $device = Device::findByUid($second['device_uid']);
        TestCase::assertSame('1.9.2', (string) $device['agent_version'],
            'the upgrade is recorded on the existing row');

        // A different machine still cannot use a spent single-use code.
        TestCase::assertThrows(
            ValidationException::class,
            static fn () => DeviceService::enroll([
                'join_code'  => $code['code'],
                'public_key' => base64_encode(random_bytes(32)),
                'hostname'   => 'someone-else',
                'os'         => 'windows',
            ]),
            'a spent single-use code still refuses a new device'
        );
    }

    /**
     * A pre-approved join code (R4, moved earlier rather than removed).
     *
     * The acceptance test for a customer install is one double-click and
     * nothing else, which cannot include an approval click. So an
     * administrator may decide in advance: this code, this many devices, for
     * this long, revocable. The decision is theirs, it is audited, and it is
     * not a default.
     */
    private static function preApprovedJoinCode(): void
    {
        TestCase::group('Pre-approved join code — admitted at once, and still bounded');

        self::asTenantAdmin('beta');

        $networkId = self::$fixtures['beta_network'];

        // The ordinary code first, to show what it does not do.
        $plain = JoinCode::issue(self::$fixtures['beta_tenant'], $networkId, null, 1, 30);
        $pending = DeviceService::enroll([
            'join_code'  => $plain['code'],
            'public_key' => base64_encode(random_bytes(32)),
            'hostname'   => 'waits-for-approval',
            'os'         => 'windows',
        ]);
        TestCase::assertSame('pending', $pending['status'],
            'an ordinary code still leaves a device waiting (R4)');

        $pendingDevice = Device::findByUid($pending['device_uid']);
        TestCase::assertSame(null, $pendingDevice['virtual_ip'],
            'with no address, so it can reach nothing');

        // And the pre-approved one.
        $code = JoinCode::issue(self::$fixtures['beta_tenant'], $networkId, null, 1, 30, true);

        $row = JoinCode::find((int) $code['id']);
        TestCase::assertSame(1, (int) $row['pre_approved'], 'the code records the decision');

        $joined = DeviceService::enroll([
            'join_code'  => $code['code'],
            'public_key' => base64_encode(random_bytes(32)),
            'hostname'   => 'one-click-pc',
            'os'         => 'windows',
        ]);

        TestCase::assertSame('authorized', $joined['status'],
            'a pre-approved code admits the device immediately');

        $device = Device::findByUid($joined['device_uid']);
        TestCase::assert(
            $device['virtual_ip'] !== null && $device['virtual_ip'] !== '',
            'and it has an overlay address without anybody clicking',
            (string) $device['virtual_ip']
        );

        // Bounded: single use by default, so a second machine is refused
        // outright rather than inheriting the decision.
        TestCase::assertThrows(
            ValidationException::class,
            static fn () => DeviceService::enroll([
                'join_code'  => $code['code'],
                'public_key' => base64_encode(random_bytes(32)),
                'hostname'   => 'second-machine',
                'os'         => 'windows',
            ]),
            'a second machine cannot inherit the pre-approval'
        );

        // And revoking it locks out even the machine that used it, which is
        // what makes the decision reversible.
        JoinCode::revokeAllForNetwork($networkId);

        TestCase::assertThrows(
            ValidationException::class,
            static fn () => DeviceService::enroll([
                'join_code'  => $code['code'],
                'public_key' => (string) Device::findByUid($joined['device_uid'])['public_key'],
                'hostname'   => 'one-click-pc',
                'os'         => 'windows',
            ]),
            'a revoked code admits nobody, not even the device that used it'
        );
    }

    // ---------------------------------------- production defect 5 (1.9.1)

    /**
     * Registering a relay.
     *
     * The form demanded a Curve25519 public key that a relay does not have —
     * it authenticates with AKCONNECT_RELAY_SECRET — so no relay could be
     * registered without inventing one.
     */
    private static function relayWithoutAPublicKey(): void
    {
        TestCase::group('Defect 5 — a relay registers without a key it does not have');

        self::asSuperAdmin();

        $id = TenantScope::acrossAllTenants('test relay', static fn (): int => Relay::create([
            'name'       => 'test-' . bin2hex(random_bytes(3)),
            'region'     => 'in',
            'host'       => 'relay-test.example.test',
            'port'       => 9000,
            'tcp_port'   => 0,
            'public_key' => null,
            'status'     => 'active',
        ]));

        $relay = TenantScope::acrossAllTenants('test relay', static fn (): ?array => Relay::find($id));

        TestCase::assert($relay !== null, 'the relay row was written');
        // Indexed, not coalesced: `?? 'missing'` cannot tell a NULL column
        // from an absent one, and NULL is precisely what is being asserted.
        TestCase::assert(array_key_exists('public_key', $relay), 'the row has a public_key column');
        TestCase::assertSame(null, $relay['public_key'],
            'which is NULL, because a relay has no Curve25519 key to give');
        TestCase::assertSame(9000, (int) ($relay['port'] ?? 0),
            'and the control port is the one the relay actually listens on');

        // The form must not ask for either of the two fields that caused this.
        $view = (string) @file_get_contents(APP_ROOT . '/app/Views/admin/relays.php');
        TestCase::assertNotContains('name="public_key"', $view,
            'the registration form does not ask for a public key');
        TestCase::assertNotContains('name="tcp_port"', $view,
            'nor for a TCP fallback port, which does not exist');
    }

    private static function sqlInjectionResistance(): void
    {
        TestCase::group('SQL injection resistance (§20.E)');

        self::asTenantAdmin('alpha');

        $payloads = [
            "' OR '1'='1",
            "'; DROP TABLE users; --",
            "1' UNION SELECT NULL,NULL,NULL--",
            "admin'--",
            "\\' OR 1=1 --",
            "%' OR tenant_id IS NOT NULL --",
        ];

        foreach ($payloads as $payload) {
            // Through the search path, which builds a LIKE clause.
            $result = Device::paginate([], 1, 25, 'id', 'DESC', $payload, ['name', 'hostname']);
            TestCase::assertSame(0, $result['total'], 'search payload returns nothing: ' . substr($payload, 0, 24));

            // Through an equality condition.
            TestCase::assertSame(null, Device::findBy(['name' => $payload]),
                'equality payload returns nothing: ' . substr($payload, 0, 24));
        }

        // The tables are still there.
        TestCase::assert((int) DB::scalar('SELECT COUNT(*) FROM ' . DB::table('users')) > 0,
            'the users table survived every payload');

        // Column names are rejected rather than escaped.
        TestCase::assertThrows(\App\Core\AppException::class,
            static fn () => Device::where([], 'id; DROP TABLE users', 'ASC'),
            'an injected sort column is rejected outright');
        TestCase::assertThrows(\App\Core\AppException::class,
            static fn () => Device::where(['name; DROP TABLE users' => 'x']),
            'an injected filter column is rejected outright');

        // Sort direction is an allow-list, not interpolation.
        $rows = Device::where([], 'id', 'ASC; DROP TABLE users');
        TestCase::assert(is_array($rows), 'an injected sort direction falls back to DESC safely');

        // A stored XSS payload round-trips as data, not markup.
        $xss = '<script>alert("xss")</script>';
        $deviceId = Device::create([
            'tenant_id'  => self::$fixtures['alpha_tenant'],
            'network_id' => self::$fixtures['alpha_network'],
            'device_uid' => Device::generateUid(),
            'name'       => $xss,
            'public_key' => base64_encode(random_bytes(32)),
            'status'     => 'pending',
        ]);
        TestCase::assertSame($xss, Device::find($deviceId)['name'],
            'an XSS payload is stored verbatim (escaping happens on output)');
        TestCase::assertContains('&lt;script&gt;', e(Device::find($deviceId)['name']),
            'and e() escapes it when rendered');
    }
}
