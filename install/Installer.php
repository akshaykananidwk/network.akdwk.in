<?php

declare(strict_types=1);

namespace Install;

/**
 * Installer logic, separate from its presentation.
 *
 * This class runs *before* the application exists — there is no config, no
 * autoloader beyond what index.php sets up, and no database. It therefore
 * depends on nothing from app/ except the handful of Core classes it loads
 * explicitly, and it must degrade gracefully at every step: a missing
 * extension, an unwritable directory or a refused database connection all have
 * to produce a clear instruction rather than a fatal error.
 */
final class Installer
{
    public const STEPS = ['welcome', 'requirements', 'database', 'admin', 'configuration', 'finish'];

    /** @var list<string> */
    private array $log = [];

    public function __construct(private readonly string $appRoot)
    {
    }

    public function isLocked(): bool
    {
        return is_file($this->appRoot . '/install/install.lock');
    }

    public function isConfigured(): bool
    {
        return is_file($this->appRoot . '/config/config.php');
    }

    // ------------------------------------------------------------ step 2

    /**
     * Environment checks.
     *
     * Each row says what is required, what was found, and — when it fails —
     * what to do about it. A red row the operator cannot act on is useless.
     *
     * @return array{ok:bool,groups:array<string,list<array<string,mixed>>>}
     */
    public function checkRequirements(): array
    {
        $groups = [];

        $groups['PHP'] = [
            $this->row(
                'PHP version',
                version_compare(PHP_VERSION, '8.1.0', '>='),
                PHP_VERSION,
                '8.1 or newer',
                true,
                'Ask your host to switch this site to PHP 8.1+, or select it in your control panel.'
            ),
        ];

        $required = ['pdo_mysql', 'openssl', 'curl', 'zip', 'mbstring', 'json', 'fileinfo'];
        $optional = ['sodium', 'opcache', 'intl', 'redis'];

        foreach ($required as $extension) {
            $groups['Required extensions'][] = $this->row(
                $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'loaded' : 'missing',
                'loaded',
                true,
                'Enable the ' . $extension . ' extension in your PHP configuration, then re-check.'
            );
        }

        foreach ($optional as $extension) {
            $note = match ($extension) {
                'sodium'  => 'Needed to generate signing keys and to verify signed update manifests.',
                'opcache' => 'Strongly recommended for performance. The updater resets it automatically.',
                'intl'    => 'Improves date and number formatting.',
                'redis'   => 'Optional. Without it, rate limiting and caching fall back to MySQL.',
                default   => '',
            };
            $groups['Optional extensions'][] = $this->row(
                $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'loaded' : 'not loaded',
                'recommended',
                false,
                $note
            );
        }

        foreach (['config', 'storage', 'storage/logs', 'storage/cache', 'storage/backups', 'storage/updates', 'storage/tmp', 'uploads', 'install'] as $directory) {
            $path = $this->appRoot . '/' . $directory;
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);

            $groups['Writable directories'][] = $this->row(
                $directory . '/',
                $writable,
                $exists ? ($writable ? 'writable' : 'not writable') : 'missing',
                'writable',
                true,
                $exists
                    ? 'Run: chmod 775 ' . $directory . ' (and chown it to the web server user).'
                    : 'Create the directory, then make it writable: mkdir -p ' . $directory . ' && chmod 775 ' . $directory
            );
        }

        $rewrite = $this->probeRewrite();
        $groups['Web server'][] = $this->row(
            'URL rewriting',
            $rewrite['ok'],
            $rewrite['detail'],
            'working',
            true,
            'Enable mod_rewrite and set "AllowOverride All" for this directory, or add the equivalent rule to your nginx config.'
        );

        $memory = $this->bytesFromIni((string) ini_get('memory_limit'));
        $groups['Limits'][] = $this->row(
            'memory_limit',
            $memory === -1 || $memory >= 134217728,
            (string) ini_get('memory_limit'),
            '128M or more',
            false,
            'Updates and backups of large sites need headroom. 256M is comfortable.'
        );

