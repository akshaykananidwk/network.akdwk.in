<?php

declare(strict_types=1);

namespace Tests;

/**
 * Checks that the documentation still describes the code.
 *
 * SECURITY.md claims specific controls live in specific places. A citation
 * that quietly stops resolving turns a security document into decoration, so
 * every `file::symbol` reference is verified here.
 */
final class DocumentationTests
{
    public static function run(): void
    {
        self::securityCitations();
        self::requiredDocuments();
        self::nothingStopsTheWebServer();
        self::gettingStartedMatchesTheServices();
    }

    private static function securityCitations(): void
    {
        TestCase::group('Documentation — SECURITY.md citations resolve');

        $path = APP_ROOT . '/SECURITY.md';

        if (!is_file($path)) {
            TestCase::assert(false, 'SECURITY.md exists');

            return;
        }

        $markdown = (string) file_get_contents($path);

        // `path/to/File.php::symbol` and the `::symbol` shorthand that follows it.
        preg_match_all(
            '/`([a-zA-Z0-9_\/\.]+\.php)(?:::([a-zA-Z_][a-zA-Z0-9_]*))?`|`::([a-zA-Z_][a-zA-Z0-9_]*)`/',
            $markdown,
            $matches,
            PREG_SET_ORDER
        );

        $missingFiles = [];
        $missingSymbols = [];
        $checkedFiles = [];
        $checkedSymbols = 0;
        $lastFile = null;

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                $file = $match[1];
                $symbol = $match[2] ?? '';
                $lastFile = $file;
            } else {
                // A bare `::symbol` refers back to the previous file.
                $file = $lastFile;
                $symbol = $match[3] ?? '';
            }

            if ($file === null) {
                continue;
            }

            $absolute = APP_ROOT . '/' . $file;

            if (!is_file($absolute)) {
                $missingFiles[$file] = true;
                continue;
            }

            $checkedFiles[$file] = true;

            if ($symbol === '') {
                continue;
            }

            $source = (string) file_get_contents($absolute);
            $checkedSymbols++;

