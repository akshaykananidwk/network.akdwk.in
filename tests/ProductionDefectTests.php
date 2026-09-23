<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Crypto;
use App\Models\Setting;
use App\Core\Request;
use App\Services\CoordinatorSettings;
use App\Services\EdgeRelease;
use App\Updater\PathGuard;

/**
 * The ten defects a real deployment found that the lab did not.
 *
 * Every check here fails against 1.9.0. They are grouped by the defect number
 * from that report, and the comments say what the symptom was, because a test
 * whose name is "htaccess has CGIPassAuth" teaches nobody why it matters at
 * three in the morning two years from now.
 *
 * What is NOT here: the parts that need a web server, Apache, or Windows.
 * Those live in the Apache target (services/lab/webtarget/) and the Go suites.
 */
final class ProductionDefectTests
{
    public static function run(): void
    {
        self::webrootHardening();
        self::phpDirectivesGuarded();
        self::authorizationHeaderFallbacks();
        self::argon2Parameters();
        self::coordinatorValidation();
        self::uploadsControlIsShipped();
        self::deployDocument();
        self::edgeUpgradeScript();
        self::httpsFallback();
        self::unattendedInstallAcrossUsers();
        self::installerGitAsOwner();
        self::cliActsAsTreeOwner();
        self::maintenanceBypassLink();
        self::secretsStayOffScreens();
        self::installerRootNeverActsInsideTheWebTree();
    }

    // ------------------------------------------------------- the 1.9.6 path

    /**
     * The fallback for networks that carry nothing but the port a browser uses.
     *
     * A customer laptop on an office Wi-Fi announced itself to the coordinator
     * every few seconds for an afternoon and was never answered: the network
     * let UDP out and dropped the replies. Nothing in the product could work
     * around it, because asking for help also needed UDP. The only answers
     * were "change your firewall", which no customer will do, or a path on
     * TCP 443.
     *
     * The wiring that makes that path exist is checked here, because every
     * piece of it is silent when it is wrong: an agent with no address to go
     * to simply never connects, and says nothing about why.
     */
    private static function httpsFallback(): void
    {
        TestCase::group('1.9.6 — the HTTPS fallback is configured and published');

        // Put back whatever this database held, because these rows outrank
        // config/config.php by design and this suite is not the only thing
        // that reads them. It used to leave a fixture coordinator behind — a
        // public key of 32 asterisks — and the networking lab, which points
        // the panel at a real coordinator through config/config.local.php,
        // then handed every agent that fixture key. Announcements sealed to a
        // key nobody holds are dropped in silence, so thirty-two scenarios
        // failed with nothing in any log to say why.
        $before = self::storedCoordinatorSettings();

        try {
            self::httpsFallbackChecks();
        } finally {
            foreach ($before as $key => $value) {
                Setting::set('coordinator.' . $key, $value, null);
            }
            Setting::flushCache();
        }
    }

    /** The coordinator rows this suite overwrites, exactly as they are stored. */
    private static function storedCoordinatorSettings(): array
    {
        $keys = ['host', 'public_host', 'port', 'public_key', 'fallback_url'];

        $stored = [];
        foreach ($keys as $key) {
            $stored[$key] = (string) (Setting::get('coordinator.' . $key, null, '') ?? '');
        }

        return $stored;
    }

    private static function httpsFallbackChecks(): void
    {

        // Derived from the panel's own address, because a customer who has to
        // be told a second URL is a customer who will get it wrong.
        $derived = CoordinatorSettings::defaultFallbackUrl();
        TestCase::assert(
            $derived === '' || str_starts_with($derived, 'wss://'),
            'the default fallback address is a wss:// URL'
        );
        if ($derived !== '') {
            TestCase::assertContains(
                CoordinatorSettings::FALLBACK_PATH,
                $derived,
                'the default fallback address uses the path Apache proxies'
            );
        }

        // ws:// would work and must never be offered: it is cleartext on the
        // one port every network inspects, which would make the fallback the
        // least private path in the product instead of the most ordinary.
        $valid = [
            'host'       => 'coordinator.example.test',
            'port'       => 8443,
            'public_key' => base64_encode(str_repeat("\x2a", 32)),
        ];

        $refused = [
            'an unencrypted ws:// fallback is refused' => 'ws://net.example.test/fallback',
            'an https:// fallback is refused'          => 'https://net.example.test/fallback',
            'a fallback with no host is refused'       => 'wss:///fallback',
            'a fallback that is not a URL is refused'  => 'net.example.test/fallback',
        ];

        foreach ($refused as $name => $url) {
            TestCase::assertThrows(
                \App\Core\ValidationException::class,
                static fn () => CoordinatorSettings::save(array_merge($valid, ['fallback_url' => $url])),
                $name
            );
        }

        // The address is derived from the panel's own, and it has to BE the
        // panel's own. The configuration this shipped with named a domain
        // that had never existed, so the derived default was a hostname no
        // device could reach — and a wrong host here is silent and total:
        // every agent that needs the fallback dials a name that does not
        // resolve, and nothing anywhere says why.
        $panelHost = CoordinatorSettings::panelHost();
        TestCase::assert($panelHost !== '', 'the panel knows its own hostname');
        TestCase::assertSame(
            'wss://' . $panelHost . CoordinatorSettings::FALLBACK_PATH,
            CoordinatorSettings::defaultFallbackUrl(),
            'the fallback address defaults to this panel\'s own host and the proxied path'
        );

        // And a host that is not the panel's is reported. A separate edge host
        // is legitimate, so this is a warning rather than a refusal — but
        // "deliberate" and "a hostname that has never existed" must not look
        // the same from the settings page.
        CoordinatorSettings::save([
            'host'         => 'coordinator.example.test',
            'port'         => 8443,
            'public_key'   => base64_encode(str_repeat("\x2a", 32)),
            'fallback_url' => 'wss://somewhere-else.example/fallback',
        ]);

        $problems = implode(' ', CoordinatorSettings::problems());
        TestCase::assertContains(
            'not on this panel',
            $problems,
            'a fallback address on another domain is reported'
        );

        // Put it back, so the rest of the suite sees a panel that is right.
        CoordinatorSettings::save([
            'host'         => 'coordinator.example.test',
            'port'         => 8443,
            'public_key'   => base64_encode(str_repeat("\x2a", 32)),
            'fallback_url' => CoordinatorSettings::defaultFallbackUrl(),
        ]);
        TestCase::assertNotContains(
            'not on this panel',
            implode(' ', CoordinatorSettings::problems()),
            'and the derived default is not reported as one'
        );

        // Apache is the half of this that lives outside PHP, and both forms of
        // the configuration have to be in the release for install-edge.sh to
        // find one. See services/lab/edge-script-gate.sh for what is in them.
        foreach (
            ['akconnect-fallback.conf', 'akconnect-fallback-upgrade.conf'] as $conf
        ) {
            $path = APP_ROOT . '/deploy/apache/' . $conf;

            TestCase::assert(is_file($path), 'deploy/apache/' . $conf . ' ships with the release');

            // No <VirtualHost> of its own. The file is included INSIDE the
            // panel's virtual host, and a server with thirty other sites on
            // it is the reason: a virtual host here, or a ProxyPass at server
            // level, would publish /fallback on all of them.
            $body = (string) @file_get_contents($path);
            TestCase::assertNotContains(
                '<VirtualHost',
                $body,
                $conf . ' declares no virtual host of its own'
            );
            TestCase::assertNotContains(
                '<IfModule',
                preg_replace('/^\s*#.*$/m', '', $body) ?? '',
                $conf . ' does not hide a missing module behind <IfModule>'
            );
        }

        // The scripts that install it must not put it anywhere an Apache
        // reads by itself, and must not add a server-level include.
        $lib = (string) @file_get_contents(APP_ROOT . '/deploy/lib-edge-apache.sh');

        // Directives only. The file explains in a comment why a2enconf is not
        // used — enabling a configuration that way is server-wide — and a
        // check that read the comments would fail on the sentence saying so.
        $code = (string) preg_replace('/^\s*#.*$/m', '', $lib);
        TestCase::assertNotContains(
            'a2enconf',
            $code,
            'nothing enables the configuration server-wide'
        );
        TestCase::assertContains(
            '/etc/akconnect/apache/',
            $lib,
            'the configuration lives outside every directory Apache includes automatically'
        );

        // And the panel has to be able to store what a device reports about
        // it, or a device on the HTTPS path shows as an ordinary relay and
        // nobody can tell the two situations apart.
        $migrations = glob(APP_ROOT . '/database/migrations/*_device_relay_https_state.php') ?: [];
        TestCase::assert($migrations !== [], 'the relay_https connection state has a migration');

        $view = (string) @file_get_contents(APP_ROOT . '/app/Views/partials/connection.php');
        TestCase::assertContains('relay_https', $view, 'the device list can show the HTTPS path');
        TestCase::assertContains('relay-https', $view, 'and names it the way the agent reports it');

        self::coordinatorKeyMismatchIsReported();
    }

