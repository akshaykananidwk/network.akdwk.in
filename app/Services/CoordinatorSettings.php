<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\ValidationException;
use App\Models\Setting;

/**
 * Where the coordinator's address and keys come from.
 *
 * They used to come only from `config/config.php`, written once by the
 * installer. That had two consequences a production deployment ran into on the
 * same evening:
 *
 *   - the installer never wrote `coordinator.public_key` at all, although
 *     DeviceService reads it and agents cannot seal an announcement without
 *     it, so discovery was configured and mute;
 *   - `coordinator.host` defaulted to `127.0.0.1`, which is correct for a
 *     coordinator on the panel's own machine and wrong for every deployment
 *     where it is not — and there was no page to change it, so the file was
 *     edited by hand.
 *
 * Hand-editing a PHP file is a reasonable thing to have to do once. It is not
 * a reasonable thing to have to remember after every update, and
 * `config/config.php` is a protected path precisely so an update does not
 * touch it — which means a value fixed there is invisible to the panel that
 * should have been able to fix it.
 *
 * So these live in the settings table, which survives updates because it is
 * data, and the configuration file is the fallback for an installation that
 * has not used the page yet. The secret and the signing key are encrypted at
 * rest, the same as every other secret this product holds.
 */
final class CoordinatorSettings
{
    private const PREFIX = 'coordinator.';

    /** Values that are secrets, and are stored encrypted. */
    private const SECRETS = ['shared_secret', 'signing_key'];

    /**
     * Everything the panel and its agents need, database first.
     *
     * @return array<string,string|int>
     */
    public static function current(): array
    {
        return [
            'host'          => self::value('host', (string) Config::get('coordinator.host', '')),
            'public_host'   => self::value('public_host', (string) Config::get('coordinator.public_host', '')),
            'port'          => (int) self::value('port', (string) Config::get('coordinator.port', '8443')),
            'public_key'    => self::value('public_key', (string) Config::get('coordinator.public_key', '')),
            'shared_secret' => self::value('shared_secret', (string) Config::get('coordinator.shared_secret', '')),
            'signing_key'   => self::value('signing_key', (string) Config::get('coordinator.signing_key', '')),
            'fallback_url'  => self::value(
                'fallback_url',
                (string) Config::get('coordinator.fallback_url', self::defaultFallbackUrl())
            ),
        ];
    }

    /**
     * Where an agent goes when the network it is on passes nothing but 443.
     *
     * Derived from the panel's own address rather than asked for, because the
     * installer already points Apache at the relay on that host and a customer
     * who has to be told a second URL is a customer who will get it wrong. An
     * administrator can still override it — a separate edge host is a
     * perfectly reasonable deployment — but nobody has to.
     */
    public static function defaultFallbackUrl(): string
    {
        $base = trim((string) Config::get('app.url', ''));
        if ($base === '') {
            return '';
        }

        $host = (string) parse_url($base, PHP_URL_HOST);
        if ($host === '') {
            return '';
        }

        // wss:// and not ws://: the whole point is to look exactly like the
        // browser traffic the network already allows, and an unencrypted
        // upgrade on 443 does not.
        return 'wss://' . $host . self::FALLBACK_PATH;
    }

    /** The path Apache proxies to the relay. install-edge.sh writes the same. */
    public const FALLBACK_PATH = '/fallback';

