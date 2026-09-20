<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;
use App\Core\UpdateException;
use PDO;

/**
 * Database dump and restore.
 *
 * mysqldump is used when the host allows shell_exec; a great many shared and
 * aaPanel setups disable it, so a complete pure-PHP dumper is the fallback
 * rather than an afterthought. Both paths produce the same restorable SQL:
 * DROP + CREATE + chunked INSERTs, plus views, triggers and routines.
 *
 * Rows are streamed with an unbuffered query and written in batches, so a
 * multi-gigabyte table does not have to fit in memory.
 */
final class DatabaseDumper
{
    private const ROWS_PER_INSERT = 200;
    private const ROWS_PER_FLUSH = 5000;

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

        $method = 'php';
        $tables = 0;

        if ($this->canShellOut()) {
            try {
                $tables = $this->dumpWithMysqldump($destination);
                $method = 'mysqldump';
            } catch (UpdateException $e) {
                Logger::warning('backup', 'mysqldump failed; falling back to the PHP dumper', ['error' => $e->getMessage()]);
                $tables = $this->dumpWithPhp($destination);
            }
        } else {
            $tables = $this->dumpWithPhp($destination);
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

        $handle = str_ends_with($dumpPath, '.gz') ? gzopen($dumpPath, 'rb') : fopen($dumpPath, 'rb');
        if ($handle === false) {
            throw new UpdateException('Cannot read backup file: ' . $dumpPath);
        }

        $pdo = DB::write();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $executed = 0;
        $buffer = '';

        try {
            while (!feof($handle)) {
                $line = str_ends_with($dumpPath, '.gz') ? gzgets($handle) : fgets($handle);
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
                    $pdo->exec($statement);
                    $executed++;
                }
            }

            if (trim($buffer) !== '') {
                $pdo->exec(trim($buffer));
                $executed++;
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            str_ends_with($dumpPath, '.gz') ? gzclose($handle) : fclose($handle);
        }

        Logger::info('backup', 'Database restored', ['statements' => $executed, 'from' => basename($dumpPath)]);

        return $executed;
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

    private function dumpWithMysqldump(string $destination): int
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
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers --events '
                . '--add-drop-table --default-character-set=utf8mb4 --no-tablespaces %s 2>&1 | gzip -6 > %s',
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

        return count($this->tableNames());
    }

    // ------------------------------------------------------------ PHP dumper

