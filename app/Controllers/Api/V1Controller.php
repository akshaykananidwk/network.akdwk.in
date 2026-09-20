<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Network;
use App\Models\NetworkRoute;
use App\Models\Relay;
use App\Models\UsageCounter;
use App\Services\AclService;
use App\Services\DeviceService;
use App\Services\NetworkService;

/**
 * REST API v1 (§8).
 *
 * Every response uses the standard envelope from Response::api(). Every read
 * goes through a tenant-scoped model, so an API key for tenant A cannot reach
 * tenant B's rows by guessing an id — the lookup simply returns 404.
 */
final class V1Controller
{
    // ------------------------------------------------------------- networks

    public function listNetworks(Request $request): Response
    {
        Auth::authorize('network.view');

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = Network::paginate([], $page, $perPage, 'name', 'ASC', (string) $request->query('q', ''), ['name', 'cidr']);

        return Response::api(
            array_map($this->presentNetwork(...), $result['rows']),
            $this->pagination($result)
        );
    }

    public function createNetwork(Request $request): Response
    {
        Auth::authorize('network.create');

        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:120',
            'cidr' => 'required|cidr',
        ], ['cidr' => 'Address range']);

        $network = NetworkService::create(array_merge($request->all(), $data));

        return Response::api($this->presentNetwork($network), [], 201);
    }

    /** @param array<string,string> $params */
    public function getNetwork(Request $request, array $params): Response
    {
        Auth::authorize('network.view');

        return Response::api($this->presentNetwork(Network::findOrFail((int) $params['id'])));
    }

    /** @param array<string,string> $params */
    public function updateNetwork(Request $request, array $params): Response
    {
        Auth::authorize('network.update');

        return Response::api($this->presentNetwork(NetworkService::update((int) $params['id'], $request->all())));
    }

    /** @param array<string,string> $params */
    public function deleteNetwork(Request $request, array $params): Response
    {
        Auth::authorize('network.delete');
        NetworkService::delete((int) $params['id']);

        return Response::noContent();
    }

    /** @param array<string,string> $params */
    public function networkMembers(Request $request, array $params): Response
    {
        Auth::authorize('network.view');

        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        return Response::api(array_map(
            $this->presentDevice(...),
            Device::where(['network_id' => $networkId], 'name', 'ASC', 500)
        ));
    }

    /** @param array<string,string> $params */
    public function networkRoutes(Request $request, array $params): Response
    {
        Auth::authorize('route.view');

        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        return Response::api(NetworkRoute::forNetwork($networkId, false));
    }

    // ------------------------------------------------------------------ ACL

    /** @param array<string,string> $params */
    public function listAcl(Request $request, array $params): Response
    {
        Auth::authorize('acl.view');

        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        return Response::api(\App\Models\AclRule::forNetwork($networkId, false));
    }

    /** @param array<string,string> $params */
    public function createAclRule(Request $request, array $params): Response
    {
        Auth::authorize('acl.manage');

        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        return Response::api(AclService::createRule($networkId, $request->all()), [], 201);
    }

    /** @param array<string,string> $params */
    public function updateAclRule(Request $request, array $params): Response
    {
        Auth::authorize('acl.manage');

        Network::findOrFail((int) $params['id']);

        return Response::api(AclService::updateRule((int) $params['ruleId'], $request->all()));
    }

    /** @param array<string,string> $params */
    public function deleteAclRule(Request $request, array $params): Response
    {
        Auth::authorize('acl.manage');

        Network::findOrFail((int) $params['id']);
        AclService::deleteRule((int) $params['ruleId']);

        return Response::noContent();
    }

    // -------------------------------------------------------------- devices

    public function listDevices(Request $request): Response
    {
        Auth::authorize('device.view');

        $conditions = [];
        $status = (string) $request->query('status', '');
        if (in_array($status, ['pending', 'authorized', 'disabled', 'revoked'], true)) {
            $conditions['status'] = $status;
        }
        if ((int) $request->query('network_id', '0') > 0) {
            $conditions['network_id'] = (int) $request->query('network_id');
        }

        $result = Device::paginate(
            $conditions,
            max(1, (int) $request->query('page', '1')),
            min(100, max(1, (int) $request->query('per_page', '25'))),
            'last_seen_at',
            'DESC',
            (string) $request->query('q', ''),
            ['name', 'hostname', 'virtual_ip', 'device_uid']
        );

        return Response::api(array_map($this->presentDevice(...), $result['rows']), $this->pagination($result));
    }

    /** @param array<string,string> $params */
    public function getDevice(Request $request, array $params): Response
    {
        Auth::authorize('device.view');

        return Response::api($this->presentDevice(Device::findOrFail((int) $params['id'])));
    }

    /** @param array<string,string> $params */
    public function updateDevice(Request $request, array $params): Response
    {
        Auth::authorize('device.update');

        return Response::api($this->presentDevice(DeviceService::updateMetadata((int) $params['id'], $request->all())));
    }

    /** @param array<string,string> $params */
    public function approveDevice(Request $request, array $params): Response
    {
        Auth::authorize('device.approve');

        $preferred = trim((string) $request->input('virtual_ip', ''));
        $result = DeviceService::approve((int) $params['id'], $preferred !== '' ? $preferred : null);

        return Response::api([
            'device'     => $this->presentDevice($result['device']),
            'virtual_ip' => $result['virtual_ip'],
            // The only time a device token is ever returned to an API caller.
            'device_token' => $result['token'],
        ]);
    }

    /** @param array<string,string> $params */
    public function revokeDevice(Request $request, array $params): Response
    {
        Auth::authorize('device.revoke');

        DeviceService::revoke((int) $params['id'], (string) $request->input('reason', 'Revoked via API'));

        return Response::api(['status' => 'revoked']);
    }

    /** @param array<string,string> $params */
    public function disableDevice(Request $request, array $params): Response
    {
        Auth::authorize('device.update');

        DeviceService::disable((int) $params['id']);

        return Response::api(['status' => 'disabled']);
    }

    /** @param array<string,string> $params */
    public function deleteDevice(Request $request, array $params): Response
    {
        Auth::authorize('device.delete');

        $deviceId = (int) $params['id'];
        Device::findOrFail($deviceId);
        DeviceService::revoke($deviceId, 'Deleted via API');
        Device::delete($deviceId);

        return Response::noContent();
    }

    // ------------------------------------------------------------ platform

    public function listRelays(Request $request): Response
    {
        // Relay hostnames and public keys are not tenant secrets — every agent
        // receives them — but capacity and session counts are operational
        // detail, so only the connection fields are exposed here.
        return Response::api(array_map(
            static fn (array $relay): array => [
                'name'       => $relay['name'],
                'region'     => $relay['region'],
                'host'       => $relay['host'],
                'port'       => (int) $relay['port'],
                'tcp_port'   => (int) $relay['tcp_port'],
                'public_key' => $relay['public_key'],
            ],
            Relay::availableFor((string) $request->query('region', ''))
        ));
    }

    public function auditLogs(Request $request): Response
    {
        Auth::authorize('audit.view');

        $result = AuditLog::search(
            array_filter([
                'action' => (string) $request->query('action', ''),
                'result' => (string) $request->query('result', ''),
                'from'   => (string) $request->query('from', ''),
                'to'     => (string) $request->query('to', ''),
            ]),
            max(1, (int) $request->query('page', '1')),
            min(200, max(1, (int) $request->query('per_page', '50')))
        );

        return Response::api($result['rows'], $this->pagination($result));
    }

    public function usage(Request $request): Response
    {
        Auth::authorize('billing.view');

        $tenantId = Auth::tenantId();
        if ($tenantId === null) {
            return Response::apiError('Usage is reported per customer account.', 400);
        }

        $period = (string) $request->query('period', UsageCounter::currentPeriod());

        return Response::api([
            'period'  => $period,
            'metrics' => UsageCounter::forPeriod($tenantId, $period),
            'limits'  => \App\Services\BillingService::usageMeters($tenantId),
        ]);
    }

    // ------------------------------------------------------------ presenters

    /**
     * @param array<string,mixed> $network
     * @return array<string,mixed>
     */
    private function presentNetwork(array $network): array
    {
        return [
            'id'                 => (int) $network['id'],
            'uid'                => $network['network_uid'],
            'name'               => $network['name'],
            'description'        => $network['description'],
            'cidr'               => $network['cidr'],
            'dns'                => $network['dns_json'] ?? [],
            'search_domain'      => $network['search_domain'],
            'mtu'                => (int) $network['mtu'],
            'auto_assign_ip'     => (bool) $network['auto_assign_ip'],
            'private'            => (bool) $network['private'],
            'acl_default_action' => $network['acl_default_action'],
            'status'             => $network['status'],
            'revision'           => (int) $network['config_revision'],
            'created_at'         => $network['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function presentDevice(array $device): array
    {
        return [
            'id'              => (int) $device['id'],
            'uid'             => $device['device_uid'],
            'name'            => $device['name'],
            'hostname'        => $device['hostname'],
            'os'              => $device['os'],
            'os_version'      => $device['os_version'],
            'agent_version'   => $device['agent_version'],
            'network_id'      => $device['network_id'] !== null ? (int) $device['network_id'] : null,
            'virtual_ip'      => $device['virtual_ip'],
            'status'          => $device['status'],
            'connection_type' => $device['connection_type'],
            'last_seen_at'    => $device['last_seen_at'],
            'last_endpoint'   => $device['last_endpoint'],
            'rx_bytes'        => (int) $device['rx_bytes'],
            'tx_bytes'        => (int) $device['tx_bytes'],
            'latency_ms'      => $device['latency_ms'] !== null ? (int) $device['latency_ms'] : null,
            'tags'            => $device['tags_json'] ?? [],
            'is_gateway'      => (bool) $device['is_gateway'],
            'approved_at'     => $device['approved_at'],
            // public_key is deliberately included: peers need it and it is not
            // a secret. The private half never existed on this server (R5).
            'public_key'      => $device['public_key'],
        ];
    }

    /**
     * @param array{total:int,page:int,per_page:int,pages:int} $result
     * @return array<string,int>
     */
    private function pagination(array $result): array
    {
        return [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
        ];
    }
}