    /** This panel's own hostname, from the address it is served on. */
    public static function panelHost(): string
    {
        return (string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
    }

    /**
     * Is this fallback address on the panel's own domain?
     *
     * A separate edge host is a legitimate arrangement, so this is a warning
     * rather than a refusal — but it is the difference between a deliberate
     * choice and a hostname that has never existed, and only one of those is
     * worth being told about.
     */
    private static function fallbackMatchesPanel(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $panel = self::panelHost();

        return $host !== '' && $panel !== '' && strcasecmp($host, $panel) === 0;
    }

    /** The address agents are told to talk to, which may differ from ours. */
    public static function agentHost(): string
    {
        $current = self::current();

        return $current['public_host'] !== '' ? (string) $current['public_host'] : (string) $current['host'];
    }

    /**
     * Save what an administrator typed.
     *
     * @param array<string,mixed> $input
     * @return array<string,string|int>
     * @throws ValidationException
     */
    public static function save(array $input): array
    {
        $errors = [];

        $host = trim((string) ($input['host'] ?? ''));
        if ($host === '') {
            $errors['host'] = 'The coordinator\'s address is required — a hostname or an IP.';
        } elseif (!self::plausibleHost($host)) {
            $errors['host'] = 'That does not look like a hostname or an IP address.';
        }

        $publicHost = trim((string) ($input['public_host'] ?? ''));
        if ($publicHost !== '' && !self::plausibleHost($publicHost)) {
            $errors['public_host'] = 'That does not look like a hostname or an IP address.';
        }

        $port = (int) ($input['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'The port must be between 1 and 65535. The coordinator listens on 8443 by default.';
        }

        // The one field with a shape worth checking. An X25519 public key is 32
        // bytes, base64, which is 44 characters ending in "=". Agents seal
        // their announcements to it and a wrong one fails silently — the
        // coordinator simply never decrypts anything — so catching it here is
        // worth more than the check costs.
        $publicKey = trim((string) ($input['public_key'] ?? ''));
        if ($publicKey === '') {
            $errors['public_key'] = 'The public key is required. Agents seal their announcements to it, '
                . 'and without it discovery is configured but silent. '
                . 'It is printed by the coordinator on startup, and by install-edge.sh.';
        } elseif (!self::isX25519PublicKey($publicKey)) {
            $errors['public_key'] = 'That is not an X25519 public key. It is 44 characters of base64 '
                . 'ending in "=", as printed by "akconnect-coordinator keygen".';
        }

        $fallbackUrl = trim((string) ($input['fallback_url'] ?? ''));
        if ($fallbackUrl !== '' && !self::plausibleFallbackUrl($fallbackUrl)) {
            // The example is this panel's own address, worked out rather than
            // written down. It used to be a literal hostname that had never
            // existed, which meant the correction offered to somebody who had
            // just mistyped the field was itself unreachable.
            $errors['fallback_url'] = 'That must be a wss:// address, for example '
                . (self::defaultFallbackUrl() ?: 'wss://your-panel-domain/fallback')
                . '. It is where agents go when the network they are on passes nothing but '
                . 'the port a browser uses.';
        }

        $sharedSecret = trim((string) ($input['shared_secret'] ?? ''));
        if ($sharedSecret !== '' && strlen($sharedSecret) < 32) {
            $errors['shared_secret'] = 'The shared secret is too short to be the one install-edge.sh generated, '
                . 'which is 64 characters.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // What changed, for the audit entry — as booleans, never the values.
        $before = self::current();

        self::put('host', $host);
        self::put('public_host', $publicHost);
        self::put('port', (string) $port);
        self::put('public_key', $publicKey);
        self::put('fallback_url', $fallbackUrl);

        // Blank means "leave it alone", so an administrator editing the host
        // does not have to paste the secret again — and so the form can show a
        // placeholder rather than the secret itself.
        if ($sharedSecret !== '') {
            self::put('shared_secret', $sharedSecret);
        }

        $signingKey = trim((string) ($input['signing_key'] ?? ''));
        if ($signingKey !== '') {
            self::put('signing_key', $signingKey);
        }

        Setting::flushCache();

        AuditService::log('coordinator.update', 'settings', null, null, [
            'host'        => $host,
            'public_host' => $publicHost,
            'port'        => $port,
            // Never the key material itself, and never a prefix of it. Worked
            // out rather than asserted: this said true on every save, and a
            // secret rotation left no trace at all — which is the one change
            // an incident review looks for first.
            'public_key_changed'    => $publicKey !== (string) ($before['public_key'] ?? ''),
            'shared_secret_changed' => $sharedSecret !== ''
                && !hash_equals((string) ($before['shared_secret'] ?? ''), $sharedSecret),
            'signing_key_changed'   => $signingKey !== ''
                && !hash_equals((string) ($before['signing_key'] ?? ''), $signingKey),
        ]);

        return self::current();
    }

    /**
     * Is this configuration usable?
     *
     * Shown on the page, because "saved" and "will work" are different things
     * and the difference is otherwise only discoverable by watching an agent
     * fail to connect.
     *
     * @return list<string> the reasons it is not, empty when it is
     */
    public static function problems(): array
    {
        $current = self::current();
        $problems = [];

        if ((string) $current['host'] === '') {
            $problems[] = 'No address is set, so the panel cannot tell agents where the coordinator is.';
        } elseif (in_array((string) $current['host'], ['127.0.0.1', 'localhost', '::1'], true)
            && (string) $current['public_host'] === '') {
            $problems[] = 'The address is ' . $current['host'] . ', which only works if the coordinator '
                . 'runs on this same machine. If it is on another server, set that server\'s address here.';
        }

        if ((string) $current['public_key'] === '') {
            $problems[] = 'No public key is set. Agents seal their announcements to it, so discovery '
                . 'will be configured and silent — peers will only connect when they can reach each '
                . 'other without help.';
        }

        // The key the coordinator says it is running, against the key this
        // panel hands to agents. When they differ nothing works and nothing
        // is logged: an agent seals its announcement to the key it is given,
        // the coordinator cannot open it, and dropping an unopenable packet
        // in silence is exactly right for a socket on the public internet.
        // The panel is the only place both halves are visible.
        $reported = EdgeRelease::coordinatorPublicKey();
        if ($reported !== '' && (string) $current['public_key'] !== ''
            && !hash_equals($reported, (string) $current['public_key'])) {
            $problems[] = 'The public key set here is not the one the coordinator is running. '
                . 'Agents seal their announcements to the key this panel gives them, so none of '
                . 'them can be read and nothing will connect — with nothing in any log to say so. '
                . 'The running key is ' . $reported . '.';
        }

        if ((string) $current['shared_secret'] === '') {
            $problems[] = 'No shared secret is set, so the coordinator cannot authenticate to this panel.';
        }

        if ((string) $current['fallback_url'] === '') {
            $problems[] = 'No HTTPS fallback address is set. Devices on networks that pass nothing but '
                . 'port 443 — hotel wifi, guest networks, offices that drop UDP replies — will not '
                . 'connect at all.';
        } elseif (!self::fallbackMatchesPanel((string) $current['fallback_url'])) {
            // A wrong host here is silent and total: the address is published
            // to every agent, every agent that needs it dials a name that does
            // not resolve, and nothing anywhere says why. It happened — the
            // shipped configuration named a domain that had never existed, so
            // the derived default was a hostname nobody could reach.
            $problems[] = 'The HTTPS fallback address is ' . $current['fallback_url'] . ', which is '
                . 'not on this panel\'s own domain (' . self::panelHost() . '). That is right only '
                . 'if a separate edge host serves it. If it is not deliberate, devices on networks '
                . 'that block UDP will dial a name that does not answer.';
        }

        return $problems;
    }

    private static function value(string $key, string $fallback): string
    {
        $stored = Setting::get(self::PREFIX . $key, null, null);

        return ($stored === null || $stored === '') ? $fallback : $stored;
    }

    private static function put(string $key, string $value): void
    {
        Setting::set(self::PREFIX . $key, $value, null, in_array($key, self::SECRETS, true));
    }

    /**
     * Is this a fallback address an agent could actually use?
     *
     * Deliberately narrow. A ws:// address would work and must not be offered:
     * it is cleartext on the one port every network inspects, and it would
     * make the fallback the least private path in the product rather than
     * the most ordinary-looking one.
     */
    private static function plausibleFallbackUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'wss') {
            return false;
        }

        return ($parts['host'] ?? '') !== '' && self::plausibleHost((string) $parts['host']);
    }

    private static function plausibleHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $host) === 1
            && strlen($host) <= 253;
    }

    private static function isX25519PublicKey(string $candidate): bool
    {
        $raw = base64_decode($candidate, true);

        return $raw !== false && strlen($raw) === 32;
    }
}
