<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Relay;
use App\Services\AuditService;

/** Relay fleet management. Platform-level; relays are shared across tenants. */
final class RelayController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('admin.relays', [
            'title'  => 'Relays',
            'relays' => Relay::where([], 'region', 'ASC', 200),
        ]);
    }

    public function store(Request $request): Response
    {
        // No public key and no TCP port.
        //
        // A relay authorises sessions from the coordinator's HMAC ticket and
        // has no keypair at all; the field used to be required, so registering
        // a real relay meant inventing one, which a production deployment did.
        // TCP fallback is not implemented, so a port for it is a number with
        // nothing behind it.
        $data = Validator::validate($request->all(), [
            'name'          => 'required|string|max:120',
            'region'        => 'required|string|max:32',
            'host'          => 'required|hostname',
            'port'          => 'required|port',
            'capacity_mbps' => 'nullable|int|min:1',
        ], ['host' => 'Hostname', 'port' => 'Control port']);

        $relayId = Relay::create([
            'name'          => $data['name'],
            'region'        => strtolower((string) $data['region']),
            'host'          => $data['host'],
            'port'          => (int) $data['port'],
            'tcp_port'      => 0,
            'public_key'    => null,
            'capacity_mbps' => (int) ($data['capacity_mbps'] ?? 100),
            'status'        => 'active',
        ]);

        AuditService::log('relay.create', 'relay', $relayId, null, [
            'name'   => $data['name'],
            'region' => $data['region'],
            'host'   => $data['host'],
        ]);

        return $this->redirect('admin/relays', sprintf('Relay "%s" registered.', $data['name']));
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $relayId = (int) $params['id'];
        $before = Relay::findOrFail($relayId);

        $data = Validator::validate($request->all(), [
            'name'          => 'required|string|max:120',
            'region'        => 'required|string|max:32',
            'host'          => 'required|hostname',
            'port'          => 'required|port',
            'capacity_mbps' => 'nullable|int|min:1',
            'status'        => 'required|in:active,draining,down,disabled',
        ], ['host' => 'Hostname']);

        Relay::update($relayId, [
            'name'          => $data['name'],
            'region'        => strtolower((string) $data['region']),
            'host'          => $data['host'],
            'port'          => (int) $data['port'],
            'capacity_mbps' => (int) ($data['capacity_mbps'] ?? $before['capacity_mbps']),
            'status'        => $data['status'],
        ]);

        AuditService::logChange('relay.update', 'relay', $relayId, $before, Relay::findOrFail($relayId));

        return $this->redirect('admin/relays', 'Relay updated.');
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $relayId = (int) $params['id'];
        $relay = Relay::findOrFail($relayId);

        Relay::delete($relayId);
        AuditService::log('relay.delete', 'relay', $relayId, ['name' => $relay['name']], null);

        // Agents keep their cached relay list until their next config poll, so
        // sessions in progress are not dropped by removing a relay row.
        return $this->redirect('admin/relays', sprintf('Relay "%s" removed. Agents stop using it at their next poll.', $relay['name']));
    }
}