    /**
     * The one fault in this product with no symptom at all.
     *
     * An agent seals its announcement to the public key the panel hands it.
     * If that is not the key the running coordinator holds, the coordinator
     * cannot open a single one — and dropping an unopenable packet without a
     * word is exactly what a socket on the public internet must do, so there
     * is nothing in any log on either side. Every device is configured,
     * connected to the panel, reporting healthy, and mute.
     *
     * It happened here: the PHP suite left a fixture coordinator in the
     * settings table, those rows outrank the configuration file by design, and
     * the next lab run handed thirty-two scenarios' worth of agents a public
     * key of 32 asterisks. Nothing said so.
     *
     * The panel is the only place both halves are visible, so the coordinator
     * now reports which key it is running on the authenticated call it already
     * makes, and the settings page says when they differ.
     */
    private static function coordinatorKeyMismatchIsReported(): void
    {
        $running = base64_encode(str_repeat("\x11", 32));
        $configured = base64_encode(str_repeat("\x22", 32));

        $storedKey = (string) (Setting::get('coordinator.public_key', null, '') ?? '');
        $storedReported = EdgeRelease::coordinatorPublicKey();

        try {
            Setting::set('coordinator.public_key', $configured, null);
            Setting::set('edge.coordinator_public_key', $running, null);
            Setting::flushCache();

            TestCase::assertContains(
                'not the one the coordinator is running',
                implode(' ', CoordinatorSettings::problems()),
                'a coordinator running a different key than the panel publishes is reported'
            );

            // And the same key is not a problem, or the warning would be noise
            // on every healthy installation.
            Setting::set('edge.coordinator_public_key', $configured, null);
            Setting::flushCache();

            TestCase::assertNotContains(
                'not the one the coordinator is running',
                implode(' ', CoordinatorSettings::problems()),
                'and a coordinator running the published key is not'
            );

            // Only the coordinator writes it, and only 32 bytes of base64.
            EdgeRelease::noteCoordinatorPublicKey('not a key');
            TestCase::assertSame(
                $configured,
                EdgeRelease::coordinatorPublicKey(),
                'a reported key that is not 32 bytes of base64 is ignored'
            );
        } finally {
            Setting::set('coordinator.public_key', $storedKey, null);
            Setting::set('edge.coordinator_public_key', $storedReported, null);
            Setting::flushCache();
        }
    }

    // ---------------------------------------------------------------- #1, #9

    private static function webrootHardening(): void
    {
        TestCase::group('Defect 1 — the repository root is the webroot, and it is closed');

        $htaccess = (string) @file_get_contents(APP_ROOT . '/.htaccess');
        TestCase::assert($htaccess !== '', 'the root .htaccess exists');

        // A production install found deploy/ and docs/ readable, along with
        // .git and every .md and .sh in the tree.
        foreach (['app', 'config', 'database', 'storage', 'services', 'deploy', 'docs'] as $directory) {
            TestCase::assert(
                preg_match('/RewriteRule \^\([^)]*\b' . preg_quote($directory, '/') . '\b[^)]*\)/', $htaccess) === 1,
                $directory . '/ is blocked'
            );
        }

        TestCase::assertContains('.git', $htaccess, '.git is blocked');
        foreach (['.*\.md', '.*\.sh'] as $pattern) {
            TestCase::assertContains($pattern, $htaccess, $pattern . ' is blocked');
        }

        // #9: without this, every agent request arrives unauthenticated.
        TestCase::assertContains('CGIPassAuth On', $htaccess, 'CGIPassAuth is set for FastCGI');
        TestCase::assertContains('HTTP_AUTHORIZATION', $htaccess, 'the SetEnvIf fallback is present for older Apache');

        // The uploads rule. A .php file uploaded into uploads/ executed.
        $uploads = (string) @file_get_contents(APP_ROOT . '/uploads/.htaccess');
        TestCase::assert($uploads !== '', 'uploads/.htaccess exists');
        TestCase::assertContains('SetHandler none', $uploads, 'uploads/ hands nothing to the PHP handler');
        TestCase::assertContains('Require all denied', $uploads, 'uploads/ denies direct requests');
        TestCase::assertContains('-ExecCGI', $uploads, 'uploads/ has ExecCGI off');
    }

    // -------------------------------------------------------------------- #2

    private static function phpDirectivesGuarded(): void
    {
        TestCase::group('Defect 2 — php_flag under PHP-FPM is a 500 on every page');

        $lines = explode("\n", (string) @file_get_contents(APP_ROOT . '/.htaccess'));
        $depth = 0;
        $unguarded = [];

        foreach ($lines as $number => $line) {
            $trimmed = trim($line);
            if (preg_match('/^<IfModule\s+(mod_php[0-9]*\.c)\s*>/i', $trimmed) === 1) {
                $depth++;
                continue;
            }
            if ($depth > 0 && preg_match('#^</IfModule>#i', $trimmed) === 1) {
                $depth--;
                continue;
            }
            if ($depth === 0 && preg_match('/^php_(flag|value)\s/i', $trimmed) === 1) {
                $unguarded[] = 'line ' . ($number + 1) . ': ' . $trimmed;
            }
        }

        TestCase::assert(
            $unguarded === [],
            'every php_flag/php_value sits inside an <IfModule mod_php*.c> block',
            $unguarded === [] ? 'none unguarded' : implode('; ', $unguarded)
        );
    }

    // -------------------------------------------------------------------- #9

    private static function authorizationHeaderFallbacks(): void
    {
        TestCase::group('Defect 9 — Apache strips Authorization; read it wherever it lands');

        $token = 'ak_' . str_repeat('a', 40);

        $server = $_SERVER;

        try {
            foreach ([
                'HTTP_AUTHORIZATION',
                'REDIRECT_HTTP_AUTHORIZATION',
                'REDIRECT_REDIRECT_HTTP_AUTHORIZATION',
            ] as $variable) {
                $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/config'];
                $_SERVER[$variable] = 'Bearer ' . $token;

                TestCase::assertSame(
                    $token,
                    Request::capture()->bearerToken(),
                    'the token is read from ' . $variable
                );
            }

            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/config'];
            TestCase::assertSame(
                null,
                Request::capture()->bearerToken(),
                'no header at all means no token, not an empty string'
            );
        } finally {
            // Leave Request::current() describing the real environment again.
            $_SERVER = $server;
            Request::capture();
        }
    }

    // -------------------------------------------------------------------- #3

