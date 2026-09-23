<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Logger;
use App\Core\UpdateException;
use App\Models\AgentRelease;
use App\Models\Setting;
use App\Models\UpdateSetting;
use App\Updater\UpdateEnv;

/**
 * What the edge servers are running, and what they should be.
 *
 * The panel's updater updates the panel. It cannot update the coordinator and
 * the relay, because those are Go services on a different machine — and 1.9.2
 * fixed two defects that live in exactly those. So an operator who ran Update
 * Now still had a stale edge, and no way to tell from the panel.
 *
 * ## Why the panel does not simply do it
 *
 * To build and restart services on the edge, the panel would need
 * root-equivalent access to that machine, stored here. The panel is a PHP
 * application on the public internet holding the customer database; the edge
 * holds the coordinator's private key and the relay secrets. One compromise
 * would take both, and keeping those apart is R6 — the control plane is not
 * the data plane.
 *
 * So the edge pulls. `deploy/upgrade-edge.sh` is one command, and with
 * `--install-timer` it becomes a systemd timer that watches this panel and
 * upgrades itself. The trust runs the safe way: the edge already trusts the
 * repository it was built from and already authenticates to this panel. The
 * panel's job is to say what the current release is and to notice when the
 * edge is behind it.
 */
final class EdgeRelease
{
    private const PREFIX = 'edge.';

    /** Where uploaded artefacts live. Inside storage/, which no update touches. */
    private const DIR = 'storage/downloads';

    /** Artefacts this panel will accept and serve. */
    public const KINDS = ['windows-pack', 'windows-setup', 'windows-agent'];

    /**
     * What the edge should build.
     *
     * The version is this panel's own, and the commit is what the updater
     * recorded when it installed it — so an edge that builds this ref is
     * building the same code the panel is running, not merely the newest.
     *
     * @return array<string,string>
     */
    public static function target(): array
    {
        $update = UpdateSetting::current();

        return [
            'version'   => UpdateEnv::currentVersion(APP_ROOT),
            'commit'    => (string) ($update['current_commit'] ?? ''),
            'owner'     => (string) ($update['repo_owner'] ?? ''),
            'repo'      => (string) ($update['repo_name'] ?? ''),
            'branch'    => (string) ($update['branch'] ?? 'main'),
            'panel_url' => rtrim((string) Config::get('app.url', ''), '/'),
        ];
    }

    /**
     * Record what an edge server reports about itself.
     *
     * @param array<string,mixed> $report
     */
    public static function record(array $report): void
    {
        foreach (['coordinator_version', 'relay_version', 'host'] as $key) {
            $value = trim((string) ($report[$key] ?? ''));
            if ($value !== '' && preg_match('/^[\w.\-:+]{1,64}$/', $value) === 1) {
                Setting::set(self::PREFIX . $key, $value);
            }
        }

        Setting::set(self::PREFIX . 'reported_at', gmdate('Y-m-d H:i:s'));
        Setting::flushCache();
    }

    /** Just the coordinator's version, from the header it sends on every call. */
    public static function noteCoordinatorVersion(string $version): void
    {
        $version = trim($version);
        if ($version === '' || preg_match('/^[\w.\-+]{1,32}$/', $version) !== 1) {
            return;
        }

        // Always, whether or not the version moved: this is the only evidence
        // the panel has that the coordinator is alive, and it is throttled so
        // a busy coordinator does not rewrite the row on every call.
        self::noteCoordinatorSeen();

        if (Setting::get(self::PREFIX . 'coordinator_version', null) === $version) {
            // Every verify call carries this header; only a change is worth a
            // write, or a busy coordinator would rewrite the same row forever.
            return;
        }

        Setting::set(self::PREFIX . 'coordinator_version', $version);
        Setting::set(self::PREFIX . 'reported_at', gmdate('Y-m-d H:i:s'));
        Setting::flushCache();
    }

