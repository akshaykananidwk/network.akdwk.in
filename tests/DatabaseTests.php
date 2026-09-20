<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\ForbiddenException;
use App\Core\LimitExceededException;
use App\Core\NotFoundException;
use App\Core\Rbac;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\IpAllocation;
use App\Models\JoinCode;
use App\Models\MigrationRecord;
use App\Models\Network;
use App\Models\Plan;
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
        DB::begin();

        try {
            self::seedFixtures();
            self::migrationLedger();
            self::tenantIsolation();
            self::ipamAllocation();
            self::deviceLifecycle();
            self::splitTunnelGuarantee();
            self::planLimits();
            self::rbacMatrix();
            self::sqlInjectionResistance();
        } finally {
            // Nothing this suite created survives.
            DB::rollback();
            Auth::reset();
            TenantScope::reset();
        }
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

    // ------------------------------------------------------------ migrations

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