    private static function argon2Parameters(): void
    {
        TestCase::group('Defect 3 — Argon2 with the sodium provider refuses threads > 1');

        $hash = Crypto::hashPassword('Installer!Pass2026');

        TestCase::assert($hash !== '', 'a password hashes at all');
        TestCase::assert(Crypto::verifyPassword('Installer!Pass2026', $hash), 'the hash verifies');
        TestCase::assert(!Crypto::verifyPassword('wrong', $hash), 'a wrong password does not');
        TestCase::assert(
            !Crypto::passwordNeedsRehash($hash),
            'a hash this build just produced does not immediately need rehashing'
        );

        if (str_starts_with($hash, '$argon2')) {
            TestCase::assertContains(
                'p=1',
                $hash,
                'Argon2 parallelism is 1, which is the only value libsodium accepts'
            );
        } else {
            TestCase::assert(
                str_starts_with($hash, '$2y$'),
                'without Argon2 the fallback is bcrypt, not a fatal',
                substr($hash, 0, 4)
            );
        }
    }

    // -------------------------------------------------------------------- #4

    private static function coordinatorValidation(): void
    {
        TestCase::group('Defect 4 — the coordinator settings page validates what it is given');

        $valid = [
            'host'       => 'coordinator.example.test',
            'port'       => 8443,
            'public_key' => base64_encode(str_repeat("\x2a", 32)),
        ];

        $cases = [
            'a blank host is refused'          => ['host' => ''],
            'a host with a space is refused'   => ['host' => 'not a host'],
            'port 0 is refused'                => ['port' => 0],
            'port 70000 is refused'            => ['port' => 70000],
            'a blank public key is refused'    => ['public_key' => ''],
            'a 31-byte public key is refused'  => ['public_key' => base64_encode(str_repeat("\x2a", 31))],
            'a non-base64 public key is refused' => ['public_key' => 'not a key at all'],
            'a short shared secret is refused' => ['shared_secret' => 'tooshort'],
        ];

        foreach ($cases as $name => $override) {
            TestCase::assertThrows(
                \App\Core\ValidationException::class,
                static fn () => CoordinatorSettings::save(array_merge($valid, $override)),
                $name
            );
        }

        // The installer must write the key. Omitting it made discovery
        // configured and silent: agents seal announcements to it.
        $installer = (string) @file_get_contents(APP_ROOT . '/install/Installer.php');
        TestCase::assertContains(
            "'public_key'    => \$answers['coordinator_public_key']",
            $installer,
            'the installer writes coordinator.public_key'
        );
        TestCase::assertNotContains(
            "'host'          => \$answers['coordinator_host'] ?? '127.0.0.1'",
            $installer,
            'the installer no longer defaults the coordinator to 127.0.0.1'
        );
    }

    // -------------------------------------------------------------------- #1

    private static function uploadsControlIsShipped(): void
    {
        TestCase::group('Defect 1 — the updater can install uploads/.htaccess on an existing site');

        $guard = new PathGuard(APP_ROOT, ['uploads/', 'storage/', 'config/config.php', '.env']);

        TestCase::assert($guard->isProtected('uploads/.htaccess'), 'uploads/.htaccess is still a protected path');
        TestCase::assert(!$guard->isWriteBlocked('uploads/.htaccess'), 'but the updater may write it');
        TestCase::assert($guard->isWriteBlocked('uploads/customer-logo.png'), 'customer content is not writable');
        TestCase::assert($guard->isWriteBlocked('config/config.php'), 'the configuration file is not writable');
        TestCase::assert($guard->isWriteBlocked('storage/logs/app.log'), 'storage is not writable');

        // The carve-out is a write, never a delete: a release cannot remove a
        // security control by listing it in the manifest.
        TestCase::assert(
            $guard->isProtected('uploads/.htaccess'),
            'a manifest asking to delete it is still refused'
        );

        TestCase::assertSame(
            ['uploads/.htaccess'],
            PathGuard::SHIPPED_CONTROLS,
            'the carve-out is exactly one file wide'
        );
    }

    // ----------------------------------------------- the edge script (1.9.2)

    /**
     * The deployment script's own honesty, as far as a PHP test can see it.
     *
     * The script shipped with a failed preflight printing "All steps passed",
     * which is the false-pass class every gate in this project exists to
     * prevent — printed by my own deployment script, on the first thing the
     * operator ran. The behaviour is exercised properly by
     * services/lab/edge-script-gate.sh, which runs the real script eleven
     * ways; these are the invariants that can be checked by reading it, so
     * that a rewrite cannot quietly drop them.
     */
    private static function edgeUpgradeScript(): void
    {
        TestCase::group('The edge upgrade script cannot report a false pass');

        $path = APP_ROOT . '/deploy/upgrade-edge.sh';
        $script = (string) @file_get_contents($path);

        TestCase::assert($script !== '', 'deploy/upgrade-edge.sh exists');
        TestCase::assert(is_executable($path), 'and is executable');

        // The three independent reasons a false pass is impossible.
        TestCase::assertContains(
            'trap on_exit EXIT',
            $script,
            'an EXIT trap has the last word on the verdict'
        );

        // A copy up to revision 2 hands over by writing this script into
        // /tmp and running it there, so anything it loads from beside itself
        // is loaded from /tmp — where any local user can put a file for root
        // to run. Nothing may be loaded relative to $0.
        // Every file it loads comes from the checkout, by $SRC_DIR — never by
        // a path worked out from $0, in whatever spelling: a two-line HERE=
        // form, ${0%/*}, dirname -- "$0" all passed the first version of this.
        preg_match_all('/^\s*(?:\.|source)\s+(\S+)/m', $script, $sourced);
        $outside = array_filter($sourced[1], static fn (string $path): bool => !str_starts_with($path, '"$SRC_DIR/deploy/'));
        TestCase::assert(
            $sourced[1] !== [] && $outside === []
                && preg_match('/\$\{0[%#]|dirname\s+(--\s+)?"?\$0|BASH_SOURCE/', $script) !== 1,
            'upgrade-edge.sh loads nothing from beside itself — it may be running from /tmp',
            $outside === [] ? '' : 'loads: ' . implode(', ', $outside)
        );
        TestCase::assert(
            str_contains($script, 'mktemp -d /tmp/akconnect-upgrade-edge.'),
            'and the copy it hands over to gets a directory of its own'
        );
        // Run by bash, not executed: a /tmp mounted noexec refused the exec,
        // and a failed exec ends bash with no summary at all.
        TestCase::assert(
            preg_match('/exec bash "\$THEIRS"/', $script) === 1 && preg_match('/exec "\$THEIRS"/', $script) !== 1,
            'and hands over through bash, so a noexec /tmp cannot stop it'
        );
        TestCase::assert(
            preg_match('/^export GOFLAGS=.*-buildvcs=false/m', $script) === 1,
            'every go build it starts, an older release\'s pack builder included, skips VCS stamping'
        );
        TestCase::assertContains(
            'REACHED_END',
            $script,
            'and success is claimed only from the last line'
        );
        // Three places, each a deliberate end: the --check report, the
        // "already current" exit (1.9.7-dev.21 — a run that finds nothing to
        // do records that as a PASS and stops, instead of rebuilding and
        // restarting both services every hour), and the last line. Every one
        // must be immediately followed by an exit or be the end of the file;
        // one that is not would let the script carry on after claiming it
        // had finished.
        $ends = [];
        foreach (explode("\n", $script) as $n => $line) {
            if (trim($line) === 'REACHED_END=1') {
                $ends[] = $n;
            }
        }
        $lines = explode("\n", $script);
        $deliberate = array_filter($ends, static function (int $n) use ($lines): bool {
            $rest = array_values(array_filter(
                array_slice($lines, $n + 1),
                static fn (string $l): bool => trim($l) !== '' && !str_starts_with(trim($l), '#')
            ));

            // `exit 0` and nothing else. `fi` was accepted too, and let
            // through exactly what this exists to stop: the "already
            // current" block with its exit deleted falls through to a
            // rebuild with REACHED_END already set, and still counted as
            // three deliberate ends.
            return $rest === [] || trim($rest[0]) === 'exit 0';
        });
        TestCase::assert(
            count($ends) === 3 && count($deliberate) === 3
                && str_contains($script, 'pass "already current"'),
            'which is set in exactly three places, each a deliberate end: --check, already current, and the last line',
            count($ends) === 3 && count($deliberate) === 3
                ? '' : count($ends) . ' occurrence(s), ' . count($deliberate) . ' followed by an exit or the end'
        );

        // SCRIPT_REVISION is how a copy on an edge decides to hand over to a
        // newer one. It sat at 2 through eight changes that mattered, so the
        // hand-over did nothing for any of them — and the gate, which forces
        // its "old" copy below whatever HEAD says, could not notice. The
        // script's fingerprint is recorded beside the revision it was
        // reviewed at; any edit makes this fail until somebody decides,
        // deliberately, whether a running edge needs the new copy (raise the
        // revision) or not (record the new fingerprint at the same one).
        $revision = preg_match('/^SCRIPT_REVISION=(\d+)$/m', $script, $m) === 1 ? (int) $m[1] : -1;
        $fingerprint = hash('sha256', (string) preg_replace('/^SCRIPT_REVISION=\d+\n/m', '', $script));
        $recorded = trim((string) @file_get_contents(APP_ROOT . '/deploy/upgrade-edge.revision'));
        $expected = $revision . ' ' . $fingerprint;

        TestCase::assert(
            $recorded === $expected,
            'upgrade-edge.sh has not changed since its SCRIPT_REVISION was last decided',
            $recorded === $expected ? '' : 'it has. If a running edge needs this copy, raise SCRIPT_REVISION first. Then record it:'
                . "\n  echo '" . $expected . "' > deploy/upgrade-edge.revision"
        );
        TestCase::assertContains(
            'STOPPED_BECAUSE',
            $script,
            'and die() records why it stopped, so the reason is in the table'
        );

        // The summary must treat an unfinished run as a failure whatever the
        // results list says.
        TestCase::assertContains(
            '$REACHED_END" -ne 1',
            $script,
            'the summary fails an unfinished run'
        );

        // Go where the official tarball puts it.
        foreach (['/usr/local/go/bin', '/usr/lib/go'] as $location) {
            TestCase::assertContains(
                $location,
                $script,
                'it looks for Go in ' . $location
            );
        }
        TestCase::assertContains(
            'Environment=PATH=/usr/local/go/bin',
            $script,
            'and the systemd unit it installs has that on its PATH too'
        );

        // A toolchain too old to build with is refused up front.
        TestCase::assertContains('1.24', $script, 'and it refuses a Go older than the services need');

        // The gate that runs it for real.
        $gate = APP_ROOT . '/services/lab/edge-script-gate.sh';
        TestCase::assert(is_file($gate) && is_executable($gate), 'the script has a gate of its own');
        TestCase::assertContains(
            'edge-script-gate.sh',
            (string) @file_get_contents(APP_ROOT . '/services/lab/release.sh'),
            'and release.sh runs it'
        );
    }

