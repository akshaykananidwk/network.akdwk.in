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
}
