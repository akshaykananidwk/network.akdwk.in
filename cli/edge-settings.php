#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Point this panel at its edge, and record where it was installed from.
 *
 *   printf '%s' "$json" | php cli/edge-settings.php [--root=/path/to/panel]
 *
 * One JSON object on standard input. Every key is optional, and an absent key
 * is left exactly as it is:
 *
 *   {
 *     "coordinator": {"host": "…", "port": 8443, "public_key": "…",
 *                     "shared_secret": "…", "fallback_url": "wss://…/fallback"},
 *     "source":      {"repo_owner": "…", "repo_name": "…",
 *                     "branch": "release/1.9.7", "commit": "<sha>"},
 *     "channel":     "stable" | "beta" | "edge"
 *   }
 *
 * "source_if_unset" takes the same fields as "source" and fills only what was
 * never set: an empty repository, the seeded branch "main" on a panel with no
 * commit recorded, an empty commit. It is what a re-run sends. The panel
 * updates itself from release archives and never moves its .git, so the
 * commit a re-run can read from the tree is the one it was installed at —
 * writing it back over the panel's own record would rewind the panel and have
 * it offer again an update it already applied.
 *
 * Why this is a script and not a heredoc
 * --------------------------------------
 * deploy/getting-started.sh used to write this as PHP into a mktemp file and
 * run it as the web user. mktemp makes files only their creator can read, the
 * creator was root, and the web user could not open it: "Could not open input
 * file". The script then said "could not write the settings automatically" and
 * carried on to report a successful install — with a panel that had no
 * coordinator key, the installer's own random shared secret, no repository and
 * the seeded branch "main". Every agent that enrolled would have been refused
 * by the coordinator, and upgrade-edge.sh could never build the panel's
 * release, so no installer was ever published. It was the same fault as the
 * answers file one release earlier, one step further down.
 *
 * The values come on standard input rather than as arguments, because an
 * argument is readable by every user on the machine for as long as the
 * process runs (ps shows it), and one of them is the coordinator's shared
 * secret.
 *
 * Idempotent by construction: each group is compared with what is stored and
 * written only if it differs, so a re-run of the installer changes nothing and
 * records nothing in the audit log unless something really was different.
 *
 * Exits non-zero, naming the field, if anything could not be saved. A caller
 * that treats that as a warning is making a mistake the installer made once.
 */

// --root=<panel>: see _root.php. getting-started.sh runs this copy, from the
// edge source, against a panel that may be older than it.
require __DIR__ . '/_root.php';
require __DIR__ . '/_bootstrap.php';

use App\Core\ValidationException;
use App\Models\UpdateSetting;
use App\Services\CoordinatorSettings;

$raw = stream_get_contents(STDIN);
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

if (!is_array($input)) {
    fwrite(STDERR, "Expected one JSON object on standard input"
        . (is_string($raw) && trim($raw) !== '' ? ': ' . json_last_error_msg() : ', and got nothing')
        . ".\n");
    exit(1);
}

$failed = false;

// ---------------------------------------------------------------- coordinator

if (isset($input['coordinator']) && is_array($input['coordinator'])) {
    $wanted = $input['coordinator'];
    $current = CoordinatorSettings::current();

    $same = true;
    foreach (['host', 'public_key', 'shared_secret', 'fallback_url'] as $key) {
        if (array_key_exists($key, $wanted) && (string) $wanted[$key] !== (string) ($current[$key] ?? '')) {
            $same = false;
        }
    }
    if (array_key_exists('port', $wanted) && (int) $wanted['port'] !== (int) ($current['port'] ?? 0)) {
        $same = false;
    }

    if ($same) {
        cli_ok('the coordinator was already ' . (string) $current['host'] . ':' . (int) $current['port'] . ', with this key');
    } else {
        try {
            CoordinatorSettings::save([
                'host'          => (string) ($wanted['host'] ?? $current['host']),
                'public_host'   => (string) ($wanted['public_host'] ?? $wanted['host'] ?? $current['public_host']),
                'port'          => (int) ($wanted['port'] ?? $current['port']),
                'public_key'    => (string) ($wanted['public_key'] ?? $current['public_key']),
                'shared_secret' => (string) ($wanted['shared_secret'] ?? ''),
                'fallback_url'  => (string) ($wanted['fallback_url'] ?? $current['fallback_url']),
            ]);
            cli_ok('the coordinator is ' . (string) ($wanted['host'] ?? $current['host'])
                . ':' . (int) ($wanted['port'] ?? $current['port']) . ', key and secret saved');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $message) {
                cli_fail('coordinator ' . $field . ': ' . (is_array($message) ? implode(' ', $message) : $message));
            }
            $failed = true;
        }
    }
}