    // ------------------------------------------------------------- #1, #2, #4

    private static function deployDocument(): void
    {
        TestCase::group('Defect 1 — DEPLOY.md describes the layout this product actually has');

        $deploy = (string) @file_get_contents(APP_ROOT . '/DEPLOY.md');
        TestCase::assert($deploy !== '', 'DEPLOY.md exists');

        TestCase::assertNotContains(
            'document root** to `/www/wwwroot/network.akdwk.in/public`',
            $deploy,
            'it no longer tells an operator to point Apache at a public/ that does not exist'
        );
        TestCase::assert(
            !is_dir(APP_ROOT . '/public'),
            'and there is indeed no public/ directory to point at'
        );
        // The cron lines themselves, not the prose around them: the document
        // now names /usr/bin/php8.1 deliberately, to say it is not there.
        $cronLines = array_values(array_filter(
            explode("\n", $deploy),
            static fn (string $line): bool => str_contains($line, 'worker.php') && str_contains($line, '* * *')
        ));

        TestCase::assert($cronLines !== [], 'DEPLOY.md still shows a worker cron line');
        foreach ($cronLines as $line) {
            TestCase::assert(
                !str_contains($line, '/usr/bin/php'),
                'the cron line does not use a PHP binary aaPanel does not install',
                trim($line)
            );
            TestCase::assertContains('/www/server/php/', $line, 'the cron line uses aaPanel\'s PHP');
        }
        TestCase::assertContains(
            'crontab -u www',
            $deploy,
            'the worker cron runs as www, so storage files are not left owned by root'
        );
        TestCase::assertContains(
            '/admin/coordinator',
            $deploy,
            'Stage 3a names the page that now exists'
        );
    }

    // --------------------------------------------- the 1.9.7-dev.20 path

