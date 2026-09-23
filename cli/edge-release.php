#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Print the release this panel is on, as the edge is told it.
 *
 *   php cli/edge-release.php [--root=/path/to/panel]
 *
 * One line of JSON on standard output: {"version": "…", "commit": "…",
 * "branch": "…"} — the same version, commit and branch /api/v1/edge/release
 * answers upgrade-edge.sh with (EdgeRelease::target()), read here without a
 * signed request because the caller is root on the same machine.
 *
 * Why it exists: deploy/getting-started.sh builds the coordinator and the relay
 * before the panel can be asked over HTTPS, and it built them from the tip of
 * the branch it had fetched. Straight after, upgrade-edge.sh asked the panel
 * which release it was on and built that — a second build, and on a re-run
 * over an older panel a different version, restarted into minutes after the
 * first. Now the installer asks here first and builds the panel's release
 * once, and upgrade-edge.sh finds it already running.
 *
 * Nothing secret is printed, and nothing is written.
 */

// --root=<panel>: see _root.php. getting-started.sh runs this copy, from the
// edge source, against a panel that may be older than it.
require __DIR__ . '/_root.php';
require __DIR__ . '/_bootstrap.php';

use App\Models\UpdateSetting;
use App\Updater\UpdateEnv;

$update = UpdateSetting::current();

$version = method_exists(UpdateEnv::class, 'currentVersion')
    ? UpdateEnv::currentVersion(APP_ROOT)
    : trim((string) @file_get_contents(APP_ROOT . '/VERSION'));

echo json_encode([
    'version' => $version,
    'commit'  => (string) ($update['current_commit'] ?? ''),
    'branch'  => (string) ($update['branch'] ?? 'main'),
], JSON_UNESCAPED_SLASHES), "\n";

exit(0);
