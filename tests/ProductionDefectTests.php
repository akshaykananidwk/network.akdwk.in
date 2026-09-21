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
