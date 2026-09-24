#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Trust an edge server's release key, from the panel's own machine.
 *
 *   php cli/edge-trust.php [--root=/path/to/panel]                 what is trusted, what is held
 *   php cli/edge-trust.php [--root=…] --key=<hex> [--publish-held]  trust that key
 *
 * From 1.9.7-dev.23 the panel publishes an installer or an agent update only
 * when the edge's own ed25519 release key signed it. Which key that is has to
 * come from somewhere the shared secret cannot reach, or the secret would
 * simply bring its own key. Two places qualify: an administrator approving a
 * held upload under Platform → Coordinator, and this — somebody who can run
 * commands as the panel's own user on the panel's own machine, which is
 * already everything.
 *
 * deploy/upgrade-edge.sh runs it when the panel is on the edge's machine (the
 * getting-started layout), so there nobody has to approve anything.
 *
 * --publish-held also publishes what is waiting and was signed by that key.
 *
 * The key is a PUBLIC key; it is fine on a command line. Nothing secret is
 * read or printed.
 */

// --root=<panel>: see _root.php. upgrade-edge.sh runs this copy, from the edge
// source, against the panel on its machine.
require __DIR__ . '/_root.php';
require __DIR__ . '/_bootstrap.php';

use App\Core\UpdateException;
use App\Services\EdgeRelease;

$options = cli_options($argv);

if (!method_exists(EdgeRelease::class, 'trustReleaseKey')) {
    fwrite(STDERR, "This panel predates edge release keys (1.9.7-dev.23). Update it first.\n");
    exit(3);
}

if (isset($options['key'])) {
    try {
        $fingerprint = EdgeRelease::trustReleaseKey((string) $options['key'], 'root on the panel\'s machine');
    } catch (UpdateException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    cli_out('  trusted edge key ' . $fingerprint);

    if (isset($options['publish-held'])) {
        foreach (EdgeRelease::held() as $kind => $upload) {
            if ($upload['other_key']) {
                cli_out('  left waiting: ' . $kind . ' ' . $upload['version'] . ', signed by another key ('
                    . $upload['fingerprint'] . ')');

                continue;
            }

            try {
                EdgeRelease::approveHeld($kind, (string) $upload['fingerprint'], 'root on the panel\'s machine');
                cli_out('  published the held ' . $kind . ' ' . $upload['version']);
            } catch (UpdateException $e) {
                cli_out('  could not publish the held ' . $kind . ': ' . $e->getMessage());
            }
        }
    }

    exit(0);
}

$trusted = EdgeRelease::trustedReleaseKey();
cli_out('  trusted edge key: ' . ($trusted === '' ? 'none' : EdgeRelease::fingerprint($trusted)));

$held = EdgeRelease::held();
if ($held === []) {
    cli_out('  nothing is waiting for approval');
}
foreach ($held as $kind => $upload) {
    cli_out(sprintf(
        '  waiting: %s %s, signed by %s%s, received %s UTC',
        $kind,
        (string) $upload['version'],
        (string) $upload['fingerprint'],
        $upload['other_key'] ? ' — NOT the trusted key' : '',
        (string) $upload['received_at']
    ));
}

exit(0);
