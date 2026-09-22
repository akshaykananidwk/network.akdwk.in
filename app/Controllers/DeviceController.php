<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Device;
use App\Models\Network;
use App\Services\AuditService;
use App\Services\DeviceService;
use App\Services\IpamService;

/**
 * Device list, detail and the approve/revoke/disable lifecycle actions.
 */
final class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $params = $this->listParams($request, ['name', 'status', 'last_seen_at', 'virtual_ip', 'agent_version'], 'last_seen_at');

        $conditions = [];
        $status = (string) $request->query('status', '');
        if (in_array($status, ['pending', 'authorized', 'disabled', 'revoked'], true)) {
            $conditions['status'] = $status;
        }
        $networkId = (int) $request->query('network', '0');
        if ($networkId > 0) {
            $conditions['network_id'] = $networkId;
        }

        $result = Device::paginate(
            $conditions,
            $params['page'],
            $params['per_page'],
            $params['sort'],
            $params['direction'],
            $params['q'],
            ['name', 'hostname', 'virtual_ip', 'device_uid', 'os']
        );

        return $this->view('devices.index', [
            'title'          => 'Devices',
            'result'         => $result,
            'params'         => $params,
            'networks'       => Network::where([], 'name', 'ASC', 200),
            'filter_status'  => $status,
            'filter_network' => $networkId,
        ]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $device = Device::findOrFail((int) $params['id']);
        $network = $device['network_id'] !== null ? Network::find((int) $device['network_id']) : null;

        return $this->view('devices.show', [
            'title'   => $device['name'],
            'device'  => $device,
            'network' => $network,
            // Shown exactly once, immediately after approval, then gone.
            'issued_token' => Session::flash('issued_device_token'),
        ]);
    }

    /** @param array<string,string> $params */
    public function approve(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];
        $preferredIp = trim((string) $request->input('virtual_ip', ''));

        $result = DeviceService::approve($deviceId, $preferredIp !== '' ? $preferredIp : null);

        // The agent normally collects its token by polling; surfacing it here
        // covers manual and air-gapped installs.
        Session::flash('issued_device_token', $result['token']);

        if ($request->wantsJson()) {
            return Response::api([
                'device'     => $result['device'],
                'virtual_ip' => $result['virtual_ip'],
                'token'      => $result['token'],
            ]);
        }

        return $this->redirect(
            'devices/' . $deviceId,
            sprintf('%s approved and assigned %s.', $result['device']['name'], $result['virtual_ip'])
        );
    }

    /** @param array<string,string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];
        $reason = trim((string) $request->input('reason', 'Revoked by an administrator'));

        DeviceService::revoke($deviceId, $reason);

        if ($request->wantsJson()) {
            return Response::api(['status' => 'revoked']);
        }

        return $this->redirect('devices/' . $deviceId, 'Device revoked. Its peers drop it within 10 seconds.');
    }

    /**
     * Ask this device to check for an agent update now.
     *
     * The agent checks every six hours by itself, which is right for a
     * rollout and useless for somebody standing in front of a machine trying
     * to fix it. This does not push anything: it records a request the agent
     * collects on its next configuration poll, and the agent still refuses a
     * binary whose signature does not verify.
     *
     * @param array<string,string> $params
     */
    public function requestUpdate(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];

        // Through the model's scoped finder, so a device belonging to another
        // customer is a 404 here as it is everywhere else.
        $device = Device::find($deviceId);
        if ($device === null) {
            throw new NotFoundException('App\\Models\\Device #' . $deviceId . ' not found');
        }

        Device::requestUpdate($deviceId);
        AuditService::log('device.update_requested', 'device', $deviceId);

        if ($request->wantsJson()) {
            return Response::api(['status' => 'requested']);
        }

        return $this->redirect(
            'devices/' . $deviceId,
            'Asked this device to check for an update. It picks the request up within a minute, '
                . 'and only installs a release signed by this panel.'
        );
    }

    /** @param array<string,string> $params */
    public function disable(Request $request, array $params): Response
    {
        DeviceService::disable((int) $params['id']);

        return $this->redirect('devices/' . $params['id'], 'Device disabled. It keeps its address.');
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        Validator::validate($request->all(), [
            'name'       => 'nullable|string|max:120',
            'tags'       => 'nullable|string|max:500',
            'is_gateway' => 'nullable|bool',
        ], ['name' => 'Device name']);

        DeviceService::updateMetadata((int) $params['id'], $request->all());

        return $this->redirect('devices/' . $params['id'], 'Device updated.');
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];
        $device = Device::findOrFail($deviceId);

        // Revoke first so the address is released and peers are told, then
        // soft-delete. Deleting without revoking would leave live peers.
        DeviceService::revoke($deviceId, 'Device deleted');
        IpamService::release($deviceId);
        Device::delete($deviceId);

        return $this->redirect('devices', sprintf('%s deleted.', $device['name']));
    }

    /**
     * Bulk approve, used from the pending queue on the dashboard.
     */
    public function bulkApprove(Request $request): Response
    {
        $ids = $request->all()['ids'] ?? [];
        if (!is_array($ids)) {
            return $this->back($request, '', 'No devices selected.');
        }

        $approved = 0;
        $failures = [];

        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            try {
                DeviceService::approve((int) $id);
                $approved++;
            } catch (\App\Core\AppException $e) {
                // A plan limit hit part-way through is the common case; report
                // it rather than silently approving a prefix of the selection.
                $failures[] = $e->userMessage();
            }
        }

        $message = $approved . ' device(s) approved.';

        return $failures === []
            ? $this->back($request, $message)
            : $this->back($request, $message, implode(' ', array_unique($failures)));
    }
}