    /**
     * When the coordinator last called in.
     *
     * Defect 31: the update health check probed the coordinator with
     * fsockopen(), which is TCP, and the coordinator listens on UDP only — one
     * net.ListenUDP and no TCP anywhere. So it answered "Connection refused"
     * on every update against a perfectly healthy edge, and taught an operator
     * to skim past a warning line.
     *
     * It was also the wrong question. The panel deliberately has no way to
     * reach into the edge — that is why deploy/upgrade-edge.sh exists instead
     * of the panel restarting services. What the panel does have is the
     * coordinator calling it, signed, several times a minute. That is the
     * signal, so that is what is recorded here and checked there.
     */
    public static function noteCoordinatorSeen(): void
    {
        $last = (string) (Setting::get(self::PREFIX . 'coordinator_seen_at') ?? '');

        // A minute's resolution is plenty to answer "is it alive" and keeps
        // this to one write a minute however busy the edge is.
        if ($last !== '' && (time() - (int) strtotime($last . ' UTC')) < 60) {
            return;
        }

        Setting::set(self::PREFIX . 'coordinator_seen_at', gmdate('Y-m-d H:i:s'));
        Setting::flushCache();
    }

    /**
     * The public key the running coordinator says it holds.
     *
     * Recorded only after the request's signature has been checked, so this
     * is the coordinator speaking and not a passer-by. It is never used to
     * decide anything — agents are given the configured key, not this one —
     * because a header that could replace the configured key would be a way
     * to have every agent seal its announcements to somebody else's.
     */
    public static function noteCoordinatorPublicKey(string $publicKey): void
    {
        $publicKey = trim($publicKey);

        // 32 bytes of base64 and nothing else. A value that is not a key is
        // not worth storing to be displayed back to an administrator.
        $raw = base64_decode($publicKey, true);
        if ($publicKey === '' || $raw === false || strlen($raw) !== 32) {
            return;
        }

        if (Setting::get(self::PREFIX . 'coordinator_public_key', null) === $publicKey) {
            return;
        }

        Setting::set(self::PREFIX . 'coordinator_public_key', $publicKey);
        Setting::flushCache();
    }

    /** What the coordinator last said its key was, or '' if it never has. */
    public static function coordinatorPublicKey(): string
    {
        return (string) (Setting::get(self::PREFIX . 'coordinator_public_key') ?? '');
    }

    /** Seconds since the coordinator last called in, or null if it never has. */
    public static function coordinatorSeenSecondsAgo(): ?int
    {
        $last = (string) (Setting::get(self::PREFIX . 'coordinator_seen_at') ?? '');
        if ($last === '') {
            return null;
        }

        return max(0, time() - (int) strtotime($last . ' UTC'));
    }

    /**
     * Is the edge behind this panel?
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $target = self::target();
        $coordinator = (string) (Setting::get(self::PREFIX . 'coordinator_version', null) ?? '');
        $relay = (string) (Setting::get(self::PREFIX . 'relay_version', null) ?? '');

        $behind = [];
        foreach (['coordinator' => $coordinator, 'relay' => $relay] as $what => $seen) {
            if ($seen === '') {
                continue;
            }
            if (version_compare($seen, $target['version'], '<')) {
                $behind[$what] = $seen;
            }
        }

        return [
            'panel_version'       => $target['version'],
            'coordinator_version' => $coordinator,
            'relay_version'       => $relay,
            'reported_at'         => Setting::get(self::PREFIX . 'reported_at', null),
            'behind'              => $behind,
            'unknown'             => $coordinator === '' && $relay === '',
            // Printed verbatim on the page, because the point is that an
            // operator should not have to work it out.
            'command'             => 'sudo /opt/akconnect/src/deploy/upgrade-edge.sh',
        ];
    }

    // ------------------------------------------------------------- artefacts

    /**
     * Append one chunk of an upload, and finish it when the last one lands.
     *
     * Chunked because a 14 MB installer does not fit inside the
     * `post_max_size` a shared host is willing to allow, and because a failed
     * 14 MB upload that has to start again on a VPS in another country is a
     * bad way to spend five minutes.
     *
     * @return array<string,mixed> what the caller should report back
     * @throws UpdateException
     */
    public static function receiveChunk(
        string $kind,
        string $version,
        string $sha256,
        int $offset,
        int $total,
        string $bytes
    ): array {
        if (!in_array($kind, self::KINDS, true)) {
            throw new UpdateException('Unknown artefact kind: ' . $kind);
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new UpdateException('The artefact checksum is not a sha256 digest.');
        }
        if (preg_match('/^[\w.\-+]{1,32}$/', $version) !== 1) {
            throw new UpdateException('The artefact version is not a version.');
        }
        if ($total <= 0 || $total > 256 * 1024 * 1024) {
            throw new UpdateException('The artefact size is implausible.');
        }
        if ($offset < 0 || $offset + strlen($bytes) > $total) {
            throw new UpdateException('That chunk does not fit inside the artefact.');
        }

        $dir = APP_ROOT . '/' . self::DIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UpdateException('Cannot create ' . self::DIR);
        }

