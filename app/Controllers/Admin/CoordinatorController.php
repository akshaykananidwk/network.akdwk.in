<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
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
            ],
            'has_shared_secret' => (string) $current['shared_secret'] !== '',
            'has_signing_key'   => (string) $current['signing_key'] !== '',
            'problems'          => CoordinatorSettings::problems(),
            // The panel's updater updates the panel. The coordinator and the
            // relay are Go services on another machine, so the page has to say
            // when they are behind — an operator who ran Update Now and
            // assumed that was all of it is how 1.9.1's two protocol fixes sat
            // unapplied on a live deployment.
            'edge'              => EdgeRelease::status(),
        ]);
    }
}
