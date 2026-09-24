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

    /** The edge release key uploads must be signed with (hex). */
    private const TRUSTED_KEY = 'edge.release_key';

    /** An upload waiting for an administrator, per kind (JSON). */
    private const HELD = 'edge.held.';

    /** Where held uploads wait. Never served. */
    private const HELD_DIR = 'storage/downloads/held';

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
            // Which release's installers this panel is already serving, so
            // an edge that is on the right version can tell "nothing to do"
            // from "the installer was never published" without rebuilding
            // and restarting to find out. An edge older than this ignores
            // the fields; one newer than the panel sees them missing and does
            // the full run, as it always did.
            'published_setup' => (string) (self::current('windows-setup')['version'] ?? ''),
            'published_agent' => (string) (self::current('windows-agent')['version'] ?? ''),
            // What is waiting for an administrator, and under which edge key
            // (1.9.7-dev.23), so an edge whose own uploads are waiting says so
            // instead of building and uploading them again every hour. The
            // fingerprint of a public key; nothing here is secret.
            'held_setup'       => (string) (self::heldRecord('windows-setup')['version'] ?? ''),
            'held_agent'       => (string) (self::heldRecord('windows-agent')['version'] ?? ''),
            'held_fingerprint' => self::fingerprint((string) (self::heldRecord('windows-agent')['key']
                ?? self::heldRecord('windows-setup')['key'] ?? '')),
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
        string $bytes,
        string $edgeKey = '',
        string $edgeSignature = ''
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

        // Whose upload is this? The shared secret only says it came from
        // something that holds the shared secret — which, since 1.9.7-dev.21
        // printed it, may be anyone. What is published has to carry the
        // edge's own signature, under the one edge key this panel was told to
        // trust by someone with root on it or by an administrator.
        $verdict = self::judge($kind, $version, $sha256, $edgeKey, $edgeSignature);

        if ($verdict['action'] === 'refuse') {
            @unlink($partial);
            Logger::warning('security', 'Edge upload refused: not signed by the trusted edge key', [
                'kind'    => $kind,
                'version' => $version,
                'sha256'  => $sha256,
                'reason'  => $verdict['reason'],
            ]);

            throw new UpdateException($verdict['message']);
        }

        if ($verdict['action'] === 'hold') {
            return self::hold($kind, $version, $sha256, $total, $partial, $edgeKey, $edgeSignature, $verdict);
        }

        return self::publish($kind, $version, $sha256, $total, $partial);
    }

    /**
     * Put a verified upload where devices and customers will get it.
     *
     * @return array<string,mixed>
     * @throws UpdateException
     */
    private static function publish(string $kind, string $version, string $sha256, int $total, string $source): array
    {
        $dir = APP_ROOT . '/' . self::DIR;
        $final = $dir . '/' . self::filename($kind, $version);
        if (!rename($source, $final)) {
            @unlink($source);

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

    // ------------------------------------------------- the edge's own key

    /**
     * What an edge signs, and what is checked here. The same string is built
     * in services/coordinator/cmd/akconnect-coordinator/releasekey.go; the
     * prefix keeps the signature from meaning anything anywhere else.
     */
    public static function artifactMessage(string $kind, string $version, string $sha256): string
    {
        return "akconnect-edge-artifact/v1\n" . $kind . "\n" . $version . "\n" . $sha256;
    }

    /**
     * What an administrator compares by eye with what the edge prints:
     * the first 80 bits of sha256 over the raw key, in groups of four.
     * `akconnect-coordinator release-key public` prints the same.
     */
    public static function fingerprint(string $publicKeyHex): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $publicKeyHex) !== 1) {
            return '';
        }

        return implode(' ', str_split(substr(hash('sha256', (string) hex2bin($publicKeyHex)), 0, 20), 4));
    }

    /** The edge release key this panel publishes under, lowercase hex, or ''. */
    public static function trustedReleaseKey(): string
    {
        $key = strtolower(trim((string) (Setting::get(self::TRUSTED_KEY, null) ?? '')));

        return preg_match('/^[0-9a-f]{64}$/', $key) === 1 ? $key : '';
    }

    /**
     * Trust an edge's release key.
     *
     * Two callers, and only two: cli/edge-trust.php, which is root on this
     * machine (upgrade-edge.sh runs it when the panel is on the edge's own
     * machine), and an administrator approving a held upload. Never anything
     * that arrives over the API: the shared secret is exactly the credential
     * this key exists to not depend on.
     *
     * @return string the key's fingerprint
     * @throws UpdateException
     */
    public static function trustReleaseKey(string $publicKeyHex, string $by): string
    {
        $key = strtolower(trim($publicKeyHex));
        if (preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
            throw new UpdateException('That is not an edge release key (64 hex characters).');
        }

        $previous = self::trustedReleaseKey();
        if ($previous === $key) {
            return self::fingerprint($key);
        }

        Setting::set(self::TRUSTED_KEY, $key);
        Setting::flushCache();

        Logger::notice('security', 'Edge release key trusted', [
            'fingerprint' => self::fingerprint($key),
            'replaces'    => $previous === '' ? null : self::fingerprint($previous),
            'by'          => $by,
        ]);

        return self::fingerprint($key);
    }

    /**
     * Publish, hold or refuse a complete, checksum-verified upload.
     *
     *   - signed by the trusted key                → published
     *   - signed, by a key this panel does not trust (none trusted yet, or
     *     another one)                              → held for an administrator
     *   - no valid signature at all                  → refused
     *
     * Every edge from 1.9.7-dev.23 signs, so an unsigned upload is either an
     * older edge — which builds only an older release than this panel, and is
     * brought up to date by the timer — or somebody with the shared secret.
     *
     * @return array{action:string,reason:string,message:string,key:string}
     */
    private static function judge(string $kind, string $version, string $sha256, string $edgeKey, string $edgeSignature): array
    {
        $key = strtolower(trim($edgeKey));
        $signed = preg_match('/^[0-9a-f]{64}$/', $key) === 1
            && preg_match('/^[0-9a-f]{128}$/', strtolower(trim($edgeSignature))) === 1
            && Crypto::verifySignature(self::artifactMessage($kind, $version, $sha256), strtolower(trim($edgeSignature)), $key);

        if (!$signed) {
            return [
                'action'  => 'refuse',
                'reason'  => $edgeSignature === '' ? 'unsigned' : 'bad_signature',
                'message' => 'Refused: this upload does not carry a valid edge signature. An edge on '
                    . '1.9.7-dev.23 or later signs what it publishes; the shared secret alone is not enough.',
                'key'     => '',
            ];
        }

        $trusted = self::trustedReleaseKey();

        if ($trusted !== '' && hash_equals($trusted, $key)) {
            return ['action' => 'publish', 'reason' => 'trusted', 'message' => '', 'key' => $key];
        }

        return [
            'action'  => 'hold',
            'reason'  => $trusted === '' ? 'no_trusted_key' : 'other_key',
            'message' => $trusted === ''
                ? 'Held: this panel trusts no edge key yet. An administrator approves it under Platform → Coordinator.'
                : 'Held: signed by edge key ' . self::fingerprint($key) . ', not the trusted '
                    . self::fingerprint($trusted) . '. An administrator decides under Platform → Coordinator.',
            'key'     => $key,
        ];
    }

    /**
     * Keep an upload that nobody has vouched for yet, out of reach.
     *
     * One per kind; a newer one replaces it. Never served, never registered as
     * an agent release, until approveHeld().
     *
     * @param array{action:string,reason:string,message:string,key:string} $verdict
     * @return array<string,mixed>
     * @throws UpdateException
     */
    private static function hold(
        string $kind,
        string $version,
        string $sha256,
        int $total,
        string $source,
        string $edgeKey,
        string $edgeSignature,
        array $verdict
    ): array {
        $dir = APP_ROOT . '/' . self::HELD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            @unlink($source);

            throw new UpdateException('Cannot create ' . self::HELD_DIR);
        }

        $previous = self::heldRecord($kind);
        $file = $kind . '-' . $sha256 . '.bin';

        if (!rename($source, $dir . '/' . $file)) {
            @unlink($source);

            throw new UpdateException('Cannot keep the held upload.');
        }
        @chmod($dir . '/' . $file, 0640);

        if ($previous !== null && $previous['file'] !== $file) {
            @unlink($dir . '/' . $previous['file']);
        }

        Setting::set(self::HELD . $kind, (string) json_encode([
            'kind'        => $kind,
            'version'     => $version,
            'sha256'      => $sha256,
            'size'        => $total,
            'file'        => $file,
            'key'         => strtolower(trim($edgeKey)),
            'signature'   => strtolower(trim($edgeSignature)),
            'reason'      => $verdict['reason'],
            'received_at' => gmdate('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES));
        Setting::flushCache();

        Logger::warning('security', 'Edge upload held for an administrator', [
            'kind'        => $kind,
            'version'     => $version,
            'sha256'      => $sha256,
            'fingerprint' => self::fingerprint(strtolower(trim($edgeKey))),
            'reason'      => $verdict['reason'],
        ]);

        return [
            'complete'    => true,
            'held'        => true,
            'reason'      => $verdict['reason'],
            'message'     => $verdict['message'],
            'fingerprint' => self::fingerprint(strtolower(trim($edgeKey))),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function heldRecord(string $kind): ?array
    {
        $raw = (string) (Setting::get(self::HELD . $kind, null) ?? '');
        if ($raw === '') {
            return null;
        }

        $record = json_decode($raw, true);
        if (!is_array($record) || !isset($record['file'], $record['sha256'], $record['version'], $record['key'])
            || basename((string) $record['file']) !== (string) $record['file']) {
            return null;
        }

        return $record;
    }

    /**
     * Uploads waiting for an administrator, for the Coordinator page and the
     * audit.
     *
     * @return array<string,array<string,mixed>> kind => record
     */
    public static function held(): array
    {
        $trusted = self::trustedReleaseKey();
        $out = [];

        foreach (self::KINDS as $kind) {
            $record = self::heldRecord($kind);
            if ($record === null) {
                continue;
            }

            $record['fingerprint'] = self::fingerprint((string) $record['key']);
            $record['other_key'] = $trusted !== '' && !hash_equals($trusted, (string) $record['key']);
            $out[$kind] = $record;
        }

        return $out;
    }

    /**
     * An administrator vouches for a held upload: its key becomes the trusted
     * edge key, and it is published.
     *
     * The fingerprint the administrator was shown is passed back and must
     * match, so a click cannot approve a different upload that replaced the
     * one on screen in the meantime. Everything is checked again: the file's
     * digest and the signature over it.
     *
     * @return array<string,mixed>
     * @throws UpdateException
     */
    public static function approveHeld(string $kind, string $shownFingerprint, string $by): array
    {
        $record = in_array($kind, self::KINDS, true) ? self::heldRecord($kind) : null;
        if ($record === null) {
            throw new UpdateException('Nothing of that kind is waiting for approval.');
        }

        $key = (string) $record['key'];
        if (self::fingerprint($key) === '' || !hash_equals(self::fingerprint($key), trim($shownFingerprint))) {
            throw new UpdateException('The upload waiting now is not the one you approved; look again.');
        }

        $path = APP_ROOT . '/' . self::HELD_DIR . '/' . $record['file'];
        $sha256 = (string) $record['sha256'];
        $version = (string) $record['version'];

        if (!is_file($path) || !hash_equals($sha256, (string) hash_file('sha256', $path))) {
            self::forgetHeld($kind, $record);

            throw new UpdateException('The held file is missing or has changed; discarded.');
        }

        if (!Crypto::verifySignature(self::artifactMessage($kind, $version, $sha256), (string) $record['signature'], $key)) {
            self::forgetHeld($kind, $record);

            throw new UpdateException('The held upload\'s signature does not verify; discarded.');
        }

        self::trustReleaseKey($key, $by);

        Setting::set(self::HELD . $kind, null);
        Setting::flushCache();

        $result = self::publish($kind, $version, $sha256, (int) filesize($path), $path);

        Logger::notice('security', 'Held edge upload approved and published', [
            'kind'        => $kind,
            'version'     => $version,
            'fingerprint' => self::fingerprint($key),
            'by'          => $by,
        ]);

        return $result;
    }

    /** Throw a held upload away. */
    public static function discardHeld(string $kind, string $by): void
    {
        $record = in_array($kind, self::KINDS, true) ? self::heldRecord($kind) : null;
        if ($record === null) {
            return;
        }

        self::forgetHeld($kind, $record);

        Logger::notice('security', 'Held edge upload discarded', [
            'kind'    => $kind,
            'version' => (string) $record['version'],
            'by'      => $by,
        ]);
    }

    /** @param array<string,mixed> $record */
    private static function forgetHeld(string $kind, array $record): void
    {
        @unlink(APP_ROOT . '/' . self::HELD_DIR . '/' . $record['file']);
        Setting::set(self::HELD . $kind, null);
        Setting::flushCache();
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
