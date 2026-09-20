<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;
use App\Core\UpdateException;

/**
 * Database dump and restore.
 *
 * mysqldump is used when the host allows it and the schema is simple enough
 * for it (see chooseMethod); otherwise SqlDumpWriter produces the same
 * restorable SQL in pure PHP. Either way the output is a gzip stream of
 * DROP + CREATE + chunked INSERTs, plus views, triggers and routines.
 */
final class DatabaseDumper
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) (Config::get('db.host') ?? '127.0.0.1'),
            (int) (Config::get('db.port') ?? 3306),
            (string) (Config::get('db.name') ?? ''),
            (string) (Config::get('db.user') ?? ''),
            (string) (Config::get('db.pass') ?? ''),
        );
    }

    /**
     * Write a gzip-compressed dump.
     *
     * @return array{path:string,bytes:int,sha256:string,method:string,tables:int}
     */
    public function dump(string $destination): array
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new UpdateException('Cannot create backup directory: ' . $directory, 'BACKUP_DB');
        }

        $writer = new SqlDumpWriter($this->database);
        $method = $this->chooseMethod($writer);

        if ($method === 'mysqldump') {
            try {
                $tables = $this->dumpWithMysqldump($destination, $writer);
            } catch (UpdateException $e) {
                Logger::warning('backup', 'mysqldump failed; falling back to the PHP dumper', ['error' => $e->getMessage()]);
                $method = 'php';
                $tables = $writer->writeTo($destination);
            }
        } else {
            $tables = $writer->writeTo($destination);
        }

        $bytes = (int) filesize($destination);
        $sha256 = (string) hash_file('sha256', $destination);

        if ($bytes < 100) {
            throw new UpdateException('Database dump is implausibly small; refusing to treat it as a backup.', 'BACKUP_DB');
        }

        Logger::info('backup', 'Database dumped', ['bytes' => $bytes, 'method' => $method, 'tables' => $tables]);

        return ['path' => $destination, 'bytes' => $bytes, 'sha256' => $sha256, 'method' => $method, 'tables' => $tables];
    }

    /**
     * Pick the dumper.
     *
     * mysqldump is much faster, but MariaDB's writes STORED generated columns
     * into its INSERT statements — with or without --complete-insert — and
     * restoring that fails outright with "the value specified for generated
     * column ... has been ignored" (error 1906). A backup that cannot be
     * restored is not a backup, so a schema with generated columns always goes
     * through the PHP dumper, which emits an explicit column list without them.
     */
    private function chooseMethod(SqlDumpWriter $writer): string
    {
        $generated = $writer->generatedColumns();
        if ($generated !== []) {
            Logger::info('backup', 'Using the PHP dumper: mysqldump mishandles generated columns', [
                'count'   => count($generated),
                'example' => $generated[0],
            ]);

            return 'php';
        }

        return $this->canShellOut() ? 'mysqldump' : 'php';
    }

    /**
     * Restore from a dump produced by dump().
     *
     * Statements are split on semicolons that fall outside quoted strings and
     * comments — a naive explode(';') corrupts any row containing one.
     *
     * @return int statements executed
     */
    public function restore(string $dumpPath): int
    {
        if (!is_file($dumpPath)) {
            throw new UpdateException('Backup file not found: ' . $dumpPath);
        }

        $gzipped = str_ends_with($dumpPath, '.gz');
        $handle = $gzipped ? gzopen($dumpPath, 'rb') : fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new UpdateException('Cannot read backup file: ' . $dumpPath);
        }

        $pdo = DB::write();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $executed = 0;
        $buffer = '';
        $holdsLocks = false;

        try {
            while (!feof($handle)) {
                $line = $gzipped ? gzgets($handle) : fgets($handle);
                if ($line === false) {
                    break;
                }

                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*!')) {
                    continue;
                }

                $buffer .= $line;

                if ($this->endsWithStatement($buffer)) {
                    $statement = trim($buffer);
                    $buffer = '';
                    if ($statement === '' || $statement === ';') {
                        continue;
                    }
                    $holdsLocks = $this->locksAfter($statement, $holdsLocks);
                    $pdo->exec($statement);
                    $executed++;
                }
            }

            if (trim($buffer) !== '') {
                $statement = trim($buffer);
                $holdsLocks = $this->locksAfter($statement, $holdsLocks);
                $pdo->exec($statement);
                $executed++;
            }
        } finally {
            $this->releaseConnection($pdo, $holdsLocks);
            $gzipped ? gzclose($handle) : fclose($handle);
        }

        Logger::info('backup', 'Database restored', ['statements' => $executed, 'from' => basename($dumpPath)]);

        return $executed;
    }

    /**
     * Does the connection hold table locks once this statement has run?
     *
     * A dump written by mysqldump brackets each table in LOCK TABLES /
     * UNLOCK TABLES, so the answer changes as the restore replays.
     */
    private function locksAfter(string $statement, bool $current): bool
    {
        if (preg_match('/^UNLOCK\s+TABLES/i', $statement) === 1) {
            return false;
        }
        if (preg_match('/^LOCK\s+TABLES/i', $statement) === 1) {
            return true;
        }

        return $current;
    }

    /**
     * Put the connection back into a usable state after a restore.
     *
     * If a restore throws between a LOCK TABLES and its UNLOCK — as it does on
     * a dump this class cannot replay — the session is left holding locks, and
     * every later query on it dies with "table ... was not locked with LOCK
     * TABLES". That turns one restore failure into a rollback that cannot even
     * record why it failed.
     *
     * The unlock is issued only when locks are actually held, because
     * UNLOCK TABLES commits the caller's open transaction as a side effect and
     * a restore of a lock-free dump has no business doing that.
     *
     * @param \PDO $pdo
     */
    private function releaseConnection(\PDO $pdo, bool $holdsLocks): void
    {
        $statements = $holdsLocks ? ['UNLOCK TABLES', 'SET FOREIGN_KEY_CHECKS = 1'] : ['SET FOREIGN_KEY_CHECKS = 1'];

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                Logger::warning('backup', 'Could not reset the connection after a restore', [
                    'statement' => $statement,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    // ------------------------------------------------------------- mysqldump

    private function canShellOut(): bool
    {
        if (!function_exists('shell_exec') && !function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true) || in_array('exec', $disabled, true)) {
            return false;
        }

        $which = @shell_exec('command -v mysqldump 2>/dev/null');

        return is_string($which) && trim($which) !== '';
    }

    private function dumpWithMysqldump(string $destination, SqlDumpWriter $writer): int
    {
        // The password goes in a 0600 defaults file, never on the command line
        // where it would be visible in `ps` to every user on the box.
        $configFile = tempnam(sys_get_temp_dir(), 'mydump');
        if ($configFile === false) {
            throw new UpdateException('Cannot create a temporary credentials file.', 'BACKUP_DB');
        }
        chmod($configFile, 0600);
        file_put_contents($configFile, sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n",
            $this->host,
            $this->port,
            $this->user,
            str_replace('"', '\"', $this->password)
        ));

        try {
            // --skip-lock-tables: --single-transaction already gives InnoDB a
            // consistent snapshot, and the LOCK TABLES statements it would
            // otherwise emit are what strand a failed restore mid-lock.
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --skip-lock-tables --quick '
                . '--routines --triggers --events --add-drop-table --complete-insert '
                . '--default-character-set=utf8mb4 --no-tablespaces %s 2>&1 | gzip -6 > %s',
                escapeshellarg($configFile),
                escapeshellarg($this->database),
                escapeshellarg($destination)
            );

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new UpdateException('mysqldump exited with code ' . $exitCode . ': ' . implode(' ', array_slice($output, 0, 3)), 'BACKUP_DB');
            }
        } finally {
            @unlink($configFile);
        }

        return count($writer->tableNames());
    }

    /**
     * Does the buffer end on a statement boundary?
     *
     * Walks the string tracking quote and comment state, so a semicolon inside
     * a value does not split a statement.
     */
    private function endsWithStatement(string $buffer): bool
    {
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $escaped = false;
        $lastSignificant = '';

        $length = strlen($buffer);
        for ($i = 0; $i < $length; $i++) {
            $char = $buffer[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && ($inSingle || $inDouble)) {
                $escaped = true;
                continue;
            }
            if ($char === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
                continue;
            }
            if ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick && !ctype_space($char)) {
                $lastSignificant = $char;
            }
        }

        return !$inSingle && !$inDouble && !$inBacktick && $lastSignificant === ';';
    }
}
