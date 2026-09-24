<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\UpdateException;
use App\Services\AuditService;
use App\Services\CoordinatorSettings;
use App\Services\EdgeRelease;

/**
 * Settings → Coordinator.
 *
 * DEPLOY.md told an operator to come here long before the page existed. The
 * installer wrote 127.0.0.1 for the host and nothing at all for the public
 * key, so the only way to correct either was to edit config/config.php by
 * hand — a protected file, invisible to the panel, and one more thing to
 * remember on a machine an operator touches once a year.
 */
final class CoordinatorController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function update(Request $request): Response
    {
        CoordinatorSettings::save($request->all());

        return $this->redirect('admin/coordinator', 'Coordinator settings saved.');
    }

    /**
     * Publish an upload that was held because no trusted edge key signed it.
     *
     * The administrator is vouching for the edge key that signed it, by the
     * fingerprint they compared with what the edge prints; from then on that
     * edge's uploads publish by themselves. The fingerprint comes back with
     * the form so the approval is for what was on the screen.
     */
    public function approveHeld(Request $request): Response
    {
        $kind = (string) $request->input('kind', '');
        $fingerprint = (string) $request->input('fingerprint', '');
        $by = (string) (Auth::user()['email'] ?? 'an administrator');

        try {
            EdgeRelease::approveHeld($kind, $fingerprint, $by);
        } catch (UpdateException $e) {
            AuditService::log('edge.upload.approve', 'edge', null, null, ['kind' => $kind, 'error' => $e->getMessage()], 'failure');

            return $this->redirect('admin/coordinator', '', $e->getMessage());
        }

        AuditService::log('edge.upload.approve', 'edge', null, null, ['kind' => $kind, 'fingerprint' => $fingerprint]);

        return $this->redirect('admin/coordinator', 'Published, and edge key ' . $fingerprint
            . ' is now trusted: its uploads publish by themselves from here on.');
    }

    /** Throw a held upload away. */
    public function discardHeld(Request $request): Response
    {
        $kind = (string) $request->input('kind', '');
        EdgeRelease::discardHeld($kind, (string) (Auth::user()['email'] ?? 'an administrator'));
        AuditService::log('edge.upload.discard', 'edge', null, null, ['kind' => $kind]);

        return $this->redirect('admin/coordinator', 'Discarded. Nothing was published.');
    }

    private function render(): Response
    {
        $current = CoordinatorSettings::current();

        return $this->view('admin.coordinator', [
            'title'    => 'Coordinator',
            // The secrets are never sent to the browser. The form shows
            // whether one is set and takes a replacement; blank leaves it.
            'settings' => [
                'host'        => (string) $current['host'],
                'public_host' => (string) $current['public_host'],
                'port'        => (int) $current['port'],
                'public_key'  => (string) $current['public_key'],
                'fallback_url' => (string) $current['fallback_url'],
            ],
            // What the address would be if nobody set one, shown as the
            // field's placeholder. Derived from the panel's own address, so
            // the ordinary deployment needs nothing typed.
            'fallback_default' => CoordinatorSettings::defaultFallbackUrl(),
            'has_shared_secret' => (string) $current['shared_secret'] !== '',
            'has_signing_key'   => (string) $current['signing_key'] !== '',
            'problems'          => CoordinatorSettings::problems(),
            // The panel's updater updates the panel. The coordinator and the
            // relay are Go services on another machine, so the page has to say
            // when they are behind — an operator who ran Update Now and
            // assumed that was all of it is how 1.9.1's two protocol fixes sat
            // unapplied on a live deployment.
            'edge'              => EdgeRelease::status(),
            // What the edge publishes is only published when the edge's own
            // release key signed it; the rest waits here (1.9.7-dev.23).
            'release_key'       => EdgeRelease::fingerprint(EdgeRelease::trustedReleaseKey()),
            'held'              => EdgeRelease::held(),
        ]);
    }
}