// --------------------------------------------------------------------- source

if (isset($input['source']) && is_array($input['source'])) {
    $wanted = $input['source'];
    $current = UpdateSetting::current();

    $changes = [];
    foreach (['repo_owner', 'repo_name', 'branch'] as $key) {
        if (isset($wanted[$key]) && (string) $wanted[$key] !== '' && (string) $wanted[$key] !== (string) ($current[$key] ?? '')) {
            $changes[$key] = (string) $wanted[$key];
        }
    }

    // The commit the tree really is at. upgrade-edge.sh builds exactly this;
    // without it, it builds the tip of the branch, which is only the panel's
    // version until somebody pushes.
    if (isset($wanted['commit']) && preg_match('/^[0-9a-f]{7,40}$/', (string) $wanted['commit']) === 1
        && (string) $wanted['commit'] !== (string) ($current['current_commit'] ?? '')) {
        $changes['current_commit'] = (string) $wanted['commit'];
    }

    if ($changes === []) {
        cli_ok('the source was already ' . (string) ($current['repo_owner'] ?? '') . '/'
            . (string) ($current['repo_name'] ?? '') . ' on ' . (string) ($current['branch'] ?? ''));
    } else {
        UpdateSetting::save($changes);
        $after = UpdateSetting::current();
        cli_ok('the source is ' . (string) ($after['repo_owner'] ?? '') . '/' . (string) ($after['repo_name'] ?? '')
            . ' on ' . (string) ($after['branch'] ?? '')
            . (isset($changes['current_commit']) ? ' at ' . substr($changes['current_commit'], 0, 12) : ''));
    }
}

if (isset($input['source_if_unset']) && is_array($input['source_if_unset'])) {
    $wanted = $input['source_if_unset'];
    $current = UpdateSetting::current();
    $neverSet = (string) ($current['repo_owner'] ?? '') === ''
        && (string) ($current['current_commit'] ?? '') === '';

    $changes = [];
    foreach (['repo_owner', 'repo_name'] as $key) {
        if ((string) ($current[$key] ?? '') === '' && (string) ($wanted[$key] ?? '') !== '') {
            $changes[$key] = (string) $wanted[$key];
        }
    }
    // The branch only when it is still the seed and nothing else was ever
    // recorded — "main" on a configured panel may be exactly what somebody chose.
    if ($neverSet && (string) ($current['branch'] ?? 'main') === 'main' && (string) ($wanted['branch'] ?? '') !== '') {
        $changes['branch'] = (string) $wanted['branch'];
    }
    if ((string) ($current['current_commit'] ?? '') === ''
        && preg_match('/^[0-9a-f]{7,40}$/', (string) ($wanted['commit'] ?? '')) === 1) {
        $changes['current_commit'] = (string) $wanted['commit'];
    }

    if ($changes === []) {
        cli_ok('the source was already recorded (' . (string) ($current['branch'] ?? '')
            . ((string) ($current['current_commit'] ?? '') !== '' ? ' at ' . substr((string) $current['current_commit'], 0, 12) : '')
            . ') — left as the panel has it');
    } else {
        UpdateSetting::save($changes);
        cli_ok('the source was never recorded; it is now ' . implode(', ', array_map(
            static fn (string $k, string $v): string => $k . ' ' . ($k === 'current_commit' ? substr($v, 0, 12) : $v),
            array_keys($changes),
            $changes
        )));
    }
}

// -------------------------------------------------------------------- channel

if (isset($input['channel'])) {
    $channel = (string) $input['channel'];

    if (!in_array($channel, ['stable', 'beta', 'edge'], true)) {
        cli_fail('channel must be stable, beta or edge — not "' . $channel . '"');
        $failed = true;
    } elseif ((string) (UpdateSetting::current()['channel'] ?? '') === $channel) {
        cli_ok('the release channel was already ' . $channel);
    } else {
        UpdateSetting::save(['channel' => $channel]);
        cli_ok('the release channel is ' . $channel);
    }
}

exit($failed ? 1 : 0);
