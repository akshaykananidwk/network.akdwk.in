<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Tenant;
use App\Services\AclService;
use App\Services\AuditService;
use App\Services\IpamService;
use App\Services\NetworkService;

/**
 * Network CRUD plus the per-network tabs: members, IP management, routes, ACL.
 *
 * Every lookup goes through Network::findOrFail(), which is tenant-scoped, so
 * an id belonging to another customer returns 404 rather than data.
 */
final class NetworkController extends Controller
{
    public function index(Request $request): Response
    {
        $params = $this->listParams($request, ['name', 'cidr', 'status', 'created_at'], 'name');

        $result = Network::paginate(
            [],
            $params['page'],
            $params['per_page'],
            $params['sort'],
            $params['sort'] === 'name' ? 'ASC' : $params['direction'],
            $params['q'],
            ['name', 'cidr', 'network_uid', 'description']
        );

        $counts = Network::deviceCounts(array_map(static fn (array $n): int => (int) $n['id'], $result['rows']));
        foreach ($result['rows'] as $index => $network) {
            $result['rows'][$index]['device_counts'] = $counts[(int) $network['id']]
                ?? ['total' => 0, 'online' => 0, 'pending' => 0];
        }

        return $this->view('networks.index', [
            'title'  => 'Networks',
            'result' => $result,
            'params' => $params,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('networks.form', [
            'title'   => 'New network',
            'network' => null,
            // Only a platform administrator sees this: they have no tenant of
            // their own, so the form has to ask which customer the network is
            // for. Shown even when the list is empty, because "no selector"
            // and "no customers" look identical from the other side of the
            // screen and only one of them is fixable by the person looking.
            'is_platform' => Auth::tenantId() === null,
            'tenants'     => Auth::tenantId() === null ? Tenant::allActive() : [],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'name'                 => 'required|string|max:120',
            'description'          => 'nullable|string|max:255',
            'cidr'                 => 'required|cidr',
            'mtu'                  => 'nullable|int|between:576,1500',
            'keepalive_seconds'    => 'nullable|int|between:0,180',
            'search_domain'        => 'nullable|string|max:190',
            'mapped_pool'          => 'nullable|string|max:20',
            'auto_assign_ip'       => 'nullable|bool',
            'auto_approve_devices' => 'nullable|bool',
            'acl_default_action'   => 'nullable|in:allow,deny',
            'tenant_id'            => 'nullable|int',
        ], ['cidr' => 'Address range', 'mtu' => 'MTU', 'tenant_id' => 'Customer']);

        $data['dns'] = $this->parseDnsList((string) $request->input('dns', ''));

        $network = NetworkService::create($data);

        return $this->redirect(
            'networks/' . $network['id'],
            sprintf('Network "%s" created with range %s.', $network['name'], $network['cidr'])
        );
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $detail = NetworkService::detail((int) $params['id']);

        return $this->view('networks.show', array_merge($detail, [
            'title'           => $detail['network']['name'],
            'active_tab'      => (string) $request->query('tab', 'members'),
            'ip_map'          => IpamService::map((int) $params['id']),
            'install_windows' => $detail['join_code'] !== null
                ? NetworkService::installCommand((string) $detail['join_code']['code'], 'windows')
                : null,
            'install_unix'    => $detail['join_code'] !== null
                ? NetworkService::installCommand((string) $detail['join_code']['code'], 'linux')
                : null,
        ]));
    }

    /** @param array<string,string> $params */
    public function edit(Request $request, array $params): Response
    {
        return $this->view('networks.form', [
            'title'   => 'Edit network',
            'network' => Network::findOrFail((int) $params['id']),
            // A network never changes customer, so the selector is create-only.
            'is_platform' => false,
            'tenants'     => [],
        ]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $data = Validator::validate($request->all(), [
            'name'                 => 'required|string|max:120',
            'description'          => 'nullable|string|max:255',
            'cidr'                 => 'nullable|cidr',
            'mtu'                  => 'nullable|int|between:576,1500',
            'keepalive_seconds'    => 'nullable|int|between:0,180',
            'search_domain'        => 'nullable|string|max:190',
            'mapped_pool'          => 'nullable|string|max:20',
            'auto_assign_ip'       => 'nullable|bool',
            'auto_approve_devices' => 'nullable|bool',
            'acl_default_action'   => 'nullable|in:allow,deny',
            'status'               => 'nullable|in:active,paused,archived',
        ], ['cidr' => 'Address range']);

        if ($request->input('dns') !== null) {
            $data['dns'] = $this->parseDnsList((string) $request->input('dns', ''));
        }

        $network = NetworkService::update((int) $params['id'], $data);

        return $this->redirect('networks/' . $network['id'], 'Network updated.');
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        NetworkService::delete((int) $params['id']);

        return $this->redirect('networks', 'Network archived. Its devices have been detached and revoked.');
    }

    // --------------------------------------------------------- join codes

    /** @param array<string,string> $params */
    public function issueJoinCode(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        $network = Network::findOrFail($networkId);

        $ttl = (int) $request->input('ttl_minutes', '60');
        $maxUses = (int) $request->input('max_uses', '0');
        $preApproved = $request->boolean('pre_approved');

        // Pre-approval is a decision taken now, about a device that does not
        // exist yet, so it comes with its own limits rather than inheriting
        // the ordinary ones. An unlimited pre-approved code that lives for a
        // week is a standing invitation, which is not what anyone means by it.
        if ($preApproved) {
            $maxUses = $maxUses > 0 ? min($maxUses, 25) : 1;
            $ttl = min(max($ttl, 5), 120);
        }

        $code = JoinCode::issue(
            (int) $network['tenant_id'],
            $networkId,
            Auth::id(),
            max(0, $maxUses),
            max(5, min($ttl, 10080)),
            $preApproved
        );

        AuditService::log('network.join_code.issue', 'network', $networkId, null, [
            'expires_at'   => $code['expires_at'],
            'max_uses'     => $maxUses,
            // Recorded because it is the whole of R4 for the devices that use
            // this code: an administrator decided, here, in advance.
            'pre_approved' => $preApproved,
        ]);

        if ($request->wantsJson()) {
            return Response::api([
                'code'            => $code['code'],
                'expires_at'      => $code['expires_at'],
                'install_windows' => NetworkService::installCommand($code['code'], 'windows'),
                'install_unix'    => NetworkService::installCommand($code['code'], 'linux'),
            ]);
        }

        return $this->redirect(
            'networks/' . $networkId,
            $preApproved
                ? sprintf(
                    'Pre-approved join code issued: the next %s to use it joins without waiting for approval. '
                        . 'It expires at %s and can be revoked before then.',
                    $maxUses === 1 ? 'device' : $maxUses . ' devices',
                    local_time($code['expires_at'])
                )
                : 'New join code issued.'
        );
    }

    /** @param array<string,string> $params */
    public function revokeJoinCodes(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        $revoked = JoinCode::revokeAllForNetwork($networkId);
        AuditService::log('network.join_code.revoke_all', 'network', $networkId, null, ['revoked' => $revoked]);

        return $this->redirect('networks/' . $networkId, $revoked . ' join code(s) revoked.');
    }

    // ------------------------------------------------------ IP management

    /** @param array<string,string> $params */
    public function reserveIp(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        $data = Validator::validate($request->all(), [
            'ip'    => 'required|ipv4',
            'label' => 'nullable|string|max:120',
        ], ['ip' => 'IP address']);

        IpamService::reserve($networkId, (string) $data['ip'], (string) ($data['label'] ?? 'Reserved'));
        AuditService::log('network.ip.reserve', 'network', $networkId, null, ['ip' => $data['ip']]);

        return $this->redirect('networks/' . $networkId . '?tab=ips', $data['ip'] . ' reserved.');
    }

    /** @param array<string,string> $params */
    public function releaseIp(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        $ip = (string) $request->input('ip', '');
        IpamService::unreserve($networkId, $ip);
        AuditService::log('network.ip.unreserve', 'network', $networkId, null, ['ip' => $ip]);

        return $this->redirect('networks/' . $networkId . '?tab=ips', $ip . ' returned to the pool.');
    }

    // ---------------------------------------------------------------- ACL

    /** @param array<string,string> $params */
    public function storeAclRule(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        AclService::createRule($networkId, $request->all());

        return $this->redirect(
            'networks/' . $networkId . '?tab=acl',
            'Rule added. Connected devices pick it up within 10 seconds.'
        );
    }

    /** @param array<string,string> $params */
    public function updateAclRule(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        AclService::updateRule((int) $params['ruleId'], $request->all());

        return $this->redirect('networks/' . $networkId . '?tab=acl', 'Rule updated.');
    }

    /** @param array<string,string> $params */
    public function deleteAclRule(Request $request, array $params): Response
    {
        $networkId = (int) $params['id'];
        Network::findOrFail($networkId);

        AclService::deleteRule((int) $params['ruleId']);

        return $this->redirect('networks/' . $networkId . '?tab=acl', 'Rule removed.');
    }

    /**
     * Parse a comma or newline separated DNS server list.
     *
     * @return list<string>
     */
    private function parseDnsList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter(
            $parts,
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
    }
}
