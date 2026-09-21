<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Middleware\TenantScope;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\NetworkRoute;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AclRouteFilters;
use App\Services\AclService;
use App\Services\DeviceService;
use App\Services\DnsZone;
use App\Services\RouteHostService;
use App\Services\NetworkService;
use App\Services\RouteService;
use App\Services\SubnetMapper;
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
            self::subnetsAreMapped();
            self::rulesAreTranslatedAndStayPrecise();
            self::namesResolveToOverlayAddresses();
            self::zonesOutsideInternalAreRefused();
            self::namedHostsMustBeInsideTheirRoute();
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

            $reals = array_values(array_unique(array_column($config['routes'], 'real_destination')));
            TestCase::assertSame(['192.168.1.0/24'], $reals,
                $mine . "'s agent knows of exactly one 192.168.1.0/24");
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

    /**
     * The mapping itself: both customers on 192.168.1.0/24, each given a
     * different prefix, and the overlay carrying neither customer's real
     * range.
     */
    private static function subnetsAreMapped(): void
    {
        TestCase::group('Gateway — identical LANs get different virtual prefixes (§16)');

        $pool = SubnetMapper::pool();

        foreach (['hotelA', 'hotelB'] as $key) {
            self::act($key);
            $route = NetworkRoute::find(self::$fx[$key . '_route']);

            TestCase::assert(!empty($route['mapped_cidr']),
                $key . "'s route was given a virtual prefix", (string) $route['mapped_cidr']);
            TestCase::assert(AclRouteFilters::overlap((string) $route['mapped_cidr'], $pool),
                'and it came out of the pool ' . $pool);
            self::$fx[$key . '_mapped'] = (string) $route['mapped_cidr'];
        }

        // Different customers may reuse a virtual prefix — they never share a
        // routing table — but within our own fixtures they are separate
        // networks, and what matters is that neither carries the other's.
        foreach (['hotelA' => 'hotelB', 'hotelB' => 'hotelA'] as $mine => $theirs) {
            self::act($mine);
            $config = DeviceService::buildAgentConfig(Device::find(self::$fx[$mine . '_laptop']));

            $destinations = array_column($config['routes'], 'destination');
            TestCase::assert(!in_array('192.168.1.0/24', $destinations, true),
                $mine . "'s agent is never told to route the customer's real range");
            TestCase::assert(in_array(self::$fx[$mine . '_mapped'], $destinations, true),
                'it routes ' . self::$fx[$mine . '_mapped'] . ' instead');

            // The real range is still carried, because the gateway has to NAT
            // between the two and a human has to be shown which machine a rule
            // is about.
            $reals = array_column($config['routes'], 'real_destination');
            TestCase::assert(in_array('192.168.1.0/24', $reals, true),
                'and the real range travels alongside it for the gateway and the UI');
        }

        // The gateway is told both, because it is the only device that needs
        // each.
        self::act('hotelA');
        $gatewayConfig = DeviceService::buildAgentConfig(Device::find(self::$fx['hotelA_gw']));

        TestCase::assertSame(1, count($gatewayConfig['device']['advertises']),
            'the gateway advertises one LAN');
        TestCase::assertSame('192.168.1.0/24',
            $gatewayConfig['device']['advertises'][0]['real_destination'],
            'the gateway knows the real range it forwards into');
        TestCase::assertSame(self::$fx['hotelA_mapped'],
            $gatewayConfig['device']['advertises'][0]['destination'],
            'and the mapped range it receives on');
    }

    /**
     * Rules are written about the address on the recorder and compiled to the
     * address the overlay uses — and a rule about one machine stays about one
     * machine.
     */
    private static function rulesAreTranslatedAndStayPrecise(): void
    {
        TestCase::group('Gateway — rules name the real IP, agents get the mapped one (§36)');

        self::act('hotelA');
        $mapped = self::$fx['hotelA_mapped'];

        // "AK Support may reach the NVR on tcp/554." The NVR is named by the
        // address on its own label.
        AclService::createRule(self::$fx['hotelA_network'], [
            'action'    => 'allow',
            'src_type'  => 'device',
            'src_value' => (string) Device::find(self::$fx['hotelA_laptop'])['device_uid'],
            'dst_type'  => 'cidr',
            'dst_value' => '192.168.1.50/32',
            'protocol'  => 'tcp',
            'port_from' => 554,
            'port_to'   => 554,
            'priority'  => 10,
        ]);

        $config = DeviceService::buildAgentConfig(Device::find(self::$fx['hotelA_laptop']));
        $byDestination = [];
        foreach ($config['routes'] as $route) {
            $byDestination[(string) $route['destination']] = $route;
        }

        $expected = SubnetMapper::mapAddress('192.168.1.50/32', '192.168.1.0/24', $mapped);
        TestCase::assert($expected !== null, 'the NVR maps to an overlay address', (string) $expected);

        TestCase::assert(isset($byDestination[$expected]),
            'the agent gets an entry of its own for the NVR at ' . $expected);
        TestCase::assertSame('192.168.1.50/32',
            (string) $byDestination[$expected]['real_destination'],
            'and is told which real address that is');

        TestCase::assertNotContains('192.168.1.50', (string) json_encode(array_column($config['routes'], 'destination')),
            'no real LAN address appears in anything the agent routes on');

        // The precision that matters: the whole-LAN entry must not have
        // inherited the NVR's rule, or the rule would open tcp/554 on the till
        // as well.
        $subnetFilters = $byDestination[$mapped]['filters'] ?? [];
        TestCase::assertSame(0, count($subnetFilters),
            'the rule about one machine did not become a rule about the whole LAN');

        $hostFilters = $byDestination[$expected]['filters'] ?? [];
        TestCase::assertSame(1, count($hostFilters), 'and it did land on that machine');
        TestCase::assertSame(554, (int) $hostFilters[0]['port_from'], 'on the port it names');
    }

    /**
     * §18: names for overlay devices and for machines behind a gateway, in
     * a zone that is always under .internal.
     */
    private static function namesResolveToOverlayAddresses(): void
    {
        TestCase::group('Names — §18, and only for our own domain');

        self::act('hotelA');
        $network = Network::find(self::$fx['hotelA_network']);
        $zone = DnsZone::forNetwork($network);

        TestCase::assert(str_ends_with($zone, '.internal'),
            'the zone is under .internal, which is reserved for private use', $zone);

        // Name the NVR the way an operator would: by the address on its label.
        RouteHostService::add(self::$fx['hotelA_route'], [
            'label'   => 'nvr',
            'address' => '192.168.1.50',
        ]);

        $records = [];
        foreach (DnsZone::records($network) as $record) {
            $records[$record['name']] = $record['address'];
        }

        $gateway = Device::find(self::$fx['hotelA_gw']);
        $laptop = Device::find(self::$fx['hotelA_laptop']);

        $gatewayName = DnsZone::slug((string) $gateway['name']) . '.' . $zone;
        TestCase::assert(isset($records[$gatewayName]),
            'the gateway device has a name', $gatewayName);
        TestCase::assertSame((string) $gateway['virtual_ip'], $records[$gatewayName] ?? '',
            'and it resolves to its overlay address');

        $laptopName = DnsZone::slug((string) $laptop['name']) . '.' . $zone;
        TestCase::assertSame((string) $laptop['virtual_ip'], $records[$laptopName] ?? '',
            'so does the laptop, including on the laptop itself');

        // The one that matters: the NVR resolves to its MAPPED address.
        // Answering with 192.168.1.50 would send a technician to whatever sits
        // at that address on their own LAN.
        $nvrName = 'nvr.' . DnsZone::slug((string) $gateway['name']) . '.' . $zone;
        $expected = explode('/', (string) SubnetMapper::mapAddress(
            '192.168.1.50/32', '192.168.1.0/24', self::$fx['hotelA_mapped']
        ))[0];

        TestCase::assert(isset($records[$nvrName]), 'the NVR has a name', $nvrName);
        TestCase::assertSame($expected, $records[$nvrName] ?? '',
            'and it resolves to the overlay address, not to 192.168.1.50');
        TestCase::assertNotContains('192.168.1.50', (string) json_encode(array_values($records)),
            'no real LAN address appears in any record');
    }

    /**
     * A zone outside .internal is refused, because a network configured with
     * one would make every one of its agents authoritative for a domain
     * somebody else owns.
     */
    private static function zonesOutsideInternalAreRefused(): void
    {
        TestCase::group('Names — a zone this product may not be authoritative for');

        self::act('hotelA');

        foreach (['google.com', 'acme.co.in', 'localhost', 'internal.evil.com'] as $bad) {
            $refused = false;
            try {
                DnsZone::validate($bad);
            } catch (Throwable $e) {
                $refused = true;
            }
            TestCase::assert($refused, "\"{$bad}\" is refused as a search domain");
        }

        TestCase::assertSame('acme.internal', DnsZone::validate('ACME.Internal.'),
            'and a usable one is accepted, folded and trimmed');
    }

    /** A name must point at a machine the gateway actually routes for. */
    private static function namedHostsMustBeInsideTheirRoute(): void
    {
        TestCase::group('Names — a name outside the advertised range is refused (§18)');

        self::act('hotelA');

        $refused = false;
        try {
            RouteHostService::add(self::$fx['hotelA_route'], [
                'label'   => 'elsewhere',
                'address' => '10.4.4.4',
            ]);
        } catch (Throwable $e) {
            $refused = true;
        }

        TestCase::assert($refused,
            'an address outside 192.168.1.0/24 cannot be named behind that gateway');
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
        TestCase::assert(in_array('192.168.90.0/24', array_column($config['routes'], 'real_destination'), true),
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
