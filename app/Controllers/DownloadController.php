<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\EdgeRelease;

/**
 * The stable address for the customer installer.
 *
 * Unauthenticated, deliberately. The whole point is that an operator can send
 * a customer one link and one join code, and a customer who has to sign in to
 * a panel to fetch an installer is a customer on the phone.
 *
 * What is being handed out is a build stamped with this panel's address and
 * nothing else: no join code, no key, no token. Without a code it enrols
 * nowhere, and a code is short-lived, limited and revocable. So the file is
 * not a secret, and treating it as one would cost more than it protects.
 *
 * The address does not change between releases — /download/setup.exe is always
 * the current one — because a link in an email outlives a version number.
 */
final class DownloadController extends Controller
{
    public function windowsSetup(Request $request): Response
    {
        return $this->serve('windows-setup', $request);
    }

    public function windowsPack(Request $request): Response
    {
        return $this->serve('windows-pack', $request);
    }

    private function serve(string $kind, Request $request): Response
    {
        $artifact = EdgeRelease::current($kind);

        if ($artifact === null) {
            // Said plainly rather than as a 404: an operator who has not run
            // the edge upgrade yet needs to know that is what is missing.
            return Response::text(
                "No installer has been published on this panel yet.\n\n"
                    . "Run deploy/upgrade-edge.sh on the edge server; it builds the installer\n"
                    . "for this panel and publishes it here.\n",
                503
            );
        }

        Logger::info('download', 'Installer served', [
            'kind'    => $kind,
            'version' => $artifact['version'],
            'ip'      => $request->ip(),
        ]);

        return Response::file(
            (string) $artifact['path'],
            $kind === 'windows-setup' ? 'akconnect-setup.exe' : 'akconnect-windows-pack.zip',
            'application/octet-stream'
        );
    }
}
