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
            '.env.sample'              => 'configuration reference',
            'config/config.sample.php' => 'configuration reference',
        ];

        foreach ($required as $file => $purpose) {
            $absolute = APP_ROOT . '/' . $file;
            $exists = is_file($absolute) && filesize($absolute) > 0;

            TestCase::assert($exists, $file . ' is present', $exists ? '' : $purpose);
        }

        // .gitignore must keep the credential files out of the repository.
        $gitignore = is_file(APP_ROOT . '/.gitignore') ? (string) file_get_contents(APP_ROOT . '/.gitignore') : '';
        foreach (['/config/config.php', '/config/.env', '/storage/', '/uploads/', 'install.lock'] as $pattern) {
            TestCase::assertContains($pattern, $gitignore, '.gitignore excludes ' . $pattern);
        }
    }
}
