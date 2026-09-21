<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\NetworkRoute;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\NetworkService;
use App\Services\RouteService;
use App\Core\Rbac;
use Throwable;

/**
 * Subnet-router mode across two customers (§16–17).
 *
 * The case this exists for: every hotel, shop and clinic in the country is
 * behind a router that hands out 192.168.1.0/24. Two customers advertising
 * byte-identical prefixes is the normal state of the product, not an edge
 * case, and it must not cause one customer's agent to learn a route into
 * another customer's building.
 *
 * Separate from DatabaseTests because that file is already long, and because
 * this asks a different question: not "is the query scoped", but "does the
 * feature survive the fact that private address space is not unique".
 */
final class GatewayTests
{
    /** @var array<string,int> */
    private static array $fx = [];

    public static function run(): void
    {
        DB::begin();

        try {
            self::seed();
            self::identicalPrefixesStaySeparate();
            self::approvalGatesTheRoute();
            self::duplicateWithinOneNetworkRefused();
        } finally {
            DB::rollback();
            Auth::reset();
            TenantScope::reset();
        }
    }

    /**
     * Two tenants, each with a network, a client device and a gateway device.
     */
    private static function seed(): void
    {
        TestCase::group('Gateway fixtures — two customers on identical LAN ranges');

        $plan = Plan::findBySlug('starter') ?? Plan::publicPlans()[0];

        foreach (['hotelA' => '10.201.0.0/24', 'hotelB' => '10.202.0.0/24'] as $key => $cidr) {
            $tenantId = TenantScope::acrossAllTenants('gateway fixture', static fn (): int => Tenant::create([
                'company_name'  => $key,
                'slug'          => strtolower($key) . '-' . bin2hex(random_bytes(3)),
                'email'         => strtolower($key) . '@example.test',
                'plan_id'       => (int) $plan['id'],
                'device_limit'  => 5,
                'network_limit' => 2,
                'user_limit'    => 3,
                'status'        => 'active',
                'timezone'      => 'Asia/Kolkata',
            ]));
            self::$fx[$key . '_tenant'] = $tenantId;

            self::$fx[$key . '_admin'] = TenantScope::asTenant($tenantId, static fn (): int => User::create([
                'tenant_id'     => $tenantId,
                'name'          => $key . ' Admin',
                'email'         => strtolower($key) . '-admin-' . bin2hex(random_bytes(3)) . '@example.test',
                'password_hash' => Crypto::hashPassword('Fixture!Pass2026'),
                'role'          => Rbac::COMPANY_ADMIN,
                'status'        => 'active',
            ]));

            self::act($key);

            $network = NetworkService::create(['name' => $key . ' site', 'cidr' => $cidr]);
            self::$fx[$key . '_network'] = (int) $network['id'];

            foreach (['gw', 'laptop'] as $role) {
                self::$fx[$key . '_' . $role] = self::enrol($key, (int) $network['id'], $role);
            }
        }

        TestCase::assert(self::$fx['hotelA_gw'] > 0, 'hotel A has a gateway device');
        TestCase::assert(self::$fx['hotelB_gw'] > 0, 'hotel B has a gateway device');
    }

    private static function enrol(string $key, int $networkId, string $role): int
    {
        $code = JoinCode::issue(self::$fx[$key . '_tenant'], $networkId, null, 0, 60);

        $enrolment = DeviceService::enroll([
            'join_code'     => $code['code'],
            'public_key'    => base64_encode(random_bytes(32)),
            'hostname'      => strtolower($key) . '-' . $role,
            'os'            => 'linux',
            'os_version'    => '12',
            'agent_version' => '1.5.0',
        ]);

        $device = Device::findByUid($enrolment['device_uid']);
        DeviceService::approve((int) $device['id']);

        return (int) $device['id'];
    }

    /** Act as a tenant's administrator for the calls that follow. */
    private static function act(string $key): void
    {
        Auth::reset();
        TenantScope::reset();

        $user = TenantScope::acrossAllTenants(
            'gateway test actor lookup',
            static fn (): ?array => User::findActiveById(self::$fx[$key . '_admin'])
        );

        $_SESSION['user_id'] = self::$fx[$key . '_admin'];
        $_SESSION['tenant_id'] = self::$fx[$key . '_tenant'];
        $_SESSION['role'] = Rbac::COMPANY_ADMIN;

        Auth::reset();
        Auth::setApiActor($user, self::$fx[$key . '_tenant'], ['*']);
    }