    private function dumpWithPhp(string $destination): int
    {
        $handle = gzopen($destination, 'wb6');
        if ($handle === false) {
            throw new UpdateException('Cannot open the dump file for writing: ' . $destination, 'BACKUP_DB');
        }

        $pdo = DB::write();

        try {
            $this->write($handle, "-- " . (string) Config::get('brand.name', 'Panel') . " database backup\n");
            $this->write($handle, '-- Generated ' . gmdate('c') . " UTC\n");
            $this->write($handle, '-- Database: ' . $this->database . "\n\n");
            $this->write($handle, "SET NAMES utf8mb4;\n");
            $this->write($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
            $this->write($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            $this->write($handle, "SET time_zone = '+00:00';\n\n");

            $tables = $this->tableNames();
            foreach ($tables as $table) {
                $this->dumpTableStructure($handle, $pdo, $table);
                $this->dumpTableRows($handle, $pdo, $table);
            }

            $this->dumpViews($handle, $pdo);
            $this->dumpTriggers($handle, $pdo);
            $this->dumpRoutines($handle, $pdo);

            $this->write($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");

            return count($tables);
        } finally {
            gzclose($handle);
        }
    }

    /** @return list<string> base tables only; views are emitted separately */
    private function tableNames(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            ['db' => $this->database]
        );

        return array_map(static fn (array $r): string => (string) $r['TABLE_NAME'], $rows);
    }

    /** @param resource $handle */
    private function dumpTableStructure($handle, PDO $pdo, string $table): void
    {
        $row = DB::selectOne('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
        $create = (string) ($row['Create Table'] ?? '');

        $this->write($handle, "\n-- Table: {$table}\n");
        $this->write($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
        $this->write($handle, $create . ";\n\n");
    }

    /** @param resource $handle */
    private function dumpTableRows($handle, PDO $pdo, string $table): void
    {
        // @sql-identifier A table name cannot be a bound parameter. $table
        // comes from information_schema for the configured database, never
        // from a request, and every backtick is stripped before it is quoted.
        $safeTable = '`' . str_replace('`', '', $table) . '`';

        // Unbuffered so MySQL streams rows instead of materialising the whole
        // result set in PHP's memory.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            // @sql-identifier $safeTable is a backtick-stripped identifier from
            // information_schema, not request input; a table name cannot bind.
            $statement = $pdo->query('SELECT * FROM ' . $safeTable);
            if ($statement === false) {
                return;
            }

            $batch = [];
            $columns = null;
            $written = 0;

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($columns === null) {
                    $columns = '(`' . implode('`, `', array_keys($row)) . '`)';
                }

                $values = [];
                foreach ($row as $value) {
                    $values[] = $this->quote($pdo, $value);
                }
                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $this->write($handle, 'INSERT INTO ' . $safeTable . ' ' . $columns . " VALUES\n" . implode(",\n", $batch) . ";\n");
                    $written += count($batch);
                    $batch = [];

                    if ($written % self::ROWS_PER_FLUSH === 0) {
                        Logger::debug('backup', 'Dump progress', ['table' => $table, 'rows' => $written]);
                    }
                }
            }

            if ($batch !== [] && $columns !== null) {
                $this->write($handle, 'INSERT INTO ' . $safeTable . ' ' . $columns . " VALUES\n" . implode(",\n", $batch) . ";\n");
            }
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /** @param resource $handle */
    private function dumpViews($handle, PDO $pdo): void
    {
        $views = DB::select(
            'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = :db',
            ['db' => $this->database]
        );

        foreach ($views as $view) {
            $name = (string) $view['TABLE_NAME'];
            $row = DB::selectOne('SHOW CREATE VIEW `' . str_replace('`', '', $name) . '`');
            $create = (string) ($row['Create View'] ?? '');
            if ($create === '') {
                continue;
            }
            $this->write($handle, "\n-- View: {$name}\n");
            $this->write($handle, 'DROP VIEW IF EXISTS `' . $name . "`;\n");
            // DEFINER clauses break a restore onto a server without that user.
            $this->write($handle, $this->stripDefiner($create) . ";\n");
        }
    }

    /** @param resource $handle */
    private function dumpTriggers($handle, PDO $pdo): void
    {
        $triggers = DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :db',
            ['db' => $this->database]
        );

        if ($triggers === []) {
            return;
        }

        $this->write($handle, "\nDELIMITER ;;\n");
        foreach ($triggers as $trigger) {
            $name = (string) $trigger['TRIGGER_NAME'];
            $row = DB::selectOne('SHOW CREATE TRIGGER `' . str_replace('`', '', $name) . '`');
            $create = (string) ($row['SQL Original Statement'] ?? '');
            if ($create === '') {
                continue;
            }
            $this->write($handle, 'DROP TRIGGER IF EXISTS `' . $name . "`;;\n");
            $this->write($handle, $this->stripDefiner($create) . ";;\n");
        }
        $this->write($handle, "DELIMITER ;\n");
    }

    /** @param resource $handle */
    private function dumpRoutines($handle, PDO $pdo): void
    {
        $routines = DB::select(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :db',
            ['db' => $this->database]
        );

        if ($routines === []) {
            return;
        }

        $this->write($handle, "\nDELIMITER ;;\n");
        foreach ($routines as $routine) {
            $name = (string) $routine['ROUTINE_NAME'];
            $type = strtoupper((string) $routine['ROUTINE_TYPE']) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $row = DB::selectOne('SHOW CREATE ' . $type . ' `' . str_replace('`', '', $name) . '`');
            $create = (string) ($row['Create ' . ucfirst(strtolower($type))] ?? '');
            if ($create === '') {
                continue;
            }
            $this->write($handle, 'DROP ' . $type . ' IF EXISTS `' . $name . "`;;\n");
            $this->write($handle, $this->stripDefiner($create) . ";;\n");
        }
        $this->write($handle, "DELIMITER ;\n");
    }

    private function quote(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = (string) $value;

        // Binary columns are emitted as hex literals so the dump stays valid
        // UTF-8 text and survives a round trip through gzip and a text editor.
        if (!mb_check_encoding($string, 'UTF-8')) {
            return '0x' . bin2hex($string);
        }

        return $pdo->quote($string);
    }

    private function stripDefiner(string $sql): string
    {
        return (string) preg_replace('/DEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i', '', $sql);
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

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if (gzwrite($handle, $data) === false) {
            throw new UpdateException('Failed writing to the database dump (disk full?).', 'BACKUP_DB');
        }
    }
}
