<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\WhatsAppAlerts;

/**
 * Platform → Alerts.
 *
 * Email is where an alert goes to be read tomorrow. A relay that stopped
 * responding, a backup that failed, an update that rolled back — those need to
 * reach a telephone, and the thing that is already open on mine is WhatsApp.
 *
 * The request is configured here rather than coded, because the sending
 * service is an account and not a standard: it has its own field names, its
 * own idea of a token and its own URL, and code that guessed at those would
 * work on exactly one day. See WhatsAppAlerts.
 */
final class AlertsController extends Controller
{
    private const PREFIX = 'alerts.whatsapp.';

    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function update(Request $request): Response
    {
        $input = $request->all();

        Setting::set(self::PREFIX . 'enabled', ($input['enabled'] ?? '') === '1' ? '1' : '0');
        Setting::set(self::PREFIX . 'endpoint', trim((string) ($input['endpoint'] ?? '')));
        Setting::set(self::PREFIX . 'method', strtoupper(trim((string) ($input['method'] ?? 'POST'))));
        Setting::set(self::PREFIX . 'content_type', trim((string) ($input['content_type'] ?? 'application/json')));
        Setting::set(self::PREFIX . 'body', (string) ($input['body'] ?? ''));
        Setting::set(self::PREFIX . 'recipients', trim((string) ($input['recipients'] ?? '')));

        // The headers carry the token. Encrypted at rest, never sent back to
        // the page, and left alone when the box is submitted empty — which is
        // what happens every time somebody changes a recipient and saves.
        $headers = (string) ($input['headers'] ?? '');
        if (trim($headers) !== '') {
            Setting::set(self::PREFIX . 'headers', $headers, null, true);
        }

        AuditService::log('alerts.update', 'setting', null, null, [
            'enabled'    => ($input['enabled'] ?? '') === '1',
            'recipients' => count(WhatsAppAlerts::recipients()),
        ]);

        return $this->redirect('admin/alerts', 'Alert settings saved.');
    }

    /**
     * Send one now, so a misconfiguration is found today rather than during
     * the outage it was set up for.
     */
    public function test(Request $request): Response
    {
        if (!WhatsAppAlerts::enabled()) {
            return $this->redirect('admin/alerts', '', 'Nothing is configured to send to yet.');
        }

        $sent = WhatsAppAlerts::send(
            'critical',
            'Test alert from ' . (string) \App\Core\Config::get('brand.name', 'the panel'),
            'If you are reading this on your telephone, alerts work. Nothing is wrong.'
        );

        return $sent
            ? $this->redirect('admin/alerts', 'Sent. Check the telephone.')
            : $this->redirect('admin/alerts', '',
                'The send failed. The panel log has the endpoint host and the status code it '
                . 'got back — the token itself is not logged anywhere.');
    }

    private function render(): Response
    {
        return $this->view('admin.alerts', [
            'title'    => 'Alerts',
            'settings' => [
                'enabled'      => (string) (Setting::get(self::PREFIX . 'enabled', null) ?? '') === '1',
                'endpoint'     => WhatsAppAlerts::endpoint(),
                'method'       => (string) (Setting::get(self::PREFIX . 'method', null) ?? 'POST'),
                'content_type' => (string) (Setting::get(self::PREFIX . 'content_type', null) ?? 'application/json'),
                'body'         => (string) (Setting::get(self::PREFIX . 'body', null) ?? ''),
                'recipients'   => (string) (Setting::get(self::PREFIX . 'recipients', null) ?? ''),
            ],
            // Whether a token is held, never the token.
            'has_headers' => trim((string) (Setting::get(self::PREFIX . 'headers', null) ?? '')) !== '',
            'ready'       => WhatsAppAlerts::enabled(),
        ]);
    }
}
