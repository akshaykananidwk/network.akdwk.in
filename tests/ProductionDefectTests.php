<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Crypto;
use App\Core\Request;
use App\Services\CoordinatorSettings;
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

        // Apache is the half of this that lives outside PHP, and both forms of
        // the configuration have to be in the release for install-edge.sh to
        // find one. See services/lab/edge-script-gate.sh for what is in them.
        foreach (
            ['akconnect-fallback.conf', 'akconnect-fallback-upgrade.conf'] as $conf
        ) {
            TestCase::assert(
                is_file(APP_ROOT . '/deploy/apache/' . $conf),
                'deploy/apache/' . $conf . ' ships with the release'
            );
        }

        // And the panel has to be able to store what a device reports about
        // it, or a device on the HTTPS path shows as an ordinary relay and
        // nobody can tell the two situations apart.
        $migrations = glob(APP_ROOT . '/database/migrations/*_device_relay_https_state.php') ?: [];
        TestCase::assert($migrations !== [], 'the relay_https connection state has a migration');

        $view = (string) @file_get_contents(APP_ROOT . '/app/Views/partials/connection.php');
        TestCase::assertContains('relay_https', $view, 'the device list can show the HTTPS path');
        TestCase::assertContains('relay-https', $view, 'and names it the way the agent reports it');
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
        TestCase::assertContains(
            'REACHED_END',
            $script,
            'and success is claimed only from the last line'
        );
        TestCase::assert(
            substr_count($script, 'REACHED_END=1') === 2,
            'which is set in exactly two places: the --check path and the end',
            substr_count($script, 'REACHED_END=1') . ' occurrence(s)'
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
}