    /**
     * The unattended installer, run by one user against a file another owns.
     *
     * The first field run of deploy/getting-started.sh on a clean server
     * stopped here. The script runs as root, the panel installer runs as the
     * web user, and the answers — which carry the database password — went
     * between them in a file root created with mktemp at mode 0600. The web
     * user could stat it and could not open it.
     *
     * What it then said was:
     *
     *     PHP Warning: file_get_contents(/tmp/tmp.nY7B7cjZ7T): Failed to
     *     open stream: Permission denied
     *     The answers file is not valid JSON.
     *
     * Both lines are true. Together they are a lie about which fault this is:
     * an unreadable file reads as false, false casts to the empty string, and
     * the empty string is not valid JSON. The operator was sent to look at
     * the JSON, which was perfect.
     *
     * Two things are checked, because there are two fixes and each stands on
     * its own. The installer must name a permission problem as one. And the
     * script must not create the situation at all — the answers now go over
     * standard input, so the secret is never written down, there is nothing
     * to chown and nothing left behind.
     */
    private static function unattendedInstallAcrossUsers(): void
    {
        TestCase::group('Defect — the unattended installer, across two users');

        // The script's half, checked statically because it holds whatever the
        // last edit left and a static check cannot be skipped by an
        // environment that will not drop privileges.
        $script = (string) @file_get_contents(APP_ROOT . '/deploy/getting-started.sh');

        // The class, not the instance. The first version of this check looked
        // for exactly the answers file, and the same defect was sitting forty
        // lines further down — a root-made mktemp file run as the web user to
        // write the panel's coordinator settings — and passed it. Any path
        // this script creates as root and then names on a `sudo -u` line is
        // the defect, whatever it is for.
        // Code only: a comment that quotes the old form is not a temporary file.
        $scriptCode = implode("\n", array_filter(
            explode("\n", $script),
            static fn (string $line): bool => !preg_match('/^\s*#/', $line)
        ));
        preg_match_all('/(\w+)="\$\((?:keep_tmp\s+"\$\()?mktemp\b/', $scriptCode, $made1);
        preg_match_all('/\bmake_tmp\s+(\w+)/', $scriptCode, $made2);
        $made = array_unique(array_merge($made1[1], $made2[1]));
        $handedOver = [];
        foreach (explode("\n", $scriptCode) as $line) {
            // Directly, or through the helpers every other-user command
            // now goes through.
            if (preg_match('/\bsudo\s+(-\w+\s+)*-u\b|\bas_user\b|\bas_owner\b/', $line) !== 1) {
                continue;
            }
            foreach ($made as $name) {
                if (preg_match('/\$\{?' . preg_quote($name, '/') . '\b/', $line) === 1) {
                    $handedOver[] = $name;
                }
            }
        }
        TestCase::assert(
            $handedOver === [],
            'nothing root creates with mktemp is handed to a process running as another user',
            $handedOver === [] ? '' : 'handed over: $' . implode(', $', array_unique($handedOver))
                . ' — the other user cannot open a file mktemp made for root'
        );

        TestCase::assert(
            preg_match('/install\.php[^\n|]*--answers=(?!-)/', $script) !== 1,
            'the installer script never hands a file path to the panel installer',
            preg_match('/install\.php[^\n|]*--answers=(?!-)/', $script) === 1
                ? 'getting-started.sh passes --answers=<path> to a process running as another user, '
                    . 'which is the defect: use --answers=- and pipe the JSON in'
                : ''
        );

        TestCase::assert(
            str_contains($script, '--answers=-'),
            'it hands the answers over on standard input instead'
        );

        TestCase::assert(
            preg_match('/\btrap\s+\w+\s+EXIT\b/', $script) === 1,
            'and removes its temporary files however it exits'
        );

        // The installer's half, run for real: one user writes a file only it
        // can read, another runs the installer against it.
        $dropper = self::privilegeDropper();
        if ($dropper === null) {
            TestCase::skip(
                'running the installer as another user',
                'this needs to be root and to have setpriv or sudo, which is true on a server '
                . 'and not in every development container. The static checks above still ran.'
            );

            return;
        }

        $tree = self::cleanTreeCopy();
        if ($tree === null) {
            TestCase::skip('running the installer as another user', 'could not stage a clean copy of the tree');

            return;
        }

        $answers = $tree . '/answers.json';
        file_put_contents($answers, json_encode([
            'app_url' => 'https://example.test', 'db_name' => 'x', 'db_user' => 'y',
            'admin_name' => 'z', 'admin_email' => 'a@b.test', 'admin_password' => 'not-a-real-password',
        ]));
        chmod($answers, 0600);

        $unreadable = self::runAs($dropper, [PHP_BINARY, $tree . '/cli/install.php', '--answers=' . $answers]);

        // "Cannot read", not merely the words "permission denied": PHP's own
        // warning carries those two words, so an assertion that looked only
        // for them passed against the exact build that failed in the field.
        // This looks for the installer having said it, deliberately, itself.
        $saidIt = stripos($unreadable, 'cannot read') !== false
            && stripos($unreadable, 'permission denied') !== false;

        TestCase::assert(
            $saidIt,
            'a file the installer may not open is reported as a permission problem',
            $saidIt ? '' : 'it said: ' . self::firstLine($unreadable)
        );

        TestCase::assert(
            stripos($unreadable, 'not valid JSON') === false,
            'and not as a JSON problem, which is what sent the operator to the wrong place',
            stripos($unreadable, 'not valid JSON') === false ? '' : 'it said: ' . self::firstLine($unreadable)
        );

        // And the route the script actually takes now gets past the answers
        // entirely — as far as the requirement checks, which is where a real
        // install carries on from.
        $viaStdin = self::runAs(
            $dropper,
            [PHP_BINARY, $tree . '/cli/install.php', '--answers=-'],
            (string) file_get_contents($answers)
        );

        // A usage message counts as a failure here. An installer that does not
        // understand --answers=- prints one, and prints neither of the two
        // phrases below — so testing only for their absence passed against a
        // build that could not read standard input at all.
        $readStdin = stripos($viaStdin, 'permission denied') === false
            && stripos($viaStdin, 'not valid JSON') === false
            && stripos($viaStdin, 'Usage:') === false;

        TestCase::assert(
            $readStdin,
            'the same answers on standard input are read without a permission problem',
            $readStdin ? '' : 'it said: ' . self::firstLine($viaStdin)
        );

        TestCase::assert(
            stripos($viaStdin, 'Checking requirements') !== false,
            'and the install proceeds past them'
        );

        self::removeTree($tree);
    }

    /** The first non-empty line, which is the part worth putting in a failure. */
    private static function firstLine(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (trim($line) !== '' && !str_starts_with(trim($line), 'PHP Warning')) {
                return trim($line);
            }
        }

