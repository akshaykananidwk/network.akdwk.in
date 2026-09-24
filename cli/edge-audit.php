#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * What has been published to this panel with the coordinator's shared secret.
 *
 *   php cli/edge-audit.php [--root=/path/to/panel] [--since=YYYY-MM-DD]
 *   php cli/edge-audit.php --withdraw=<version>   stop offering that agent release
 *
 * The shared secret is what upgrade-edge.sh signs its uploads with, and the
 * panel accepts an installer or an agent binary from whoever holds it — and
 * signs an uploaded agent with its own key and offers it to every device as a
 * self-update. So when the secret has been seen by someone else, rotating it
 * stops the next upload, and this says what may already have happened:
 *
 *   - the installer and the agent binary this panel is serving now, with the
 *     version, the sha256 and when they were published;
 *   - every agent release registered since --since (default: 30 days ago),
 *     each marked when its version is NEWER than this panel's: an edge only
 *     ever builds the release its panel is on, so a later version cannot have
 *     come from upgrade-edge.sh — and it is exactly what a device would take
 *     as an update. Earlier versions are this panel's own past releases.
 *
 * It reads and prints, and changes nothing unless --withdraw is given. Nothing
 * secret is printed.
 * Used by deploy/rotate-secret.sh (akconnect-rotate-secret).
 */

// --root=<panel>: see _root.php.
require __DIR__ . '/_root.php';
require __DIR__ . '/_bootstrap.php';

use App\Core\DB;
use App\Core\Logger;
use App\Models\Setting;
use App\Services\EdgeRelease;
use App\Updater\UpdateEnv;

$options = cli_options($argv);

// Withdrawn, not deleted: the row stays as evidence, and a withdrawn release is
// never offered to a device again.
if (isset($options['withdraw'])) {
    $version = (string) $options['withdraw'];
    if (preg_match('/^[\w.\-+]{1,32}$/', $version) !== 1) {
        fwrite(STDERR, "--withdraw needs a version: --withdraw=9.9.9\n");
        exit(1);
    }
    $count = DB::execute(
        'UPDATE ' . DB::table('agent_releases') . '
         SET deleted_at = UTC_TIMESTAMP(), rollout_percent = 0
         WHERE version = :v AND deleted_at IS NULL',
        ['v' => $version]
    );
    Logger::notice('update', 'Agent release withdrawn from the command line', ['version' => $version]);
    cli_out('  ' . $count->rowCount() . ' agent release(s) at ' . $version . ' withdrawn; no device will be offered it.');
    exit(0);
}

$since = (string) ($options['since'] ?? gmdate('Y-m-d', time() - 30 * 86400));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
    fwrite(STDERR, "--since must be a date: --since=2026-09-01\n");
    exit(1);
}

$panelVersion = method_exists(UpdateEnv::class, 'currentVersion')
    ? UpdateEnv::currentVersion(APP_ROOT)
    : trim((string) @file_get_contents(APP_ROOT . '/VERSION'));

cli_out('  This panel runs ' . $panelVersion . '.');
cli_out('');
cli_out('  Served now:');

foreach (['windows-setup' => 'the installer (setup.exe)', 'windows-agent' => 'the agent binary   '] as $kind => $label) {
    $version = (string) (Setting::get('edge.' . $kind . '.version', null) ?? '');
    if ($version === '') {
        cli_out('    ' . $label . '  nothing published');

        continue;
    }

    cli_out(sprintf(
        '    %s  %s, published %s UTC, sha256 %s%s',
        $label,
        $version,
        (string) (Setting::get('edge.' . $kind . '.published_at', null) ?? '?'),
        (string) (Setting::get('edge.' . $kind . '.sha256', null) ?? '?'),
        version_compare($version, $panelVersion, '>') ? '   ← NEWER than this panel: not built by this edge' : ''
    ));
}

// From 1.9.7-dev.23 nothing is published unless the edge's own release key
// signed it; what arrived with the shared secret alone is held, or refused.
if (method_exists(EdgeRelease::class, 'trustedReleaseKey')) {
    $trusted = EdgeRelease::trustedReleaseKey();
    cli_out('');
    cli_out('  Trusted edge release key: ' . ($trusted === '' ? 'none yet' : EdgeRelease::fingerprint($trusted)));
    foreach (EdgeRelease::held() as $kind => $upload) {
        cli_out(sprintf(
            '    waiting for an administrator: %s %s, signed by %s%s, received %s UTC',
            $kind,
            (string) $upload['version'],
            (string) $upload['fingerprint'],
            $upload['other_key'] ? '  ← NOT the trusted key: discard it unless you replaced the edge' : '',
            (string) $upload['received_at']
        ));
    }
}

$rows = DB::select(
    'SELECT version, channel, platform, arch, sha256, rollout_percent, created_at, published_at, deleted_at
     FROM ' . DB::table('agent_releases') . '
     WHERE created_at >= :since OR published_at >= :since2
     ORDER BY created_at ASC',
    ['since' => $since . ' 00:00:00', 'since2' => $since . ' 00:00:00']
);

cli_out('');
cli_out('  Agent releases registered since ' . $since . ':');

$foreign = 0;
if ($rows === []) {
    cli_out('    none');
}
foreach ($rows as $row) {
    $newer = version_compare((string) $row['version'], $panelVersion, '>');
    $withdrawn = $row['deleted_at'] !== null;
    if ($newer && !$withdrawn) {
        $foreign++;
    }

    cli_out(sprintf(
        '    %-18s %-6s %s/%s  rollout %3d%%  registered %s UTC  sha256 %s%s',
        (string) $row['version'],
        (string) $row['channel'],
        (string) $row['platform'],
        (string) $row['arch'],
        (int) $row['rollout_percent'],
        (string) $row['created_at'],
        (string) $row['sha256'],
        $withdrawn ? '   (withdrawn)' : ($newer ? '   ← NEWER than this panel: not built by this edge' : '')
    ));
}

cli_out('');
if ($foreign > 0) {
    cli_out('  ' . $foreign . ' release(s) above are newer than this panel. upgrade-edge.sh only ever builds');
    cli_out('  the panel\'s own release, so they were uploaded by something else holding the secret,');
    cli_out('  and every device on an older version would take them as an update.');
    cli_out('  Treat them as hostile. Withdraw each one before any device updates to it:');
    cli_out('      sudo -u <web user> php ' . APP_ROOT . '/cli/edge-audit.php --withdraw=<version>');
    cli_out('  and republish from source: sudo /opt/akconnect/src/deploy/upgrade-edge.sh --force');
} else {
    cli_out('  Nothing above is newer than this panel. An upload made with the secret under one');
    cli_out('  of these versions would look the same here, so republish from source —');
    cli_out('      sudo /opt/akconnect/src/deploy/upgrade-edge.sh --force');
    cli_out('  replaces the installer and the agent with ones built on this edge.');
}

exit($foreign > 0 ? 2 : 0);
