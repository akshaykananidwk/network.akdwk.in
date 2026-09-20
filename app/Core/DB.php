<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO wrapper. Prepared statements only — there is no method here that accepts
 * interpolated values, and the build fails (tests/SqlSafetyTest.php) if a model
 * or controller concatenates user input into SQL.
 *
 * read()/write() return separate handles so a read replica can be pointed at
 * later without touching call sites; with no replica configured both resolve to
 * the same connection.
 */
final class DB
{
    private static ?PDO $write = null;
    private static ?PDO $read = null;
    private static string $prefix = '';
    private static int $transactionDepth = 0;

    public static function write(): PDO
    {
        if (self::$write === null) {
            self::$write = self::connect('db');
        }

        return self::$write;
    }

    public static function read(): PDO
    {
        if (self::$read === null) {
            $replica = Config::get('db.read_host');
            self::$read = $replica ? self::connect('db', (string) $replica) : self::write();
        }

        return self::$read;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    /** Apply the configured table prefix. Used by schema-level helpers only. */
    public static function table(string $name): string
    {
        return self::$prefix . $name;
    }

    /**
     * Connect directly with explicit credentials. Used by the installer, which
     * runs before config/config.php exists.
     */
    public static function connectWith(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
        string $prefix = ''
    ): PDO {
        $dsn = $database === ''
            ? sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION time_zone='+00:00'");
        self::$prefix = $prefix;

        return $pdo;
    }

    /** Inject an already-built handle (installer, CLI, tests). */
    public static function setConnection(PDO $pdo, string $prefix = ''): void
    {
        self::$write = $pdo;
        self::$read = $pdo;
        self::$prefix = $prefix;
    }

    public static function reset(): void
    {
        self::$write = null;
        self::$read = null;
        self::$transactionDepth = 0;
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        return self::run(self::read(), $sql, $params);
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public static function execute(string $sql, array $params = []): PDOStatement
    {
        return self::run(self::write(), $sql, $params);
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function lastInsertId(): int
    {
        return (int) self::write()->lastInsertId();
    }

    /** Nested calls use savepoints so services can compose transactionally. */
    public static function begin(): void
    {
        if (self::$transactionDepth === 0) {
            self::write()->beginTransaction();
        } else {
            self::write()->exec('SAVEPOINT sp' . self::$transactionDepth);
        }
        self::$transactionDepth++;
    }

    public static function commit(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }
        self::$transactionDepth--;
        if (self::$transactionDepth === 0) {
            self::write()->commit();
        } else {
            self::write()->exec('RELEASE SAVEPOINT sp' . self::$transactionDepth);
        }
    }

    public static function rollback(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }
        self::$transactionDepth--;
        if (self::$transactionDepth === 0) {
            if (self::write()->inTransaction()) {
                self::write()->rollBack();
            }
        } else {
            self::write()->exec('ROLLBACK TO SAVEPOINT sp' . self::$transactionDepth);
        }
    }

    /** @template T @param callable():T $callback @return T */
    public static function transaction(callable $callback): mixed
    {
        self::begin();
        try {
            $result = $callback();
            self::commit();

            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    private static function connect(string $configPrefix, ?string $hostOverride = null): PDO
    {
        $host = $hostOverride ?? (string) (Config::get($configPrefix . '.host') ?? Config::env('DB_HOST', '127.0.0.1'));
        $port = (int) (Config::get($configPrefix . '.port') ?? Config::env('DB_PORT', 3306));
        $name = (string) (Config::get($configPrefix . '.name') ?? Config::env('DB_NAME', ''));
        $user = (string) (Config::get($configPrefix . '.user') ?? Config::env('DB_USER', ''));
        $pass = (string) (Config::get($configPrefix . '.pass') ?? Config::env('DB_PASS', ''));
        $prefix = (string) (Config::get($configPrefix . '.prefix') ?? Config::env('DB_PREFIX', ''));

        try {
            return self::connectWith($host, $port, $name, $user, $pass, $prefix);
        } catch (PDOException $e) {
            // The DSN carries the host and database name but never the password;
            // still, we log rather than surface it.
            Logger::error('db', 'Database connection failed', ['host' => $host, 'db' => $name, 'error' => $e->getMessage()]);
            throw new AppException('Database connection failed', 0, $e);
        }
    }

    /** @param array<string,mixed>|list<mixed> $params */
    private static function run(PDO $pdo, string $sql, array $params): PDOStatement
    {
        $started = microtime(true);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params === [] ? null : $params);
        } catch (PDOException $e) {
            Logger::error('db', 'Query failed', [
                'sql'   => $sql,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $elapsed = (microtime(true) - $started) * 1000;
        $slowMs = (float) Config::get('db.slow_query_ms', 500);
        if ($elapsed > $slowMs) {
            Logger::warning('db', 'Slow query', ['sql' => $sql, 'ms' => round($elapsed, 1)]);
        }

        return $stmt;
    }
}