            if (preg_match('/function\s+' . preg_quote($symbol, '/') . '\s*\(/', $source) !== 1) {
                $missingSymbols[] = $file . '::' . $symbol;
            }
        }

        TestCase::assert($missingFiles === [],
            count($checkedFiles) . ' cited files exist',
            $missingFiles === [] ? '' : 'missing: ' . implode(', ', array_keys($missingFiles)));

        TestCase::assert($missingSymbols === [],
            $checkedSymbols . ' cited symbols exist',
            $missingSymbols === [] ? '' : 'missing: ' . implode(', ', array_slice($missingSymbols, 0, 5)));
    }

    private static function requiredDocuments(): void
    {
        TestCase::group('Documentation — required files present');

        $required = [
            'README.md'                => 'install, update, cron, troubleshooting',
            'DEPLOY.md'                => 'vhosts, SSL, Docker',
            'API.md'                   => 'REST API v1',
            'SECURITY.md'              => 'controls and OWASP mapping',
            'CHANGELOG.md'             => 'release history',
            'THIRD_PARTY_LICENSES.md'  => 'dependency licences',
            'PROGRESS.md'              => 'what is done and what is not',
            'VERIFICATION_REPORT.md'   => 'test results',
            'docs/openapi.yaml'        => 'machine-readable API spec',
            'update.json'              => 'release manifest',
            'config/.env.sample'       => 'configuration reference',
            'config/config.sample.php' => 'configuration reference',
        ];

        foreach ($required as $file => $purpose) {
            $absolute = APP_ROOT . '/' . $file;
            $exists = is_file($absolute) && filesize($absolute) > 0;

            TestCase::assert($exists, $file . ' is present', $exists ? '' : $purpose);
        }

        self::manifestAgreesWithTheChangelog();

        // .gitignore must keep the credential files out of the repository.
        $gitignore = is_file(APP_ROOT . '/.gitignore') ? (string) file_get_contents(APP_ROOT . '/.gitignore') : '';
        foreach (['/config/config.php', '/config/.env', '/storage/', '/uploads/', 'install.lock'] as $pattern) {
            TestCase::assertContains($pattern, $gitignore, '.gitignore excludes ' . $pattern);
        }
    }

    /**
     * Every place this tree writes its own version number says the same one.
     *
     * Three places, and each is read by something different at a different
     * moment, so any two of them disagreeing breaks a different thing:
     *
     *   VERSION       upgrade-edge.sh compares it against the version the
     *                 panel reports, and refuses the upgrade when they
     *                 differ. Left behind, the edge cannot be upgraded at
     *                 all — which is how this was found: "the checkout says
     *                 1.9.5, the panel says 1.9.6".
     *   update.json   the panel reads it AFTER downloading the tag and acts
     *                 on it: the version it then reports, and which
     *                 migrations it runs. Left behind, it installs the new
     *                 code, reports the old version, and runs none of the new
     *                 migrations. On 1.9.6 that would have installed the
     *                 HTTPS fallback without the column the panel needs to
     *                 show it — the feature present and invisible, on the
     *                 release whose whole point is a path the panel has to be
     *                 able to name.
     *   CHANGELOG.md  what a person reads, and the only one of the three that
     *                 nobody can forget, because writing the release notes is
     *                 the act of making a release.
     *
     * The tag itself cannot be checked from here: it does not exist until
     * after this commit. These three can, and they are edited by the same
     * hand in the same release, so disagreeing with each other is the shape
     * the mistake takes — twice in two days, in two different files.
     */
    private static function manifestAgreesWithTheChangelog(): void
    {
        $manifest = json_decode((string) @file_get_contents(APP_ROOT . '/update.json'), true);
        TestCase::assert(is_array($manifest), 'update.json is valid JSON');

        $version = (string) ($manifest['version'] ?? '');

        // A development build on a release branch carries a -dev.N suffix, so
        // the Edge channel has something newer than the last tag to offer and
        // each push is distinguishable from the one before. version_compare
        // orders them the way this needs: 1.9.6 < 1.9.7-dev.1 < 1.9.7-dev.2
        // < 1.9.7, so a dev build never outranks the release it precedes.
        TestCase::assert(
            preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) === 1,
            'update.json names a three-part version, optionally a prerelease'
        );

        $changelog = (string) @file_get_contents(APP_ROOT . '/CHANGELOG.md');
        TestCase::assert(
            preg_match('/^## \[(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)\]/m', $changelog, $newest) === 1,
            'CHANGELOG.md has a newest release heading'
        );

        TestCase::assertSame(
            $newest[1],
            $version,
            'update.json names the release CHANGELOG.md documents at the top'
        );

        $versionFile = trim((string) @file_get_contents(APP_ROOT . '/VERSION'));
        TestCase::assertSame(
            $version,
            $versionFile,
            'VERSION names the same release as update.json — upgrade-edge.sh refuses the edge upgrade when it does not'
        );

        // And every migration it names is really in the tree, because one that
        // is not stops the update part-way through on a customer\'s panel.
        foreach ((array) ($manifest['migrations'] ?? []) as $migration) {
            TestCase::assert(
                is_file(APP_ROOT . '/database/migrations/' . $migration),
                'update.json migration ' . $migration . ' exists'
            );
        }
    }

    /**
     * No instruction in this repository may tell an operator to stop Apache.
     *
     * The R6 drill needs the panel to stop answering for a few minutes, and
     * the obvious way to arrange that is `systemctl stop apache2`. It was
     * suggested here, for a server that carries more than thirty other
     * people's websites and their mail: the drill would have taken every one
     * of them down, along with mail delivery, to find out something about one
     * virtual host.
     *
     * cli/maintenance.php is the answer — one site, 503, reversible, and the
     * rest of the machine never notices. This check exists because writing
     * that down is not the same as it being followed: the next person to need
     * a quiet panel will reach for the same blunt instrument unless something
     * refuses it.
     *
     * Prose about the rule is allowed; an instruction is not. The difference
     * is a command line, so that is what is matched.
     */
    private static function nothingStopsTheWebServer(): void
    {
        TestCase::group('Documentation — nothing tells an operator to stop the web server');

        $roots = ['deploy', 'docs', 'services/lab', 'cli', 'install'];
        $files = [
            APP_ROOT . '/DEPLOY.md',
            APP_ROOT . '/README.md',
            APP_ROOT . '/CHANGELOG.md',
        ];

        foreach ($roots as $root) {
            $dir = APP_ROOT . '/' . $root;
            if (!is_dir($dir)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $found */
            $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($found as $entry) {
                if ($entry->isFile() && in_array($entry->getExtension(), ['md', 'sh', 'php', 'txt'], true)) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        // Any web server, not only Apache: httpd, nginx and php-fpm serve the
        // same other people.
        $services = 'apache2?|httpd|nginx|php[0-9.]*-fpm';
        $pattern = '/(?:systemctl|service|rc-service)\s+(?:stop|disable)\s+(?:' . $services . ')'
            . '|(?:systemctl|rc-service)\s+(?:' . $services . ')\s+stop'
            . '|\bservice\s+(?:' . $services . ')\s+stop'
            . '|\bapachectl\s+(?:stop|graceful-stop)/i';

        $offenders = [];
        foreach (array_unique($files) as $file) {
            $body = (string) @file_get_contents($file);
            if ($body === '') {
                continue;
            }

            foreach (explode("\n", $body) as $number => $line) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                // The rule may quote the thing it forbids.
                if (stripos($line, 'never') !== false || stripos($line, 'not stop') !== false) {
                    continue;
                }

                $offenders[] = ltrim(str_replace(APP_ROOT . '/', '', $file) . ':' . ($number + 1)
                    . ' — ' . trim($line));
            }
        }

        TestCase::assert(
            $offenders === [],
            'no shipped instruction stops a web server the whole machine shares',
            $offenders === []
                ? ''
                : implode('; ', array_slice($offenders, 0, 5))
                . '. Use cli/maintenance.php: it takes this panel alone to 503 and leaves every other site serving.'
        );

        // And the replacement is really there to be reached for.
        TestCase::assert(
            is_file(APP_ROOT . '/cli/maintenance.php'),
            'cli/maintenance.php ships, so there is something to use instead'
        );

        TestCase::assert(
            str_contains((string) @file_get_contents(APP_ROOT . '/DEPLOY.md'), 'cli/maintenance.php'),
            'DEPLOY.md documents it'
        );
    }

    /**
     * The one-command installer must open exactly the ports the services bind.
     *
     * getting-started.sh writes firewall rules from numbers typed into it, and
     * the services take theirs from their systemd units and their own
     * defaults. Nothing connects the two, so moving the relay's data-port
     * range leaves a firewall that blocks it — and the symptom is a relay that
     * accepts a binding and then carries nothing, which reads as a network
     * fault at the customer's end rather than a rule on ours.
     */
    private static function gettingStartedMatchesTheServices(): void
    {
        TestCase::group('Documentation — the installer opens the ports the services use');

        $script = (string) @file_get_contents(APP_ROOT . '/deploy/getting-started.sh');
        $relayUnit = (string) @file_get_contents(APP_ROOT . '/deploy/systemd/akconnect-relay.service');
        $coordUnit = (string) @file_get_contents(APP_ROOT . '/deploy/systemd/akconnect-coordinator.service');

        TestCase::assert($script !== '', 'deploy/getting-started.sh ships with the release');

        // The relay's data ports, as its unit really pins them.
        $ports = [];
        preg_match('/--data-ports\s+(\d+)-(\d+)/', $relayUnit, $ports);
        TestCase::assert($ports !== [], 'the relay unit pins a data-port range');

        if ($ports !== []) {
            TestCase::assert(
                str_contains($script, $ports[1] . ':' . $ports[2] . '/udp'),
                'the installer opens the relay data ports the unit pins (' . $ports[1] . '-' . $ports[2] . ')',
                str_contains($script, $ports[1] . ':' . $ports[2] . '/udp') ? '' :
                    'the unit says --data-ports ' . $ports[1] . '-' . $ports[2]
                    . ' and getting-started.sh does not open that range'
            );
        }

        // The relay's control port, and the coordinator's.
        preg_match('/--control\s+:(\d+)/', $relayUnit, $control);
        if ($control !== []) {
            TestCase::assert(
                str_contains($script, $control[1] . '/udp'),
                'the installer opens the relay control port (' . $control[1] . ')'
            );
        }

        preg_match('/--listen\s+:(\d+)/', $coordUnit, $listen);
        if ($listen !== []) {
            TestCase::assert(
                str_contains($script, $listen[1] . '/udp'),
                'the installer opens the coordinator port (' . $listen[1] . ')'
            );
        }

        // The fallback. The relay listens on one address and the panel derives
        // one path; the installer's web-server configuration has to join them.
        preg_match('/--ws-listen\s+([0-9.]+:\d+)/', $relayUnit, $wsListen);
        if ($wsListen !== []) {
            TestCase::assert(
                str_contains($script, 'reverse_proxy ' . $wsListen[1]),
                'the installer proxies the fallback to where the relay listens (' . $wsListen[1] . ')'
            );
        }

        TestCase::assert(
            str_contains($script, 'handle ' . \App\Services\CoordinatorSettings::FALLBACK_PATH),
            'the installer serves the fallback on the path the panel hands to agents ('
            . \App\Services\CoordinatorSettings::FALLBACK_PATH . ')'
        );

        // And the thing that makes a web server other than Apache safe here:
        // Caddy does not read .htaccess, so every directory .htaccess denies
        // has to be denied again in the site file this script writes.
        $htaccess = (string) @file_get_contents(APP_ROOT . '/.htaccess');
        preg_match('/RewriteRule \^\(([a-z|]+)\)\(\/\|\$\)/', $htaccess, $denied);
        TestCase::assert($denied !== [], '.htaccess names the directories it denies');

        if ($denied !== []) {
            $missing = [];
            foreach (explode('|', $denied[1]) as $directory) {
                if (!str_contains($script, '/' . $directory . '/*')) {
                    $missing[] = $directory;
                }
            }

            TestCase::assert(
                $missing === [],
                'the installer denies every directory .htaccess denies',
                $missing === [] ? '' : 'not denied in the Caddy site file: ' . implode(', ', $missing)
                . '. Caddy does not read .htaccess, so these would be served as plain files.'
            );
        }
    }
}
