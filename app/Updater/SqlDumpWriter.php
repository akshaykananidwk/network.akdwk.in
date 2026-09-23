<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\Config;
use App\Core\DB;
use App\Core\Logger;
use App\Core\UpdateException;
use PDO;

/**
 * The pure-PHP database dumper.
 *
 * A great many shared and aaPanel hosts disable shell_exec, so this is a
 * complete dumper rather than a degraded fallback: DROP + CREATE + chunked
 * INSERTs, then views, triggers and routines. Rows are streamed with an
 * unbuffered query and written in batches, so a multi-gigabyte table does not
 * have to fit in memory.
 *
 * It is also the only correct path for a schema with generated columns; see
 * generatedColumns().
 */
final class SqlDumpWriter
{
    /** The last line of a complete dump. See writeTo(). */
    public const END_MARKER = '-- BACKUP COMPLETE, TABLES:';

    private const ROWS_PER_INSERT = 200;
    private const ROWS_PER_FLUSH = 5000;

    public function __construct(private readonly string $database)
    {
    }

    /**
     * Columns the server computes for itself.
     *
     * These must never appear in an INSERT: MySQL and MariaDB reject an
     * explicit value for a STORED generated column outright (error 1906), so a
     * dump that lists one cannot be restored at all.
     *
     * @return list<string> "table.column"
     */
    public function generatedColumns(): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND EXTRA LIKE '%GENERATED%'
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
            ['db' => $this->database]
        );

        return array_map(
            static fn (array $r): string => $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'],
            $rows
        );
    }

    /**
     * Write the whole database to an open gzip stream.
     *
     * @return int tables written
     */
    public function writeTo(string $destination): int
    {
        $handle = gzopen($destination, 'wb6');
        if ($handle === false) {
            throw new UpdateException('Cannot open the dump file for writing: ' . $destination, 'BACKUP_DB');
        }

        $pdo = DB::write();

        try {
            $this->put($handle, '-- ' . (string) Config::get('brand.name', 'Panel') . " database backup\n");
            $this->put($handle, '-- Generated ' . gmdate('c') . " UTC\n");
            $this->put($handle, '-- Database: ' . $this->database . "\n\n");
            $this->put($handle, "SET NAMES utf8mb4;\n");
            $this->put($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
            $this->put($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            $this->put($handle, "SET time_zone = '+00:00';\n\n");

            $tables = $this->tableNames();
            foreach ($tables as $table) {
                $this->dumpTableStructure($handle, $table);
                $this->dumpTableRows($handle, $pdo, $table);
            }

            $this->dumpViews($handle);
            $this->dumpTriggers($handle);
            $this->dumpRoutines($handle);

            $this->put($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");

            // An unambiguous end marker. Without one, a dump truncated by a
            // full disk is indistinguishable from a complete one: it is still
            // valid gzip, it still hashes consistently with itself, and the
            // missing tail only shows up when someone tries to restore it.
            $this->put($handle, sprintf("%s %d\n", self::END_MARKER, count($tables)));

            return count($tables);
        } finally {
            gzclose($handle);
        }
    }

    /** @return list<string> base tables only; views are emitted separately */
    public function tableNames(): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            ['db' => $this->database]
        );

        return array_map(static fn (array $r): string => (string) $r['TABLE_NAME'], $rows);
    }

    /**
     * Columns of one table that a restore is allowed to write, in declaration
     * order. Generated columns are excluded.
     *
     * @return list<string>
     */
    private function writableColumns(string $table): array
    {
        $rows = DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND EXTRA NOT LIKE '%GENERATED%'
             ORDER BY ORDINAL_POSITION",
            ['db' => $this->database, 't' => $table]
        );

        return array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], $rows);
    }

    /** @param resource $handle */
    private function dumpTableStructure($handle, string $table): void
    {
        $row = DB::selectOne('SHOW CREATE TABLE ' . $this->ident($table));
        $create = (string) ($row['Create Table'] ?? '');

        $this->put($handle, "\n-- Table: {$table}\n");
        $this->put($handle, 'DROP TABLE IF EXISTS ' . $this->ident($table) . ";\n");
        $this->put($handle, $create . ";\n\n");
    }

    /** @param resource $handle */
    private function dumpTableRows($handle, PDO $pdo, string $table): void
    {
        $safeTable = $this->ident($table);

        $writable = $this->writableColumns($table);
        if ($writable === []) {
            return;
        }

        $columnList = implode(', ', array_map($this->ident(...), $writable));
        $columns = '(' . $columnList . ')';

        // Unbuffered so MySQL streams rows instead of materialising the whole
        // result set in PHP's memory.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            // @sql-identifier Both the table and the column list are identifiers
            // read from information_schema for the configured database, never
            // from a request; an identifier cannot be a bound parameter, and
            // every backtick is stripped before it is quoted.
            $statement = $pdo->query('SELECT ' . $columnList . ' FROM ' . $safeTable);
            if ($statement === false) {
                return;
            }

            $batch = [];
            $written = 0;

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = $this->quote($pdo, $value);
                }
                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $this->putInsert($handle, $safeTable, $columns, $batch);
                    $written += count($batch);
                    $batch = [];

                    if ($written % self::ROWS_PER_FLUSH === 0) {
                        Logger::debug('backup', 'Dump progress', ['table' => $table, 'rows' => $written]);
                    }
                }
            }

            if ($batch !== []) {
                $this->putInsert($handle, $safeTable, $columns, $batch);
            }
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * @param resource     $handle
     * @param list<string> $batch
     */
    private function putInsert($handle, string $safeTable, string $columns, array $batch): void
    {
        $this->put(
            $handle,
            'INSERT INTO ' . $safeTable . ' ' . $columns . " VALUES\n" . implode(",\n", $batch) . ";\n"
        );
    }

    /** @param resource $handle */
    private function dumpViews($handle): void
    {
        $views = DB::select(
            'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = :db',
            ['db' => $this->database]
        );

        foreach ($views as $view) {
            $name = (string) $view['TABLE_NAME'];
            $row = DB::selectOne('SHOW CREATE VIEW ' . $this->ident($name));
            $create = (string) ($row['Create View'] ?? '');
            if ($create === '') {
                continue;
            }
            $this->put($handle, "\n-- View: {$name}\n");
            $this->put($handle, 'DROP VIEW IF EXISTS ' . $this->ident($name) . ";\n");
            // DEFINER clauses break a restore onto a server without that user.
            $this->put($handle, $this->stripDefiner($create) . ";\n");
        }
    }

    /** @param resource $handle */
    private function dumpTriggers($handle): void
    {
        $triggers = DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :db',
            ['db' => $this->database]
        );

        if ($triggers === []) {
            return;
        }

        $this->put($handle, "\nDELIMITER ;;\n");
        foreach ($triggers as $trigger) {
            $name = (string) $trigger['TRIGGER_NAME'];
            $row = DB::selectOne('SHOW CREATE TRIGGER ' . $this->ident($name));
            $create = (string) ($row['SQL Original Statement'] ?? '');
            if ($create === '') {
                continue;
            }
            $this->put($handle, 'DROP TRIGGER IF EXISTS ' . $this->ident($name) . ";;\n");
            $this->put($handle, $this->stripDefiner($create) . ";;\n");
        }
        $this->put($handle, "DELIMITER ;\n");
    }

    /** @param resource $handle */
    private function dumpRoutines($handle): void
    {
        $routines = DB::select(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :db',
            ['db' => $this->database]
        );

        if ($routines === []) {
            return;
        }

        $this->put($handle, "\nDELIMITER ;;\n");
        foreach ($routines as $routine) {
            $name = (string) $routine['ROUTINE_NAME'];
            $type = strtoupper((string) $routine['ROUTINE_TYPE']) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $row = DB::selectOne('SHOW CREATE ' . $type . ' ' . $this->ident($name));
            $create = (string) ($row['Create ' . ucfirst(strtolower($type))] ?? '');
            if ($create === '') {
                continue;
            }
            $this->put($handle, 'DROP ' . $type . ' IF EXISTS ' . $this->ident($name) . ";;\n");
            $this->put($handle, $this->stripDefiner($create) . ";;\n");
        }
        $this->put($handle, "DELIMITER ;\n");
    }

    /**
     * Quote an identifier read from information_schema.
     *
     * @sql-identifier Names come from the configured database's catalogue, not
     * from request input, and a backtick cannot survive the strip.
     */
    private function ident(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
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

    /** @param resource $handle */
    private function put($handle, string $data): void
    {
        // A short write, not just false: gzwrite reports how much it managed,
        // and on a full disk that is a positive number smaller than asked for.
        // Treating only false as failure is how a truncated dump gets written
        // and then reported as a successful backup.
        $written = gzwrite($handle, $data);

        if ($written === false || $written < strlen($data)) {
            throw new UpdateException('Failed writing to the database dump (disk full?).', 'BACKUP_DB');
        }
    }
}
