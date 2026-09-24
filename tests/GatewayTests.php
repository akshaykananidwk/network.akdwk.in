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
use App\Core\ValidationException;
use App\Services\RouteService;
use App\Services\RouteHealth;
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
            self::aHostAddressIsTheSameSubnet();
            self::aDeletedDeviceReleasesItsShares();
            self::refusalsSayWhy();
            self::aReEnrolledMachineCanTakeBackItsShare();
            self::aShareIsWorkingOnlyWhenATestProvesIt();
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
    /**
     * 192.168.1.1/24 is not a second network.
     *
     * A field test shared 192.168.1.0/24 on a device and then shared
     * 192.168.1.1/24 on the same device, and got both: the same LAN, twice,
     * with two mapped prefixes allocated to it and two routes for a packet to
     * take. Every check compared strings, so any host address inside a range
     * already advertised was a brand new subnet to them. A person typing the
     * router's own address is naming the network they are on, the way people
     * name it.
     */
    private static function aHostAddressIsTheSameSubnet(): void
    {
        TestCase::group('Gateway — a host address names the subnet it is in, not a new one');

        self::act('hotelA');

        // Normalised on the way in, so what is stored is what a routing table
        // wants and what every later comparison will see.
        $refused = false;
        try {
            RouteService::advertise(self::$fx['hotelA_network'], [
                'destination_cidr' => '192.168.1.1/24',
                'via_device_id'    => self::$fx['hotelA_laptop'],
            ]);
        } catch (Throwable $e) {
            $refused = true;
        }

        TestCase::assert($refused, '192.168.1.1/24 is refused when 192.168.1.0/24 is already shared');

        TestCase::assertSame(1, count(array_filter(
            NetworkRoute::forNetwork(self::$fx['hotelA_network']),
            static fn (array $r): bool => $r['destination_cidr'] === '192.168.1.0/24'
        )), 'and the LAN still has exactly one route and one mapped prefix');

        // Overlap, not just equality: a /16 containing an advertised /24 is
        // not a separate network either.
        $overlapRefused = false;
        try {
            RouteService::advertise(self::$fx['hotelA_network'], [
                'destination_cidr' => '192.168.0.0/16',
                'via_device_id'    => self::$fx['hotelA_laptop'],
            ]);
        } catch (Throwable $e) {
            $overlapRefused = true;
        }

        TestCase::assert($overlapRefused, 'a wider range containing an advertised one is refused');

        // And a range that really is separate is still accepted, or this
        // would have made the feature unusable rather than correct.
        $accepted = RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.9.7/24',
            'via_device_id'    => self::$fx['hotelA_laptop'],
        ]);

        TestCase::assertSame(
            '192.168.9.0/24',
            (string) $accepted['destination_cidr'],
            'a separate range is accepted, stored as its network address'
        );
    }

    /**
     * Deleting a device frees the ranges it shared.
     *
     * A PC was reinstalled and re-enrolled, and could not share the network it
     * had been sharing an hour earlier: its own deleted former self still held
     * both the customer's range and the mapped prefix. Nothing on any page
     * pointed at it, because the device holding the range no longer appeared
     * in any list. A route through a device that does not exist cannot carry
     * a packet; keeping it is a reservation held by nobody.
     */
    private static function aDeletedDeviceReleasesItsShares(): void
    {
        TestCase::group('Gateway — a deleted device does not keep holding its ranges');

        self::act('hotelA');

        $route = RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.77.0/24',
            'via_device_id'    => self::$fx['hotelA_laptop'],
        ]);
        $mapped = (string) $route['mapped_cidr'];

        $released = RouteService::withdrawAllFor(self::$fx['hotelA_laptop']);

        TestCase::assert(in_array('192.168.77.0/24', $released, true),
            'the range is reported as released, so the page can say so');

        TestCase::assertSame(0, count(array_filter(
            NetworkRoute::forNetwork(self::$fx['hotelA_network']),
            static fn (array $r): bool => $r['destination_cidr'] === '192.168.77.0/24'
        )), 'and the route is gone');

        // The same machine, re-enrolled, can share it again. This is the
        // thing that was impossible.
        $again = RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.77.0/24',
            'via_device_id'    => self::$fx['hotelA_laptop'],
        ]);

        TestCase::assertSame('192.168.77.0/24', (string) $again['destination_cidr'],
            'and the same range can be shared again after the holder is deleted');

        TestCase::assert($mapped !== '', 'the mapped prefix was allocated in the first place');

        RouteService::withdrawAllFor(self::$fx['hotelA_laptop']);
    }

    /**
     * A refused share says why, not "Validation failed".
     *
     * The page showed a red bar with no reason and no field marked, on a form
     * whose one input had just been refused for a reason the service had
     * written out in full. getMessage() is the exception's generic label; the
     * reasons are in the errors array.
     */
    private static function refusalsSayWhy(): void
    {
        TestCase::group('Gateway — a refused share names the reason and the device');

        self::act('hotelA');

        RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.88.0/24',
            'via_device_id'    => self::$fx['hotelA_laptop'],
        ]);

        $shown = '';
        try {
            RouteService::advertise(self::$fx['hotelA_network'], [
                'destination_cidr' => '192.168.88.0/24',
                'via_device_id'    => self::$fx['hotelA_laptop'],
            ]);
        } catch (ValidationException $e) {
            $shown = $e->userMessage();
        }

        TestCase::assert($shown !== '' && $shown !== 'Validation failed',
            'the message shown to a person is not the generic label', $shown);
        TestCase::assertContains('192.168.88.0/24', $shown,
            'it names the range that was refused');
        TestCase::assertContains('already advertised', $shown,
            'and says why');

        RouteService::withdrawAllFor(self::$fx['hotelA_laptop']);
    }

    /**
     * A reinstalled PC can take back the range its predecessor held.
     *
     * The holder is a device with the same hostname that has been deleted, so
     * it appears in no list and its route cannot be withdrawn from any page.
     * Somebody who re-enrolled a machine was told the network belonged to a
     * device they could not find, with no way forward at all.
     *
     * Moving keeps the mapped prefix, which is the whole reason to move
     * rather than withdraw and re-create: every other computer goes on
     * reaching the cameras at the address it already knows.
     */
    private static function aReEnrolledMachineCanTakeBackItsShare(): void
    {
        TestCase::group('Gateway — a reinstalled computer can take back its own share');

        self::act('hotelA');

        $route = RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.55.0/24',
            'via_device_id'    => self::$fx['hotelA_laptop'],
        ]);
        $mapped = (string) $route['mapped_cidr'];

        // The machine is reinstalled: same hostname, new enrolment, and the
        // old row is deleted WITHOUT its routes being withdrawn — which is
        // every panel that ran a build before 1.9.7-dev.12.
        $old = Device::findOrFail(self::$fx['hotelA_laptop']);
        $replacement = Device::create([
            'tenant_id'  => (int) $old['tenant_id'],
            'network_id' => (int) $old['network_id'],
            'device_uid' => 'dev_reinstalled_same_box',
            'name'       => (string) $old['name'],
            'public_key' => str_repeat('c', 44),
            'status'     => 'authorized',
            'os'         => (string) $old['os'],
            'arch'       => (string) $old['arch'],
        ]);
        Device::delete((int) $old['id']);

        $takeable = RouteService::takeableBy($replacement);

        TestCase::assertSame(1, count($takeable),
            'the new enrolment is offered the range its predecessor held');

        $moved = RouteService::moveTo((int) $takeable[0]['id'], $replacement);

        TestCase::assertSame($replacement, (int) $moved['via_device_id'],
            'and the route now goes through the machine that is actually running');
        TestCase::assertSame($mapped, (string) $moved['mapped_cidr'],
            'keeping its mapped prefix, so no other computer has to change anything');

        // A live device with a different name is never offered somebody
        // else's range.
        TestCase::assertSame(0, count(RouteService::takeableBy(self::$fx['hotelA_phone'] ?? 0)),
            'and a different machine is offered nothing');

        RouteService::withdrawAllFor($replacement);
    }

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

    /**
     * A share used to be "live" the moment it was approved. On a Windows
     * gateway without WinNAT that was false for ever: the router could not
     * answer the overlay's source address, and the page said live. Now it is
     * NOT working with the reason, not tested, or proven by a test that ran
     * from another computer through the tunnel — never assumed.
     */
    private static function aShareIsWorkingOnlyWhenATestProvesIt(): void
    {
        TestCase::group('Gateway — a share is working only when a test from another computer proves it');

        self::act('hotelA');
        // Fresh devices: the earlier cases delete and replace the fixtures'.
        $gw = self::enrol('hotelA', self::$fx['hotelA_network'], 'sharegw');
        $laptop = self::enrol('hotelA', self::$fx['hotelA_network'], 'sharetester');

        $created = RouteService::advertise(self::$fx['hotelA_network'], [
            'destination_cidr' => '192.168.93.0/24',
            'via_device_id'    => $gw,
        ]);
        RouteService::approve((int) $created['id']);
        $route = NetworkRoute::find((int) $created['id']);
        $mapped = (string) $route['mapped_cidr'];
        $routerAddr = (string) long2ip((int) ip2long(explode('/', $mapped)[0]) + 1);

        $now = gmdate('Y-m-d H:i:s');
        $online = ['last_seen_at' => $now, 'connection_type' => 'relay'];
        $offline = ['last_seen_at' => null, 'connection_type' => 'offline'];

        self::setDevice($gw, $offline);
        $health = RouteHealth::of($route, Device::find($gw), null);
        TestCase::assertSame('broken', $health['state'], 'a share whose gateway is offline is NOT working');
        TestCase::assertContains('offline', $health['detail'], 'and says the gateway is offline');

        self::setDevice($gw, $online);
        $health = RouteHealth::of($route, Device::find($gw), null);
        TestCase::assertSame('untested', $health['state'], 'approved and online is "not tested yet", not live');
        TestCase::assert(!str_contains(strtolower($health['text']), 'live'), 'the word live is gone');

        self::setDevice($gw, ['problems_json' => json_encode([[
            'code' => 'gateway.failed', 'detail' => 'gateway could not start its NAT: boom',
        ]])]);
        $health = RouteHealth::of($route, Device::find($gw), null);
        TestCase::assertSame('broken', $health['state'], 'a gateway whose agent reports gateway.failed is NOT working');
        TestCase::assertContains('could not start its NAT', $health['detail'], 'with the agent\'s reason');
        self::setDevice($gw, ['problems_json' => null]);

        // Gateway mode off with an approved route: the peer set carries no
        // allowed IP for the range. The page says so; the laptop keeps its
        // route, so the traffic fails inside the tunnel rather than going
        // out of the laptop's own network towards whoever owns 10.128/10.
        self::setDevice($gw, ['is_gateway' => 0]);
        $health = RouteHealth::of($route, Device::find($gw), null);
        TestCase::assertSame('broken', $health['state'], 'a share through a device with gateway mode off is NOT working');
        TestCase::assertContains('gateway mode is turned off', $health['detail'], 'and says so');
        $config = DeviceService::buildAgentConfig(Device::find($laptop));
        TestCase::assert(in_array($mapped, array_column($config['routes'], 'destination'), true),
            'and the laptop keeps the route, so the traffic fails closed inside the tunnel');
        $refused = '';
        try {
            RouteHealth::test($route);
        } catch (ValidationException $e) {
            $refused = implode(' ', $e->errors());
        }
        TestCase::assertContains('Gateway mode is turned off', $refused,
            'Test says gateway mode is off, not that the rules forbid it');
        self::setDevice($gw, ['is_gateway' => 1]);
        self::setDevice($gw, $online);

        // The test runs from another computer, never the gateway itself.
        self::setDevice($laptop, $offline);
        $refused = false;
        try {
            RouteHealth::test($route);
        } catch (ValidationException) {
            $refused = true;
        }
        TestCase::assert($refused, 'with no other computer online there is nothing to test from, and it says so');

        self::setDevice($laptop, $online);
        $probe = RouteHealth::test($route);
        TestCase::assertSame($laptop, (int) $probe['device_id'], 'the test is run by the other computer, not the gateway');
        TestCase::assertSame($routerAddr, (string) $probe['target'], 'against the first address of the share — the router');
        TestCase::assertSame(RouteHealth::label($route), (string) $probe['label'], 'tied to this share and this gateway');

        $latest = RouteHealth::latestTests(self::$fx['hotelA_tenant'], [$route])[(int) $route['id']] ?? null;
        TestCase::assertSame('testing', RouteHealth::of($route, Device::find($gw), $latest)['state'],
            'while the laptop has not answered it is testing');

        self::setProbe((int) $probe['id'], [
            'state' => 'ok', 'latency_ms' => 21, 'method' => 'icmp', 'answered_at' => $now,
        ]);
        $latest = RouteHealth::latestTests(self::$fx['hotelA_tenant'], [$route])[(int) $route['id']];
        $health = RouteHealth::of($route, Device::find($gw), $latest);
        TestCase::assertSame('working', $health['state'], 'an answered test proves the share working');
        TestCase::assertContains('hotela-sharetester', $health['detail'], 'naming the computer it was proven from');
        TestCase::assertContains('21 ms', $health['detail'], 'and how long it took');

        // A day on, the proof is history, not a status.
        $health = RouteHealth::of($route, Device::find($gw), $latest, time() + RouteHealth::PROOF_FRESH_SECONDS + 60);
        TestCase::assertSame('stale', $health['state'], 'a proof older than a day is not shown as working');
        TestCase::assertContains('last proven', $health['text'], 'but as when it was last proven');

        // Moved to another computer: the old gateway's proof does not follow.
        $moved = $route;
        $moved['via_device_id'] = $laptop;
        TestCase::assertSame([], RouteHealth::latestTests(self::$fx['hotelA_tenant'], [$moved]),
            'a share moved to another gateway starts untested');

        self::setProbe((int) $probe['id'], ['state' => 'failed', 'error' => 'no reply within 3s']);
        $latest = RouteHealth::latestTests(self::$fx['hotelA_tenant'], [$route])[(int) $route['id']];
        $health = RouteHealth::of($route, Device::find($gw), $latest);
        TestCase::assertSame('broken', $health['state'], 'a failed test is NOT working');
        TestCase::assertContains('no reply within 3s', $health['detail'], 'with what failed');

        // A test nobody picked up is a failure after a few minutes, not
        // "testing…" for ever.
        self::setProbe((int) $probe['id'], ['state' => 'pending', 'error' => null, 'answered_at' => null]);
        $latest = RouteHealth::latestTests(self::$fx['hotelA_tenant'], [$route])[(int) $route['id']];
        $health = RouteHealth::of($route, Device::find($gw), $latest, time() + RouteHealth::PENDING_EXPIRES_SECONDS + 60);
        TestCase::assertSame('broken', $health['state'], 'a test not picked up in minutes is NOT working');
        TestCase::assertContains('did not run the test', $health['detail'], 'and says the tester never ran it');

        // A machine named in the share is what Test reaches by default, at
        // its overlay address (host bits kept, as the agent translates).
        RouteHostService::add((int) $route['id'], ['label' => 'nvr', 'address' => '192.168.93.50']);
        $named = RouteHealth::test($route);
        TestCase::assertSame(explode('.', $routerAddr, 4)[0] . '.' . explode('.', $routerAddr, 4)[1] . '.'
            . explode('.', $routerAddr, 4)[2] . '.50', (string) $named['target'],
            'a machine named in the share is the default target, at its overlay address');

        // A tester whose rules restrict it inside the LAN (tcp/554 on the NVR
        // only) is not chosen while an unrestricted one is online: its probe
        // failing would say nothing about the share.
        $second = self::enrol('hotelA', self::$fx['hotelA_network'], 'sharetester2');
        self::setDevice($second, ['last_seen_at' => gmdate('Y-m-d H:i:s', time() - 5), 'connection_type' => 'relay']);
        self::setDevice($laptop, ['last_seen_at' => gmdate('Y-m-d H:i:s'), 'connection_type' => 'relay']);
        AclService::createRule(self::$fx['hotelA_network'], [
            'action'    => 'allow',
            'src_type'  => 'device',
            'src_value' => (string) Device::find($laptop)['device_uid'],
            'dst_type'  => 'cidr',
            'dst_value' => '192.168.93.0/24',
            'protocol'  => 'tcp',
            'port_from' => 554,
            'port_to'   => 554,
            'priority'  => 10,
        ]);
        $ranked = RouteHealth::test($route, null, $routerAddr);
        TestCase::assertSame($second, (int) $ranked['device_id'],
            'the unrestricted computer tests the share, not the more recently seen one limited to tcp/554');

        // A computer the access rules keep away from the share cannot prove
        // or disprove it, so it is not asked.
        $network = self::$fx['hotelA_network'];
        DB::execute('UPDATE ' . DB::table('networks') . ' SET acl_default_action = \'deny\' WHERE id = :n', ['n' => $network]);
        $refused = '';
        try {
            RouteHealth::test($route);
        } catch (ValidationException $e) {
            $refused = implode(' ', $e->errors());
        }
        DB::execute('UPDATE ' . DB::table('networks') . ' SET acl_default_action = \'allow\' WHERE id = :n', ['n' => $network]);
        TestCase::assertContains('access rules', $refused, 'with the rules denying it, no computer is asked, and it says why');

        $refused = false;
        try {
            RouteHealth::test($route, null, '192.168.93.1');
        } catch (ValidationException) {
            $refused = true;
        }
        TestCase::assert($refused, 'a target outside the share\'s overlay range is refused (the real LAN address is not routable)');

        RouteService::withdraw((int) $route['id']);
    }

    /**
     * Set device columns the model does not let a caller write — the
     * heartbeat's own (last_seen_at, problems_json) — as a heartbeat would.
     *
     * @param array<string,mixed> $columns
     */
    private static function setDevice(int $id, array $columns): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($columns as $column => $value) {
            $sets[] = '`' . $column . '` = :' . $column;
            $params[$column] = $value;
        }
        DB::execute('UPDATE ' . DB::table('devices') . ' SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /**
     * Answer a probe as the agent's report would.
     *
     * @param array<string,mixed> $columns
     */
    private static function setProbe(int $id, array $columns): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($columns as $column => $value) {
            $sets[] = '`' . $column . '` = :' . $column;
            $params[$column] = $value;
        }
        DB::execute('UPDATE ' . DB::table('device_probes') . ' SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }
}
