<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;
use App\Services\CoordinatorSettings;

/**
 * Post-update verification (§9.4 step 10).
 *
 * The contract is narrow on purpose: these checks decide whether an update
 * stands or is rolled back, so each one must be unambiguous and fast. A check
 * that could plausibly fail for an unrelated reason belongs in monitoring, not
 * here.
 *
 * Critical checks gate the update. Advisory ones are reported but do not
 * trigger a rollback — a coordinator that happens to be restarting should not
 * undo a good panel update.
 */
final class HealthChecker
{
    /** @var list<array{name:string,ok:bool,critical:bool,detail:string,ms:int}> */
    private array $results = [];

    public function __construct(private readonly string $appRoot)
    {
    }

    public static function make(): self
    {
        return new self(APP_ROOT);
    }

    /**
     * @return array{ok:bool,critical_failures:int,checks:list<array{name:string,ok:bool,critical:bool,detail:string,ms:int}>}
     */
    public function run(): array
    {
        $this->results = [];

        $this->check('Configuration loads', true, function (): string {
            if (!Config::isLoaded()) {
                throw new \RuntimeException('Configuration was never loaded.');
            }
            $key = (string) Config::get('app.key', '');
            if ($key === '') {
                throw new \RuntimeException('APP_KEY is empty — encrypted settings cannot be read.');
            }

            return 'version ' . Config::get('app.version', 'unknown');
        });

        $this->check('Database connection', true, function (): string {
            $value = DB::scalar('SELECT 1');
            if ((int) $value !== 1) {
                throw new \RuntimeException('SELECT 1 did not return 1.');
            }
            $version = (string) DB::scalar('SELECT VERSION()');

            return $version;
        });

        $this->check('Core tables present', true, function (): string {
            $required = ['users', 'tenants', 'networks', 'devices', 'migrations', 'app_updates', 'sessions'];
            $missing = [];
            foreach ($required as $table) {
                $exists = DB::scalar(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                    ['t' => DB::table($table)]
                );
                if ((int) $exists === 0) {
                    $missing[] = $table;
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException('Missing tables: ' . implode(', ', $missing));
            }

            return count($required) . ' core tables present';
        });

        $this->check('Migration ledger consistent', true, function (): string {
            $failed = (int) DB::scalar('SELECT COUNT(*) FROM ' . DB::table('migrations') . ' WHERE success = 0');
            if ($failed > 0) {
                throw new \RuntimeException($failed . ' migration(s) recorded as failed.');
            }
            $applied = (int) DB::scalar('SELECT COUNT(*) FROM ' . DB::table('migrations') . ' WHERE success = 1');

            return $applied . ' migrations applied';
        });

        $this->check('A super admin still exists', true, function (): string {
            $count = (int) DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('users') . '
                 WHERE role = \'super_admin\' AND deleted_at IS NULL AND status = \'active\''
            );
            if ($count === 0) {
                throw new \RuntimeException('No active super admin — nobody could log in to fix a problem.');
            }

            return $count . ' active';
        });

        $this->check('Critical files present', true, function (): string {
            $required = [
                'index.php',
                'health.php',
                'app/Core/Router.php',
                'app/Core/DB.php',
                'app/Core/Auth.php',
                'config/config.php',
                'VERSION',
            ];
            $missing = [];
            foreach ($required as $file) {
                if (!is_file($this->appRoot . '/' . $file)) {
                    $missing[] = $file;
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException('Missing after update: ' . implode(', ', $missing));
            }

            return count($required) . ' files verified';
        });

        $this->check('Application bootstraps', true, function (): string {
            // Re-parse the front controller and the routes file rather than
            // including them: a syntax error here is exactly the failure that
            // would otherwise present as a blank white panel.
            foreach (['index.php', 'app/routes.php'] as $file) {
                $path = $this->appRoot . '/' . $file;
                if (!is_file($path)) {
                    continue;
                }
                $source = file_get_contents($path);
                if ($source === false) {
                    throw new \RuntimeException('Cannot read ' . $file);
                }
                try {
                    token_get_all($source, TOKEN_PARSE);
                } catch (\ParseError $e) {
                    throw new \RuntimeException($file . ' has a syntax error: ' . $e->getMessage());
                }
            }

            return 'entry points parse';
        });

        $this->check('Writable directories', true, function (): string {
            $required = ['storage/logs', 'storage/cache', 'storage/backups', 'storage/tmp'];
            $unwritable = [];
            foreach ($required as $directory) {
                $path = $this->appRoot . '/' . $directory;
                if (!is_dir($path) || !is_writable($path)) {
                    $unwritable[] = $directory;
                }
            }
            if ($unwritable !== []) {
                throw new \RuntimeException('Not writable: ' . implode(', ', $unwritable));
            }

            return count($required) . ' directories writable';
        });

        $this->check('Health endpoint responds', false, function (): string {
            $url = rtrim((string) Config::get('app.url', ''), '/') . '/health.php';
            if ($url === '/health.php') {
                return 'skipped (no app URL configured)';
            }

            $curl = curl_init($url);
            if ($curl === false) {
                throw new \RuntimeException('Could not initialise the HTTP client.');
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                // The panel is in maintenance mode at this point, so a 503 with
                // a valid body is a healthy answer.
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false) {
                throw new \RuntimeException('Request failed: ' . $error);
            }
            if (!in_array($status, [200, 503], true)) {
                throw new \RuntimeException('Unexpected HTTP ' . $status);
            }

            return 'HTTP ' . $status;
        });

        $this->check('Coordinator reachable', false, function (): string {
            $coordinator = CoordinatorSettings::current();
            $host = (string) $coordinator['host'];
            $port = (int) $coordinator['port'];
            if ($host === '' || $port === 0) {
                return 'skipped (not configured)';
            }

            $socket = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($socket === false) {
                throw new \RuntimeException(sprintf('%s:%d unreachable — %s', $host, $port, $errstr));
            }
            fclose($socket);

            return $host . ':' . $port . ' reachable';
        });

        $criticalFailures = 0;
        foreach ($this->results as $result) {
            if (!$result['ok'] && $result['critical']) {
                $criticalFailures++;
            }
        }

        $summary = [
            'ok'                => $criticalFailures === 0,
            'critical_failures' => $criticalFailures,
            'checks'            => $this->results,
        ];

        Logger::info('update', 'Health check complete', [
            'ok'                => $summary['ok'],
            'critical_failures' => $criticalFailures,
            'checks'            => count($this->results),
        ]);

        return $summary;
    }

    /** @param callable():string $probe */
    private function check(string $name, bool $critical, callable $probe): void
    {
        $started = microtime(true);

        try {
            $detail = $probe();
            $ok = true;
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
            $ok = false;
        }

        $this->results[] = [
            'name'     => $name,
            'ok'       => $ok,
            'critical' => $critical,
            'detail'   => Logger::redactString($detail),
            'ms'       => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
