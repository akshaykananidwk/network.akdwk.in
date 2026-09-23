<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Device;
use App\Models\DeviceProbe;
use App\Models\Network;
use App\Core\ValidationException;
use App\Services\AgentUpdateStatus;
use App\Services\AuditService;
use App\Services\DeviceService;
use App\Services\IpamService;
use App\Services\ProbeService;
use App\Services\RouteService;

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
            // "3 of 3 on 1.9.7-dev.7", and who is not.
            'fleet_updates'  => AgentUpdateStatus::fleet(),
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
            // What this computer's own network probably is, for the one-click
            // share. A suggestion in an editable box — see suggestLan.
            'suggested_lan' => RouteService::suggestLan($device['last_lan_endpoint'] ?? null),
            'shared_lans'   => $device['network_id'] !== null
                ? RouteService::forDevice((int) $device['id'])
                : [],
            // The other devices on this network, so each can be tested from
            // this one. Reachability is a property of a pair, and the panel
            // can measure it from neither end.
            'network_peers' => $device['network_id'] !== null
                ? array_values(array_filter(
                    Device::where(['network_id' => (int) $device['network_id']], 'name'),
                    static fn (array $peer): bool => (int) $peer['id'] !== (int) $device['id']
                ))
                : [],
            // The most recent answer for each address this device has been
            // asked about, keyed by address.
            'probe_results' => DeviceProbe::latestByTarget((int) $device['id']),
            // Why this device is or is not being offered a newer agent. The
            // panel's own answer, not the device's — a device that was told
            // there is nothing for it has nothing to report.
            'update_status' => AgentUpdateStatus::forDevice($device),
            // Ranges held by this machine's deleted predecessor, which it can
            // take back rather than being told they belong to a device that
            // appears in no list.
            'takeable_lans' => RouteService::takeableBy((int) $device['id']),
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
     * Share the LAN this device is on, in one click.
     *
     * What a customer actually wants — "let the other shops reach the camera
     * recorder at 192.168.10.1" — took five steps across two pages. This is
     * the whole of it: the range, confirmed, and a route that is live.
     *
     * @param array<string,string> $params
     */
    /**
     * Take over a share held by this machine's deleted predecessor.
     *
     * @param array<string,string> $params
     */
    public function takeShare(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];

        $device = Device::find($deviceId);
        if ($device === null) {
            throw new NotFoundException('App\\Models\\Device #' . $deviceId . ' not found');
        }

        try {
            $route = RouteService::moveTo((int) $request->input('route_id', '0'), $deviceId);
        } catch (ValidationException $e) {
            return $this->redirect('devices/' . $deviceId, '', $e->userMessage());
        }

        return $this->redirect(
            'devices/' . $deviceId,
            sprintf(
                '%s is now shared through this computer, still reachable at %s — the other '
                    . 'computers do not have to change anything.',
                (string) $route['destination_cidr'],
                (string) $route['mapped_cidr']
            )
        );
    }

    public function shareLan(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];

        $device = Device::find($deviceId);
        if ($device === null) {
            throw new NotFoundException('App\\Models\\Device #' . $deviceId . ' not found');
        }

        $cidr = trim((string) $request->input('destination_cidr', ''));
        if ($cidr === '') {
            return $this->redirect('devices/' . $deviceId, '', 'Enter the range this computer should share.');
        }

        try {
            $route = RouteService::shareLan($deviceId, $cidr);
        } catch (ValidationException $e) {
            // userMessage(), not getMessage().
            //
            // getMessage() is the exception's own generic label — "Validation
            // failed" — and that is exactly what the page showed: a red bar
            // with no reason and no field marked, on a form whose one input
            // had just been refused for a reason the service had written out
            // in full. The reasons live in the errors array and userMessage
            // is what renders them.
            return $this->redirect('devices/' . $deviceId, '', $e->userMessage());
        }

        return $this->redirect(
            'devices/' . $deviceId,
            sprintf(
                'Sharing %s through this computer. The others reach it at %s — the same last number, '
                    . 'so 192.168.10.1 becomes %s.1. Give it up to a minute.',
                (string) $route['destination_cidr'],
                (string) $route['mapped_cidr'],
                rtrim(substr((string) $route['mapped_cidr'], 0, (int) strrpos((string) $route['mapped_cidr'], '.')), '.')
            )
        );
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
    /**
     * Ask this device to test whether it can reach an address.
     *
     * The device answers, not the panel: the panel is on the public internet
     * and can reach neither the overlay nor anybody's camera recorder, and
     * the agent is already inside both.
     *
     * @param array<string,string> $params
     */
    public function probe(Request $request, array $params): Response
    {
        $deviceId = (int) $params['id'];

        $device = Device::find($deviceId);
        if ($device === null) {
            throw new NotFoundException('App\\Models\\Device #' . $deviceId . ' not found');
        }

        $probe = ProbeService::request(
            $deviceId,
            (string) $request->input('target', ''),
            $request->input('label') !== null ? (string) $request->input('label') : null
        );

        if ($request->wantsJson()) {
            return Response::api(['status' => 'requested', 'probe_id' => (int) $probe['id']]);
        }

        return $this->redirect(
            'devices/' . $deviceId,
            'Testing ' . $probe['target'] . ' from this computer. The answer appears here '
                . 'within a minute — it is measured on the machine, not from the panel.'
        );
    }

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

        // And take its shared ranges with it.
        //
        // A deleted device left its routes behind, holding both the customer's
        // range and the mapped prefix allocated to it — so the SAME PC,
        // re-enrolled after a reinstall, could not share the network it had
        // just been sharing: its own former self was still advertising it.
        // Nothing pointed at the cause, because the device holding the range
        // no longer appeared anywhere.
        //
        // A route through a device that does not exist cannot carry a packet.
        // Keeping it is not caution, it is a reservation held by nobody.
        $released = RouteService::withdrawAllFor($deviceId);

        Device::delete($deviceId);

        $message = sprintf('%s deleted.', $device['name']);
        if ($released !== []) {
            $message .= ' ' . sprintf(
                'Its shared range%s %s %s free again, so the same computer can share %s after '
                    . 're-enrolling.',
                count($released) === 1 ? '' : 's',
                implode(' and ', $released),
                count($released) === 1 ? 'is' : 'are',
                count($released) === 1 ? 'it' : 'them'
            );
        }

        return $this->redirect('devices', $message);
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