    /**
     * The one that matters: both customers advertise 192.168.1.0/24, and
     * neither agent ever hears about the other's.
     */
    private static function identicalPrefixesStaySeparate(): void
    {
        TestCase::group('Gateway — two customers advertising 192.168.1.0/24 (R3, §16)');

        foreach (['hotelA', 'hotelB'] as $key) {
            self::act($key);
            $route = RouteService::advertise(self::$fx[$key . '_network'], [
                'destination_cidr' => '192.168.1.0/24',
                'via_device_id'    => self::$fx[$key . '_gw'],
            ]);
            RouteService::approve((int) $route['id']);
            self::$fx[$key . '_route'] = (int) $route['id'];
        }

        TestCase::assert(self::$fx['hotelA_route'] !== self::$fx['hotelB_route'],
            'both customers advertised the same prefix and got separate routes');

        foreach (['hotelA' => 'hotelB', 'hotelB' => 'hotelA'] as $mine => $theirs) {
            self::act($mine);

            $config = DeviceService::buildAgentConfig(Device::find(self::$fx[$mine . '_laptop']));

            $destinations = array_column($config['routes'], 'destination');
            TestCase::assertSame(['192.168.1.0/24'], array_values(array_unique($destinations)),
                $mine . "'s agent sees exactly one 192.168.1.0/24 route");
            TestCase::assertSame(1, count($config['routes']),
                $mine . "'s agent is not offered the other customer's identical prefix");

            // The gateway is named by its overlay address, and those do not
            // collide: hotel A's route points into 10.201.0.0/24 and hotel B's
            // into 10.202.0.0/24. That is what keeps two identical LAN
            // prefixes apart on the wire.
            $via = (string) $config['routes'][0]['via'];
            $ownGateway = Device::find(self::$fx[$mine . '_gw']);
            TestCase::assertSame((string) $ownGateway['virtual_ip'], $via,
                $mine . "'s route points at its own gateway, not " . $theirs . "'s");

            $json = (string) json_encode($config);
            $foreignGateway = TenantScope::acrossAllTenants('gateway test',
                static fn (): ?array => Device::find(self::$fx[$theirs . '_gw']));
            TestCase::assertNotContains((string) $foreignGateway['device_uid'], $json,
                "no trace of " . $theirs . "'s gateway in " . $mine . "'s config");
            TestCase::assertNotContains((string) $foreignGateway['virtual_ip'], $json,
                "no trace of " . $theirs . "'s gateway address in " . $mine . "'s config");
        }
    }

    /** R4 applies to subnets: nothing reaches a LAN before a person approves it. */
    private static function approvalGatesTheRoute(): void
    {
        TestCase::group('Gateway — an unapproved route reaches no agent (R4, §17)');

        self::act('hotelA');

        $route = RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.90.0/24',
            'via_device_id'    => self::$fx['hotelA_gw'],
        ]);

        TestCase::assertSame(0, (int) $route['approved'], 'a new route is created unapproved');

        $config = DeviceService::buildAgentConfig(Device::find(self::$fx['hotelA_laptop']));
        TestCase::assertNotContains('192.168.90.0/24', (string) json_encode($config),
            'an unapproved route is absent from the agent config entirely');

        RouteService::approve((int) $route['id']);

        $config = DeviceService::buildAgentConfig(Device::find(self::$fx['hotelA_laptop']));
        TestCase::assert(in_array('192.168.90.0/24', array_column($config['routes'], 'destination'), true),
            'and present once an administrator approves it');

        RouteService::withdraw((int) $route['id']);

        $config = DeviceService::buildAgentConfig(Device::find(self::$fx['hotelA_laptop']));
        TestCase::assertNotContains('192.168.90.0/24', (string) json_encode($config),
            'and gone again when it is withdrawn');
    }

    /**
     * Inside one network the prefix must be unique, because there the agent
     * genuinely cannot tell which gateway to send a packet to.
     */
    private static function duplicateWithinOneNetworkRefused(): void
    {
        TestCase::group('Gateway — the same prefix twice in one network is refused (§17)');

        self::act('hotelA');

        $refused = false;
        try {
            RouteService::advertise(self::$fx['hotelA_network'], [
                'destination_cidr' => '192.168.1.0/24',
                'via_device_id'    => self::$fx['hotelA_laptop'],
            ]);
        } catch (Throwable $e) {
            $refused = true;
        }

        TestCase::assert($refused,
            'a second gateway for the same prefix in the same network is refused');

        TestCase::assertSame(1, count(array_filter(
            NetworkRoute::forNetwork(self::$fx['hotelA_network']),
            static fn (array $r): bool => $r['destination_cidr'] === '192.168.1.0/24'
        )), 'and the network still carries exactly one route for that prefix');
    }
}