        $maxExecution = (int) ini_get('max_execution_time');
        $groups['Limits'][] = $this->row(
            'max_execution_time',
            $maxExecution === 0 || $maxExecution >= 60,
            $maxExecution === 0 ? 'unlimited' : $maxExecution . 's',
            '60s or more',
            false,
            'The updater works in resumable steps, so a low limit is survivable — but 60s+ avoids retries.'
        );

        $free = @disk_free_space($this->appRoot);
        $groups['Limits'][] = $this->row(
            'Free disk space',
            $free === false || $free > 536870912,
            $free === false ? 'unknown' : $this->humanBytes((int) $free),
            '512 MB or more',
            false,
            'Backups and staged updates need room. Clear space before updating.'
        );

        $ok = true;
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if ($row['required'] && !$row['ok']) {
                    $ok = false;
                }
            }
        }

        return ['ok' => $ok, 'groups' => $groups];
    }

    /**
     * Actually exercise rewriting rather than inferring it.
     *
     * apache_get_modules() is unavailable under php-fpm and tells us nothing
     * about whether .htaccess is honoured, so we request the probe URL.
     *
     * @return array{ok:bool,detail:string}
     */
    private function probeRewrite(): array
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = rtrim(str_replace('/install', '', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/');
        $url = sprintf('%s://%s%s/__rewrite_probe', $scheme, $host, $base);

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detail' => 'cannot test (curl missing)'];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'detail' => 'cannot test'];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => false, // a self-signed cert must not fail the probe
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            return ['ok' => false, 'detail' => 'probe request failed'];
        }

        $decoded = json_decode((string) $body, true);
        if ($status === 200 && is_array($decoded) && ($decoded['rewrite'] ?? false) === true) {
            return ['ok' => true, 'detail' => 'working'];
        }

        return ['ok' => false, 'detail' => 'not working (HTTP ' . $status . ')'];
    }

    // ------------------------------------------------------------ step 3

    /**
     * @return array{ok:bool,message:string,version?:string,can_create?:bool,existing_tables?:int}
     */
    public function testDatabase(string $host, int $port, string $name, string $user, string $password, string $prefix = ''): array
    {
        try {
            // Connect without a database first so we can report "server fine,
            // database missing" separately from "credentials wrong".
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 8]
            );
        } catch (\PDOException $e) {
            return ['ok' => false, 'message' => $this->explainPdoError($e, $host, $port)];
        }

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        if (version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '5.7', '<')
            && stripos($version, 'mariadb') === false) {
            return ['ok' => false, 'message' => 'MySQL 5.7+ or MariaDB 10.3+ is required; this server reports ' . $version . '.'];
        }

        $exists = $pdo->query(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($name)
        )->fetchColumn();

        $canCreate = false;
        if ((int) $exists === 0) {
            try {
                $pdo->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $name)
                ));
                $canCreate = true;
            } catch (\PDOException) {
                return [
                    'ok'      => false,
                    'message' => sprintf(
                        'Connected successfully, but the database "%s" does not exist and this user cannot create it. '
                        . 'Create it in your control panel (character set utf8mb4, collation utf8mb4_unicode_ci) and try again.',
                        $name
                    ),
                ];
            }
        }

        $pdo->exec('USE `' . str_replace('`', '', $name) . '`');

        $existingTables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $pdo->quote($name)
            . ($prefix !== '' ? ' AND TABLE_NAME LIKE ' . $pdo->quote($prefix . '%') : '')
        )->fetchColumn();

        return [
            'ok'              => true,
            'message'         => sprintf(
                'Connected to %s%s.%s',
                $version,
                $canCreate ? ' and created the database' : '',
                $existingTables > 0 ? ' Warning: ' . $existingTables . ' existing table(s) found.' : ''
            ),
            'version'         => $version,
            'can_create'      => $canCreate,
            'existing_tables' => $existingTables,
        ];
    }

    /**
     * Import schema.sql and seed.sql.
     *
     * @return array{ok:bool,message:string,tables:int,statements:int}
     */
    public function importSchema(\PDO $pdo, string $prefix, bool $dropExisting = false): array
    {
        $statementsRun = 0;

        if ($dropExisting) {
            $statementsRun += $this->dropExistingTables($pdo, $prefix);
        }

        foreach (['schema.sql', 'seed.sql'] as $file) {
            $path = $this->appRoot . '/database/' . $file;
            if (!is_file($path)) {
                return ['ok' => false, 'message' => 'Missing ' . $file, 'tables' => 0, 'statements' => $statementsRun];
            }

            $sql = str_replace('__PREFIX__', $prefix, (string) file_get_contents($path));

            foreach (\App\Updater\MigrationRunner::splitStatements($sql) as $statement) {
                try {
                    $pdo->exec($statement);
                    $statementsRun++;
                } catch (\PDOException $e) {
                    return [
                        'ok'         => false,
                        'message'    => sprintf('%s failed: %s', $file, $e->getMessage()),
                        'tables'     => 0,
                        'statements' => $statementsRun,
                    ];
                }
            }

            $this->log[] = sprintf('Imported %s (%d statements).', $file, $statementsRun);
        }

        $tables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            . ($prefix !== '' ? ' AND TABLE_NAME LIKE ' . $pdo->quote($prefix . '%') : '')
        )->fetchColumn();

        return [
            'ok'         => true,
            'message'    => sprintf('%d tables created.', $tables),
            'tables'     => $tables,
            'statements' => $statementsRun,
        ];
    }

    private function dropExistingTables(\PDO $pdo, string $prefix): int
    {
        $rows = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            . ($prefix !== '' ? ' AND TABLE_NAME LIKE ' . $pdo->quote($prefix . '%') : '')
        )->fetchAll(\PDO::FETCH_COLUMN);

        if ($rows === []) {
            return 0;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->log[] = sprintf('Dropped %d existing table(s).', count($rows));

        return count($rows);
    }

    // ------------------------------------------------------------ step 6

    /**
     * Write config/config.php.
     *
     * var_export produces a literal PHP array, so nothing here depends on the
     * application being loadable — which matters, because this file is what
     * makes it loadable.
     *
     * @param array<string,mixed> $answers
     */
    public function writeConfig(array $answers): string
    {
        $config = [
            'app' => [
                'name'            => $answers['site_name'],
                'version'         => trim((string) @file_get_contents($this->appRoot . '/VERSION')) ?: '1.0.0',
                'env'             => 'production',
                'debug'           => false,
                'url'             => rtrim((string) $answers['app_url'], '/'),
                'domain'          => (string) parse_url((string) $answers['app_url'], PHP_URL_HOST),
                'base_path'       => $answers['base_path'] ?? '',
                'timezone'        => $answers['timezone'],
                'locale'          => $answers['locale'] ?? 'en',
                'key'             => $answers['app_key'],
                'trusted_proxies' => [],
                'health_token'    => $answers['health_token'],
                'installed_at'    => gmdate('c'),
            ],
            'brand' => [
                'name'          => $answers['site_name'],
                'org'           => $answers['org_name'] ?? $answers['site_name'],
                'support_email' => $answers['support_email'],
                'logo'          => '',
                'primary_color' => '#2563eb',
                'accent_color'  => '#0ea5e9',
                'powered_by'    => true,
            ],
            'db' => [
                'host'          => $answers['db_host'],
                'port'          => (int) $answers['db_port'],
                'name'          => $answers['db_name'],
                'user'          => $answers['db_user'],
                'pass'          => $answers['db_pass'],
                'prefix'        => $answers['db_prefix'] ?? '',
                'read_host'     => null,
                'slow_query_ms' => 500,
            ],
            'redis' => [
                'enabled' => false,
                'host'    => '127.0.0.1',
                'port'    => 6379,
                'pass'    => '',
                'db'      => 0,
            ],
            'session' => [
                'name'             => 'ak_session',
                'lifetime_minutes' => 720,
                'idle_minutes'     => 240,
            ],
            'security' => [
                'password_min_length'   => 10,
                'lockout_threshold'     => 5,
                'lockout_max_seconds'   => 3600,
                'require_2fa_super'     => true,
                'api_rate_per_minute'   => 120,
                'login_rate_per_15min'  => 20,
                // Volume ceiling, not the abuse control: forty machines in
                // one office share one address and every request is real. What
                // is limited tightly is a *failed* enrolment, below.
                'enroll_requests_per_hour'    => 1200,
                'enroll_failures_per_15min'   => 15,
                'hsts_max_age'          => 31536000,
                'controller_public_key' => $answers['controller_public_key'] ?? '',
                'update_public_key'     => '',
            ],
            'mail' => [
                'host'        => $answers['mail_host'] ?? '',
                'port'        => (int) ($answers['mail_port'] ?? 587),
                'user'        => $answers['mail_user'] ?? '',
                'pass'        => $answers['mail_pass'] ?? '',
                'security'    => $answers['mail_security'] ?? 'tls',
                'from'        => $answers['mail_from'] ?? ('no-reply@' . parse_url((string) $answers['app_url'], PHP_URL_HOST)),
                'from_name'   => $answers['site_name'],
                'verify_peer' => true,
                'timeout'     => 15,
            ],
            'coordinator' => [
                // Blank is honest: an operator who has not stood the
                // coordinator up yet gets a panel that says so, instead of one
                // that points every agent at its own loopback address. The key
                // is written because DeviceService reads it — omitting it made
                // discovery configured and silent.
                'host'          => $answers['coordinator_host'] ?? '',
                'port'          => (int) ($answers['coordinator_port'] ?? 8443),
                'internal_url'  => 'http://127.0.0.1:8080',
                'public_key'    => $answers['coordinator_public_key'] ?? '',
                'shared_secret' => $answers['coordinator_secret'],
                'signing_key'   => $answers['controller_secret_key'] ?? '',
                'public_host'   => $answers['coordinator_host'] ?? '',
            ],
            'relays' => [],
            'network' => [
                'default_cidr'        => '10.50.0.0/16',
                'default_mtu'         => 1280,
                'default_keepalive'   => 25,
                'default_dns'         => ['1.1.1.1', '8.8.8.8'],
                'join_code_ttl_min'   => 60,
                'allow_default_route' => false,
            ],
            'update' => [
                'github_api'               => 'https://api.github.com',
                'timeout_seconds'          => 120,
                'user_agent'               => 'AK-Connect-Updater',
                'cache_minutes'            => 15,
                'min_free_disk_multiplier' => 3,
            ],
            'backup' => [
                'encryption_key'    => $answers['backup_key'],
                'max_uploads_bytes' => 2147483648,
                'offsite_driver'    => 'none',
                'offsite'           => [],
                'retention_days'    => 30,
            ],
            'logging' => [
                'level'          => 'info',
                'retention_days' => 30,
            ],
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n"
            . " * Generated by the installer on " . gmdate('c') . ".\n"
            . " *\n"
            . " * This file is listed as a protected path, so the updater will never\n"
            . " * overwrite it. It is also in .gitignore — it holds credentials.\n"
            . " *\n"
            . " * To override a single value without editing this file (useful when one node\n"
            . " * in a cluster needs a different setting), create config/config.local.php\n"
            . " * returning a partial array; it is merged over this one and is equally\n"
            . " * protected from updates.\n"
            . " */\n\n"
            . 'return ' . $this->exportArray($config, 0) . ";\n";

        $path = $this->appRoot . '/config/config.php';
        if (file_put_contents($path, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write config/config.php. Check that config/ is writable.');
        }

        // 0640: readable by the web server user, not by other accounts on a
        // shared host. It holds the database password and the app key.
        @chmod($path, 0640);

        $this->log[] = 'Wrote config/config.php';

        return $path;
    }

    /** @param array<string,mixed> $answers */
    public function writeEnv(array $answers): void
    {
        $lines = [
            '# Written by the installer. config/config.php is authoritative;',
            '# these exist for tooling that reads the environment instead.',
            '#',
            '# This file lives in config/ rather than the web root on purpose:',
            '# config/ is denied as a whole directory, while a root .env relies',
            '# on a per-file rule that nginx ignores and Apache skips under',
            '# AllowOverride None.',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . rtrim((string) $answers['app_url'], '/'),
            '',
            'DB_HOST=' . $answers['db_host'],
            'DB_PORT=' . $answers['db_port'],
            'DB_NAME=' . $answers['db_name'],
            'DB_USER=' . $answers['db_user'],
            'DB_PASS=' . $answers['db_pass'],
            'DB_PREFIX=' . ($answers['db_prefix'] ?? ''),
        ];

        $path = $this->appRoot . '/config/.env';
        file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
        @chmod($path, 0640);

        // Remove a root .env left by an earlier install so the credentials do
        // not linger somewhere the web server might serve them.
        $legacy = $this->appRoot . '/.env';
        if (is_file($legacy)) {
            @unlink($legacy);
            $this->log[] = 'Removed the legacy root .env';
        }

        $this->log[] = 'Wrote config/.env';
    }

    public function lock(): void
    {
        $path = $this->appRoot . '/install/install.lock';
        file_put_contents($path, json_encode([
            'installed_at' => gmdate('c'),
            'version'      => trim((string) @file_get_contents($this->appRoot . '/VERSION')),
            'php'          => PHP_VERSION,
        ], JSON_PRETTY_PRINT));
        @chmod($path, 0640);

        $this->log[] = 'Created install/install.lock';
    }

    /** The crontab line shown on the final screen and in the README. */
    public function crontabLine(): string
    {
        $php = PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/bin/php';

        return sprintf('*/5 * * * * %s %s/cli/worker.php >> %s/storage/logs/cron.log 2>&1', $php, $this->appRoot, $this->appRoot);
    }

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    public function writeLog(): void
    {
        $path = $this->appRoot . '/storage/logs/install.log';
        $line = '[' . gmdate('c') . '] ' . implode(' | ', $this->log) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    // ----------------------------------------------------------- internals

    /** @return array<string,mixed> */
    private function row(string $label, bool $ok, string $found, string $expected, bool $required, string $fix = ''): array
    {
        return [
            'label'    => $label,
            'ok'       => $ok,
            'found'    => $found,
            'expected' => $expected,
            'required' => $required,
            'fix'      => $ok ? '' : $fix,
        ];
    }

    private function explainPdoError(\PDOException $e, string $host, int $port): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Access denied')) {
            return 'The database username or password was not accepted. Check both, and that the user may connect from this server.';
        }
        if (str_contains($message, 'Connection refused') || str_contains($message, "Can't connect")) {
            return sprintf('No database server answered at %s:%d. Check the host and port — many panels use 127.0.0.1 rather than localhost.', $host, $port);
        }
        if (str_contains($message, 'Unknown database')) {
            return 'That database does not exist and could not be created. Create it in your control panel first.';
        }

        return 'Could not connect: ' . $message;
    }

    /** Pretty-print an array as PHP source, with aligned keys. */
    private function exportArray(array $array, int $depth): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $closeIndent = str_repeat('    ', $depth);

        if ($array === []) {
            return '[]';
        }

        $isList = array_is_list($array);
        $lines = [];

        foreach ($array as $key => $value) {
            $prefix = $isList ? '' : var_export($key, true) . ' => ';

            if (is_array($value)) {
                $lines[] = $indent . $prefix . $this->exportArray($value, $depth + 1);
            } elseif (is_bool($value)) {
                $lines[] = $indent . $prefix . ($value ? 'true' : 'false');
            } elseif ($value === null) {
                $lines[] = $indent . $prefix . 'null';
            } elseif (is_int($value) || is_float($value)) {
                $lines[] = $indent . $prefix . $value;
            } else {
                $lines[] = $indent . $prefix . var_export((string) $value, true);
            }
        }

        return "[\n" . implode(",\n", $lines) . ",\n" . $closeIndent . ']';
    }

    private function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