        // Named after the digest, so two uploads of different builds cannot
        // interleave into one file.
        $partial = $dir . '/.' . $sha256 . '.part';

        if ($offset === 0) {
            @unlink($partial);
        }

        $handle = fopen($partial, $offset === 0 ? 'wb' : 'cb');
        if ($handle === false) {
            throw new UpdateException('Cannot write ' . basename($partial));
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new UpdateException('Cannot seek to that offset.');
            }
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new UpdateException('Short write storing the artefact.');
            }
        } finally {
            fclose($handle);
        }

        $written = (int) filesize($partial);
        if ($written < $total) {
            return ['complete' => false, 'received' => $written, 'total' => $total];
        }

        // Verified before it is published: an artefact served to customers is
        // the one thing here that ends up executing on their machines.
        $actual = hash_file('sha256', $partial);
        if (!hash_equals($sha256, (string) $actual)) {
            @unlink($partial);

            throw new UpdateException('The uploaded artefact does not match its checksum; discarded.');
        }

        $final = $dir . '/' . self::filename($kind, $version);
        if (!rename($partial, $final)) {
            @unlink($partial);

            throw new UpdateException('Cannot put the artefact in place.');
        }
        @chmod($final, 0644);

        Setting::set(self::PREFIX . $kind . '.file', self::filename($kind, $version));
        Setting::set(self::PREFIX . $kind . '.version', $version);
        Setting::set(self::PREFIX . $kind . '.sha256', $sha256);
        Setting::set(self::PREFIX . $kind . '.size', (string) $total);
        Setting::set(self::PREFIX . $kind . '.published_at', gmdate('Y-m-d H:i:s'));
        Setting::flushCache();

        self::prune($dir, self::filename($kind, $version), $kind);

        // A bare agent binary is also a release devices can update themselves
        // to (§14) — which is the point of publishing it. Registered here
        // rather than by hand, because an agent release nobody remembered to
        // register means every fix is a manual reinstall on every PC.
        if ($kind === 'windows-agent') {
            self::registerAgentRelease($version, $sha256, $total, self::filename($kind, $version));
        }

        Logger::notice('update', 'Edge artefact published', [
            'kind'    => $kind,
            'version' => $version,
            'size'    => $total,
        ]);

        return [
            'complete' => true,
            'url'      => self::downloadUrl($kind),
            'sha256'   => $sha256,
            'size'     => $total,
        ];
    }

    /**
     * The artefact to serve, or null when none has been published.
     *
     * @return array<string,mixed>|null
     */
    public static function current(string $kind): ?array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }

        $file = (string) (Setting::get(self::PREFIX . $kind . '.file', null) ?? '');
        if ($file === '' || basename($file) !== $file) {
            return null;
        }

        $path = APP_ROOT . '/' . self::DIR . '/' . $file;
        if (!is_file($path)) {
            return null;
        }

        return [
            'path'         => $path,
            'filename'     => $file,
            'version'      => (string) (Setting::get(self::PREFIX . $kind . '.version', null) ?? ''),
            'sha256'       => (string) (Setting::get(self::PREFIX . $kind . '.sha256', null) ?? ''),
            'size'         => (int) filesize($path),
            'published_at' => Setting::get(self::PREFIX . $kind . '.published_at', null),
        ];
    }

    /** The stable, unchanging address an operator can send to a customer. */
    public static function downloadUrl(string $kind): string
    {
        return rtrim((string) Config::get('app.url', ''), '/') . '/download/' . match ($kind) {
            'windows-setup' => 'setup.exe',
            default         => 'windows-pack.zip',
        };
    }

    private static function filename(string $kind, string $version): string
    {
        return match ($kind) {
            'windows-setup' => 'akconnect-setup-' . $version . '.exe',
            'windows-agent' => 'akconnect-agent-' . $version . '.exe',
            default         => 'akconnect-windows-pack-' . $version . '.zip',
        };
    }

    /**
     * Make a published binary an update devices will take.
     *
     * The signature is over the sha256 digest, with the controller's ed25519
     * key — the same identity that signs agent configuration. That is the
     * boundary that matters: an agent replaces its own binary only for a
     * digest this panel's private key has signed, so a panel that is merely
     * reachable cannot push code, and a stolen database cannot either.
     *
     * Without a signing key configured the release is recorded unsigned, and
     * an agent will refuse it. That is the correct direction to fail: no
     * update is better than an unverified one.
     */
    private static function registerAgentRelease(
        string $version,
        string $sha256,
        int $size,
        string $filename
    ): void {
        $signature = '';
        $secret = (string) CoordinatorSettings::current()['signing_key'];

        if ($secret !== '') {
            try {
                $signature = Crypto::sign($sha256, $secret);
            } catch (\Throwable $e) {
                Logger::error('update', 'Could not sign the agent release', ['error' => $e->getMessage()]);
            }
        }

        if ($signature === '') {
            Logger::warning('update', 'Agent release published unsigned; devices will refuse it', [
                'version' => $version,
            ]);
        }

        // The channel follows the version. A build called 1.9.7-dev.7 is a
        // development build and belongs on the dev channel; publishing it as
        // stable would push it at every customer, and publishing it nowhere —
        // which is what happened — means a panel deliberately running
        // development builds offers its own devices nothing.
        // A DEVELOPMENT build, not merely a version with a hyphen in it.
        //
        // The first rule was "contains a hyphen", and the lab's own fixtures are
        // called things like 9.9.9-gate — so every drill published to the dev
        // channel and nine checks that had passed for months went red at once.
        // That was the rule being wrong, not the drills: a suffix is not a
        // statement about who a build is for.
        //
        // -dev.N is what this project names its development builds, and nothing
        // else is treated as one.
        $channel = preg_match('/-dev\\./', $version) === 1 ? 'dev' : 'stable';

        $existing = AgentRelease::findBy([
            'version'  => $version,
            'platform' => 'windows',
            'arch'     => 'amd64',
            'channel'  => $channel,
        ]);

        $attributes = [
            'version'         => $version,
            'channel'         => $channel,
            'platform'        => 'windows',
            'arch'            => 'amd64',
            'file_path'       => self::DIR . '/' . $filename,
            'file_size'       => $size,
            'sha256'          => $sha256,
            'signature'       => $signature,
            'release_notes'   => 'Published by deploy/upgrade-edge.sh from the release this panel runs.',
            'rollout_percent' => 100,
            'published_at'    => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            AgentRelease::update((int) $existing['id'], $attributes);
        } else {
            AgentRelease::create($attributes);
        }

        Logger::notice('update', 'Agent release registered', [
            'version' => $version,
            'signed'  => $signature !== '',
        ]);
    }

    /** Keep the current artefact and the one before it; delete the rest. */
    private static function prune(string $dir, string $keep, string $kind): void
    {
        $pattern = $kind === 'windows-setup' ? 'akconnect-setup-*.exe' : 'akconnect-windows-pack-*.zip';

        $files = glob($dir . '/' . $pattern) ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, 2) as $old) {
            if (basename($old) !== $keep) {
                @unlink($old);
            }
        }
    }
}