        return trim($output) === '' ? '(no output)' : trim(explode("\n", $output)[0]);
    }

    /**
     * A command prefix that runs something as a different, unprivileged user.
     *
     * Null when this process cannot do that, which is most development
     * containers — the caller skips rather than pretending.
     *
     * @return list<string>|null
     */
    private static function privilegeDropper(): ?array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return null;
        }

        if (self::which('setpriv') !== null) {
            return [self::which('setpriv'), '--reuid=65534', '--regid=65534', '--clear-groups'];
        }

        if (self::which('sudo') !== null) {
            return [self::which('sudo'), '-n', '-u', 'nobody'];
        }

        return null;
    }

    private static function which(string $tool): ?string
    {
        foreach (['/usr/bin/', '/bin/', '/usr/sbin/', '/sbin/'] as $directory) {
            if (is_executable($directory . $tool)) {
                return $directory . $tool;
            }
        }

        return null;
    }

    /**
     * Run a command, optionally with something on its standard input, and
     * return everything it wrote.
     *
     * @param list<string> $dropper
     * @param list<string> $command
     */
    private static function runAs(array $dropper, array $command, string $stdin = ''): string
    {
        $full = implode(' ', array_map('escapeshellarg', array_merge($dropper, $command)));

        $pipes = [];
        $process = proc_open(
            $full,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return '';
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out . $err;
    }

    /**
     * A copy of the tree with no config/config.php and no install.lock.
     *
     * Without both removed the installer refuses before it ever looks at the
     * answers, and the check would pass on a panel that still had the bug.
     * World-readable, because the point is a file the other user CANNOT read
     * and everything around it being readable is what makes that specific.
     */
    private static function cleanTreeCopy(): ?string
    {
        $root = sys_get_temp_dir() . '/akconnect-install-' . bin2hex(random_bytes(6));
        if (!@mkdir($root, 0755, true)) {
            return null;
        }

        foreach (['app', 'cli', 'config', 'database', 'install', 'lang'] as $directory) {
            if (!self::copyTree(APP_ROOT . '/' . $directory, $root . '/' . $directory)) {
                self::removeTree($root);

                return null;
            }
        }

        @unlink($root . '/config/config.php');
        @unlink($root . '/install/install.lock');
        @chmod($root, 0755);

        return $root;
    }

    private static function copyTree(string $from, string $to): bool
    {
        if (!is_dir($from)) {
            return false;
        }
        if (!is_dir($to) && !@mkdir($to, 0755, true)) {
            return false;
        }

        /** @var iterable<\SplFileInfo> $entries */
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($entries as $entry) {
            $target = $to . '/' . substr($entry->getPathname(), strlen($from) + 1);
            if ($entry->isDir()) {
                @mkdir($target, 0755, true);

                continue;
            }
            @copy($entry->getPathname(), $target);
            @chmod($target, 0644);
        }

        return true;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        /** @var iterable<\SplFileInfo> $entries */
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }

    /**
     * Every git command the installer runs, runs as the repository's owner.
     *
     * The second field run of deploy/getting-started.sh stopped at
     *
     *     fatal: detected dubious ownership in repository at '/var/www/nb.akdwk.in'
     *
     * The first run had died after handing the panel tree to the web user; the
     * re-run did git on it as root, and git refused — correctly. The operator
     * got past it with a GLOBAL safe.directory, which tells git to stop
     * checking for every repository on the machine.
     *
     * services/lab/install-gate.sh proves the fix end to end, but it needs
     * root and namespaces. This is the part that can be checked anywhere, in
     * the fast suite: that the script has no path to git that bypasses the
     * owner rule, never writes a safe.directory, and no longer runs the
     * blanket chmod that made the database password world-readable on every
     * re-run.
     */
    private static function installerGitAsOwner(): void
    {
        TestCase::group('Defect — the installer runs git as the repository owner');

        $script = (string) @file_get_contents(APP_ROOT . '/deploy/getting-started.sh');

        // Code only: comments explain the defect by quoting it.
        $code = implode("\n", array_filter(
            explode("\n", $script),
            static fn (string $line): bool => !preg_match('/^\s*#/', $line)
        ));

        $bare = [];
        foreach (explode("\n", $code) as $number => $line) {
            // A git command that is not inside as_owner / git_in. The only
            // direct `git` allowed is the one as_owner itself runs.
            // Messages quote git in words ("is not a git checkout"), and a
            // message is not a command — but a message can RUN git, inside a
            // $(…): `ok "edge source ($(git -C … rev-parse …))"` was exactly
            // that, and an earlier version of this check skipped every line
            // that began with ok. So quoted text is dropped only where it
            // holds no command substitution, and what is left is searched.
            $line = (string) preg_replace_callback(
                '/"(?:[^"\\\\]|\\\\.)*"/',
                static fn (array $q): string => str_contains($q[0], '$(') ? $q[0] : '""',
                $line
            );

            if (preg_match('/(^|[;&|(\s])git\s+(-C|-c|clone|fetch|checkout|remote|rev-parse|rev-list|pull|reset|status|archive|show|cat-file|merge-base|ls-files|ls-remote|symbolic-ref|log|config|init|push|add|commit|stash|worktree|update-index|read-tree|write-tree)\b/', $line)
                && !str_contains($line, 'as_owner') && !str_contains($line, 'git_in')) {
                $bare[] = trim($line);
            }
        }

        TestCase::assert(
            $bare === [],
            'no git command in the installer bypasses the owner rule',
            $bare === [] ? '' : 'run as root, whoever owns the tree: ' . implode(' | ', array_slice($bare, 0, 3))
        );

        // The trap removes what was registered with it — and registration
        // inside a command substitution happens in a subshell, where the
        // trap's list is a copy. The first version did exactly that for every
        // temporary path, and the trap removed none of them.
        TestCase::assert(
            preg_match('/\$\(\s*(keep_tmp|make_tmp)\b/', $code) !== 1,
            'temporary paths are registered in the installer\'s own shell, not a subshell\'s copy'
        );

        TestCase::assert(
            !preg_match('/safe\.directory/', $code),
            'it never writes a safe.directory — the workaround, not the fix'
        );

        TestCase::assert(
            str_contains($code, 'as_user "$owner" "$@"'),
            'a repository that is not root\'s is operated on as its owner'
        );

        // Through one helper, which sets what sudo's own session would not:
        // pam_umask gives the web user 0002 on Ubuntu, and the checkout came
        // out group-writable. And proxies by name only — env VAR=value put a
        // proxy password on sudo's command line, which auth.log records.
        TestCase::assert(
            str_contains($code, "umask 022 && exec \"\$@\"") && str_contains($code, '--preserve-env='),
            'every command run as another user gets umask 022, and proxy settings by name'
        );
        TestCase::assert(
            preg_match('/\b(sudo|env)\b[^\n]*\b\w*_proxy=|\benv\s+\$\{?carry|\w+_proxy=\$\{!/i', $code) !== 1,
            'no proxy value is ever put on a command line'
        );

        // Every command run as the web user gets umask 022, including the
        // wrappers this script writes out: sudo's PAM session gives that user
        // 0002. Judged on logical lines, with quoted text set aside.
        $joined = preg_replace('/\\\\\n\s*/', ' ', $code);
        $bare = [];
        foreach (explode("\n", (string) $joined) as $line) {
            $plain = (string) preg_replace(["/'[^']*'/", '/"(?:[^"\\\\]|\\\\.)*"/'], ["''", '""'], $line);
            if (preg_match('/\bsudo\b[^;|&\n]*\s-u\b/', $plain) === 1 && !str_contains($line, 'umask 022')) {
                $bare[] = trim($line);
            }
        }
        TestCase::assert($bare === [], 'nothing runs as the web user without umask 022',
            implode(' | ', array_slice($bare, 0, 3)));

        // A trap on INT that only cleaned up returned to the script, which
        // carried on to "AK Connect is installed". The LAST trap naming each
        // signal is the one in force, and it has to exit.
        $lastTrap = [];
        foreach (explode("\n", $code) as $line) {
            if (preg_match("/^\s*trap\s+('[^']*'|\S+)\s+(.+)$/", $line, $t) === 1) {
                foreach (preg_split('/\s+/', trim($t[2])) as $signal) {
                    $lastTrap[$signal] = $t[1];
                }
            }
        }
        $stops = true;
        foreach (['INT', 'TERM', 'HUP'] as $signal) {
            $stops = $stops && isset($lastTrap[$signal]) && str_contains($lastTrap[$signal], 'exit');
        }
        TestCase::assert($stops, 'an interrupted run stops; it does not clean up and carry on',
            $stops ? '' : 'traps in force: ' . json_encode($lastTrap));

        // The setup link for an account nobody has taken up, from this
        // release's copy: an older panel's would ignore the option.
        // Asked for on every run — the helper decides whether the account is
        // unclaimed — not only on the run that created it, which was the defect.
        TestCase::assert(
            preg_match('/^SETUP_LINK="\$\(as_user [^\n]*"\$SRC_DIR\/cli\/setup-link\.php"/m', $code) === 1
                && preg_match('/\[ "\$INSTALLED_NOW" -eq 1 \] \|\| SETUP_LINK_ARGS\+=\(--if-unclaimed\)/', $code) === 1,
            'a re-run issues the setup link an unfinished first run never printed'
        );

        TestCase::assert(
            !preg_match('/find\s+"\$PANEL_DIR"[^\n]*-exec\s+chmod/', $code),
            'no blanket chmod over the panel tree, which made config/.env world-readable on every re-run'
        );

        // A recursive chmod over the tree may only take bits away: go-w
        // repairs what sudo's pam_umask left group-writable; anything that
        // adds or sets a mode is the defect above again.
        preg_match_all('/chmod\s+-R\s+(\S+)\s+"\$PANEL_DIR"/', $code, $recursive);
        $adding = array_filter($recursive[1], static fn (string $mode): bool => preg_match('/^[ugoa]*-[rwxX]+$/', $mode) !== 1);
        TestCase::assert(
            $recursive[1] !== [] && $adding === [],
            'a recursive chmod over the panel only ever removes permissions (and one removes group/other write)',
            $adding === [] ? '' : 'adds or sets: ' . implode(', ', $adding)
        );

        TestCase::assert(
            preg_match('/chmod 640 "\$PANEL_DIR\/\$secret"/', $code) === 1
                && str_contains($code, 'config/.env'),
            'and the installer\'s secrets are put back to 0640 on every run'
        );

        TestCase::assert(
            is_file(APP_ROOT . '/services/lab/install-gate.sh')
                && str_contains((string) @file_get_contents(APP_ROOT . '/services/lab/release.sh'), 'install-gate.sh'),
            'the end-to-end install gate ships and the release gate runs it'
        );

        // A re-run leaves an installed panel at its own version, so a helper
        // that is new in this release is not in that panel's tree. The first
        // wiring step ran "$PANEL_DIR/cli/edge-settings.php" and died with
        // "Could not open input file" over a panel an earlier release had
        // installed — the one panel a re-run exists to repair.
        TestCase::assert(
            str_contains($code, '"$SRC_DIR/cli/edge-settings.php" --root="$PANEL_DIR"')
                && !str_contains($code, '"$PANEL_DIR/cli/edge-settings.php"'),
            'the installer writes the panel\'s settings with its own release\'s helper, aimed at the panel'
        );

        $bootstrap = (string) @file_get_contents(APP_ROOT . '/cli/_bootstrap.php');
        TestCase::assert(
            str_contains($bootstrap, "if (!defined('APP_ROOT'))"),
            'and the CLI bootstrap honours the panel root that helper names'
        );

        $out = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/cli/edge-settings.php')
            . ' --root=' . escapeshellarg(sys_get_temp_dir() . '/no-panel-here-' . getmypid()) . ' </dev/null 2>&1', $out, $status);
        TestCase::assert(
            $status === 1 && str_contains(implode("\n", $out), 'Not a panel'),
            'a --root that is not a panel is refused by name, before anything is read or written'
        );
    }

    /**
     * A panel CLI run as root acts as the owner of the panel's files.
     *
     * The panel's own updater never runs git, so it cannot hit "dubious
     * ownership" — but it has the same fault in file form. It replaces files
     * in place as the web user, and every documented CLI command
     * (`php cli/update.php --apply`, `php cli/maintenance.php on`,
     * `php cli/backup.php`, a cron line for cli/worker.php) ran as whoever typed
     * it: root, on a server. Root left root-owned files in the tree, and the
     * next update from the panel failed on one of them and rolled back over a
     * file it had never changed. A root maintenance flag was worse: the panel
     * then reported "maintenance is ON" while its own write had failed.
     */
    private static function cliActsAsTreeOwner(): void
    {
        TestCase::group('Defect — a panel CLI run as root acts as the owner of the files');

        $owner = (string) @file_get_contents(APP_ROOT . '/cli/_owner.php');
        TestCase::assert(
            str_contains($owner, 'posix_setuid') && str_contains($owner, 'fileowner($root)'),
            'cli/_owner.php holds the rule: become the owner of the tree, or refuse'
        );

        // Both entry points, and before anything that could write: the
        // bootstrap before the application loads, the installer before it
        // writes config.php — which, as root, it used to write as root:root
        // 0640, leaving a panel that answered 500 to every request.
        foreach (['cli/_bootstrap.php' => "require APP_ROOT . '/app/bootstrap.php'",
                  'cli/install.php' => '$installer = new Installer'] as $file => $firstWrite) {
            $code = (string) @file_get_contents(APP_ROOT . '/' . $file);
            $call = strpos($code, 'akconnect_become_tree_owner(APP_ROOT)');
            $write = strpos($code, $firstWrite);
            TestCase::assert(
                $call !== false && $write !== false && $call < $write,
                $file . ' becomes the owner before it loads or writes anything'
            );
        }

        $dropper = self::privilegeDropper();
        if ($dropper === null || !is_file(APP_ROOT . '/config/config.php')) {
            TestCase::skip(
                'running a CLI as root against a tree the web user owns',
                'this needs root, a second user and an installed config/config.php to copy'
            );

            return;
        }

        $tree = self::cleanTreeCopy();
        if ($tree === null) {
            TestCase::skip('running a CLI as root against a tree the web user owns', 'could not stage a copy');

            return;
        }

        @copy(APP_ROOT . '/config/config.php', $tree . '/config/config.php');
        @mkdir($tree . '/storage/logs', 0755, true);
        @mkdir($tree . '/storage/tmp', 0755, true);
        self::chownTree($tree, 'nobody');

        $out = self::runAs([], [PHP_BINARY, $tree . '/cli/maintenance.php', 'on', 'owner test']);
        $flag = $tree . '/storage/maintenance.flag';
        $owner = is_file($flag) && function_exists('posix_getpwuid')
            ? (string) (posix_getpwuid((int) fileowner($flag))['name'] ?? '')
            : '';

        TestCase::assert(
            $owner === 'nobody',
            'what it writes belongs to the owner of the tree, not to root',
            $owner === 'nobody' ? '' : 'storage/maintenance.flag is owned by ' . ($owner ?: 'nothing') . '; it said: ' . self::firstLine($out)
        );

        $foreign = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ((posix_getpwuid((int) fileowner($entry->getPathname()))['name'] ?? '') !== 'nobody') {
                $foreign[] = substr($entry->getPathname(), strlen($tree) + 1);
            }
        }
        TestCase::assert(
            $foreign === [],
            'and nothing anywhere in the tree is left root\'s',
            $foreign === [] ? '' : 'root-owned: ' . implode(', ', array_slice($foreign, 0, 4))
        );

        TestCase::assert(
            str_contains($out, 'running as nobody'),
            'and it says so, rather than doing it silently'
        );

        self::runAs([], [PHP_BINARY, $tree . '/cli/maintenance.php', 'off']);
        self::removeTree($tree);
    }

    /**
     * The bypass link `cli/maintenance.php on` prints really lets you in.
     *
     * It printed `?maintenance_bypass=<token>` from the first version, and
     * nothing in the application read that parameter: the one way in the
     * command offered answered 503 like everything else.
     */
    private static function maintenanceBypassLink(): void
    {
        TestCase::group('Defect — the maintenance bypass link is honoured');

        if (!is_file(APP_ROOT . '/config/config.php')) {
            TestCase::skip('the maintenance bypass link', 'needs an installed config/config.php to copy');

            return;
        }

        // A copy, so the running panel is never put into maintenance by a
        // test — and never left in it by one that fails half-way.
        $tree = self::cleanTreeCopy();
        if ($tree === null) {
            TestCase::skip('the maintenance bypass link', 'could not stage a copy');

            return;
        }
        @copy(APP_ROOT . '/config/config.php', $tree . '/config/config.php');
        @mkdir($tree . '/storage/logs', 0755, true);
        @mkdir($tree . '/storage/tmp', 0755, true);

        $on = self::runAs([], [PHP_BINARY, $tree . '/cli/maintenance.php', 'on', 'bypass test']);
        $token = preg_match('/maintenance_bypass=([A-Za-z0-9_\-]+)/', $on, $m) === 1 ? $m[1] : '';

        TestCase::assert($token !== '', 'cli/maintenance.php prints a bypass link', $token !== '' ? '' : self::firstLine($on));

        $probe = <<<'PHP'
<?php
$root = $argv[1];
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/dashboard';
$_GET = $argv[2] === '' ? [] : ['maintenance_bypass' => $argv[2]];
$_COOKIE = [];
$_SESSION = [];
define('APP_ROOT', $root);
require $root . '/app/Core/Autoloader.php';
$a = new App\Core\Autoloader();
$a->addNamespace('App', $root . '/app');
$a->register();
// What a browser keeps from an earlier maintenance window.
if (($argv[3] ?? '') === 'cookie') {
    $_COOKIE[App\Updater\MaintenanceMode::cookieName()] = 'a-token-from-the-last-window';
} elseif (($argv[3] ?? '') === 'session') {
    $_SESSION['maintenance_bypass'] = 'a-token-from-the-last-window';
} elseif (($argv[3] ?? '') === 'next-page') {
    // The page after the link: this window's token in the session, the last
    // window's in the cookie, and no link in the URL.
    $_COOKIE[App\Updater\MaintenanceMode::cookieName()] = 'a-token-from-the-last-window';
    $_SESSION['maintenance_bypass'] = (string) ($argv[4] ?? '');
}
require $root . '/app/Core/helpers.php';
App\Core\Config::load($root . '/config/config.php');
App\Core\View::configure($root . '/app/Views');
App\Core\Lang::configure($root . '/lang', 'en');
$result = App\Middleware\MaintenanceMiddleware::handle(App\Core\Request::capture());
echo $result === null ? 'LET-THROUGH' : 'BLOCKED';
PHP;
        $script = $tree . '/probe.php';
        file_put_contents($script, $probe);

        $without = self::runAs([], [PHP_BINARY, $script, $tree, '']);
        $with = self::runAs([], [PHP_BINARY, $script, $tree, $token]);
        $wrong = self::runAs([], [PHP_BINARY, $script, $tree, 'not-the-token']);

        TestCase::assert(str_contains($without, 'BLOCKED'), 'without the link a visitor gets the maintenance page', str_contains($without, 'BLOCKED') ? '' : self::firstLine($without));
        TestCase::assert(str_contains($with, 'LET-THROUGH'), 'with the printed link they are let through', str_contains($with, 'LET-THROUGH') ? '' : self::firstLine($with));
        TestCase::assert(str_contains($wrong, 'BLOCKED'), 'and a wrong token is not', str_contains($wrong, 'BLOCKED') ? '' : self::firstLine($wrong));

        // The second drill of the day. The browser still holds the first
        // window's token — in its session, or in the cookie a panel update
        // sets — and a stored value used to stop the link being read at all.
        $staleCookie = self::runAs([], [PHP_BINARY, $script, $tree, $token, 'cookie']);
        $staleSession = self::runAs([], [PHP_BINARY, $script, $tree, $token, 'session']);
        TestCase::assert(
            str_contains($staleCookie, 'LET-THROUGH') && str_contains($staleSession, 'LET-THROUGH'),
            'and a browser still holding the last window\'s token is let through by this one\'s link',
            'stale cookie: ' . self::firstLine($staleCookie) . '; stale session: ' . self::firstLine($staleSession)
        );

        // And on the next page, with no link: the stale cookie is still sent
        // first, and used to hide the good token the link put in the session.
        $nextPage = self::runAs([], [PHP_BINARY, $script, $tree, '', 'next-page', $token]);
        TestCase::assert(
            str_contains($nextPage, 'LET-THROUGH'),
            'and so is every page after it, though the stale cookie is still sent',
            self::firstLine($nextPage)
        );

        self::removeTree($tree);
    }

    /**
     * No secret on a screen, in a log, or on a command line.
     *
     * The installer printed the coordinator's shared secret — install-edge.sh's
     * "put these values into the panel" — and then set it itself; it was pasted
     * into a chat from there. And several scripts put secrets where ps shows
     * them to every user on the machine: jq --arg, openssl -macopt, mysql -e.
     */
    private static function secretsStayOffScreens(): void
    {
        TestCase::group('Defect — no secret is printed or put on a command line');

        $read = static fn (string $f): string => (string) @file_get_contents(APP_ROOT . '/' . $f);
        $installEdge = $read('deploy/install-edge.sh');
        $addRelay = $read('deploy/add-relay.sh');
        $installer = $read('deploy/getting-started.sh');
        $upgrade = $read('deploy/upgrade-edge.sh');
        $rotate = $read('deploy/rotate-secret.sh');

        TestCase::assert(
            !str_contains($installEdge, '$(cat "$ETC_DIR/coordinator.secret.for-panel"')
                && str_contains($installEdge, '--panel-configured-by-caller')
                && str_contains($installer, '--panel-configured-by-caller'),
            'install-edge.sh never prints the shared secret, and prints no manual steps when the installer wires the panel'
        );

        $done = preg_match('/cat <<DONE(.*?)\nDONE/s', $addRelay, $m) === 1 ? $m[1] : $addRelay;
        TestCase::assert(!str_contains($done, '$RELAY_SECRET'), 'add-relay.sh does not print the relay secret');

        TestCase::assert(
            preg_match('/--arg\s+secret/', $installer) !== 1 && str_contains($installer, 'env.AKCONNECT_COORD_SECRET'),
            'the installer hands the secret to jq in its environment, not its arguments'
        );
        TestCase::assert(
            preg_match('/mysql -e "[^"]*IDENTIFIED BY/s', $installer) !== 1,
            'the database password goes to mysql on standard input'
        );
        TestCase::assert(
            preg_match('/^[^#\n]*-macopt/m', $upgrade) !== 1 && str_contains($upgrade, 'AKCONNECT_SIGN_KEY="$SECRET" python3'),
            'upgrade-edge.sh signs with the key in python\'s environment, not on openssl\'s command line'
        );

        TestCase::assert(
            $rotate !== '' && str_contains($installer, 'deploy/rotate-secret.sh" /usr/local/bin/akconnect-rotate-secret'),
            'akconnect-rotate-secret ships and the installer puts it in place'
        );
        $exposed = preg_match('/(echo|--arg|-macopt|mysql -e)[^\n]*\$(NEW|OLD)_(SECRET|RELAY)/', $rotate) === 1
            || preg_match('/ENVIRON\["AKCONNECT_FROM"\]/', $rotate) !== 1;
        TestCase::assert(!$exposed, 'and it moves secrets by standard input and environment only');
        TestCase::assert(
            str_contains($rotate, 'panel_takes "$NEW_SECRET"')
                && strpos($rotate, 'panel_takes "$NEW_SECRET"') < strpos($rotate, 'replace_line "$COORD_ENV" AKCONNECT_COORDINATOR_SECRET')
                && strpos($rotate, 'replace_line "$COORD_ENV" AKCONNECT_COORDINATOR_SECRET') < strpos($rotate, 'systemctl restart akconnect-coordinator'),
            'the panel is changed first, then the files, then the coordinator restarted'
        );
        TestCase::assert(
            str_contains($rotate, 'FOR_PANEL') && str_contains($rotate, 'coordinator.secret.for-panel'),
            'and coordinator.secret.for-panel is rewritten too, so a re-run of the installer cannot put the old value back'
        );

        // | grep -q after something that writes in pieces fails at random under
        // pipefail (SIGPIPE, status 141): an extension, a module or a listener
        // that is there was reported missing.
        $racy = [];
        foreach (glob(APP_ROOT . '/deploy/*.sh') ?: [] as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                if (preg_match('/^\s*#/', $line) !== 1
                    && preg_match('/(-m|-M|\bss -l\w*|ufw status|mysql [^|]*)\s*2>\/dev\/null\s*\|\s*grep\s+-\w*q/', $line) === 1) {
                    $racy[] = basename($file) . ':' . ($n + 1);
                }
            }
        }
        TestCase::assert($racy === [], 'no | grep -q after a command that writes its list in pieces',
            implode(', ', $racy));
    }

    /**
     * Root never changes a mode inside the web user's tree.
     *
     * The tree is the web user's to write, so anything in it can be a link the
     * web user planted, and chmod as root follows it: an index entry that was
     * a link to /etc/akconnect/coordinator.env was made 0755.
     */
    private static function installerRootNeverActsInsideTheWebTree(): void
    {
        TestCase::group('Defect — root does not chmod through the web user\'s links');

        $script = (string) @file_get_contents(APP_ROOT . '/deploy/getting-started.sh');
        $asRoot = [];
        foreach (explode("\n", $script) as $n => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            if (preg_match('/\bchmod\b/', $line) === 1
                && (str_contains($line, '$PANEL_DIR') || str_contains($line, '$repo/'))
                && !str_contains($line, 'as_user') && !str_contains($line, 'as_owner')
                && preg_match('/^\s*(say|warn|die|printf|ok)\b|^\s+sudo chmod o\+rX/', $line) !== 1) {
                $asRoot[] = ($n + 1) . ': ' . trim($line);
            }
        }
        TestCase::assert($asRoot === [], 'every mode change in the panel tree is made as the web user',
            implode(' | ', array_slice($asRoot, 0, 3)));
    }

    private static function chownTree(string $path, string $user): void
    {
        @chown($path, $user);
        @chgrp($path, $user === 'nobody' ? 'nogroup' : $user);
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $entry) {
            @chown($entry->getPathname(), $user);
            @chgrp($entry->getPathname(), $user === 'nobody' ? 'nogroup' : $user);
        }
    }
}
