<?php

declare(strict_types=1);

namespace App\Updater;

use App\Core\DB;
use App\Core\Logger;
use App\Core\UpdateException;
use App\Models\MigrationRecord;

/**
 * Applies schema migrations in filename order.
 *
 * Both file types are supported:
 *   *.sql — statements split on real boundaries, wrapped in a transaction when
 *           the file contains no DDL (MySQL commits implicitly on DDL, so
 *           wrapping a CREATE TABLE gives false confidence)
 *   *.php — returns ['up' => callable, 'down' => callable]; down() makes the
 *           migration reversible during a rollback
 *
 * Re-running is a no-op: the ledger's unique index on filename is the
 * authority, and a file whose checksum no longer matches what was applied is
 * reported rather than silently re-run.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly string $migrationsPath,
        private readonly string $tablePrefix = '',
    ) {
    }

    /**
     * Migrations present on disk but not yet applied.
     *
     * @return list<string> filenames, in order
     */
    public function pending(): array
    {
        $applied = MigrationRecord::appliedFilenames();

        return array_values(array_filter(
            $this->available(),
            static fn (string $file): bool => !in_array($file, $applied, true)
        ));
    }

    /** @return list<string> every migration file on disk, sorted */
    public function available(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->migrationsPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($extension === 'sql' || $extension === 'php') {
                $files[] = $entry;
            }
        }

        // Filenames are timestamp-prefixed, so a plain sort is the right order.
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Report migrations whose file changed after being applied.
     *
     * Editing an applied migration is how a schema quietly diverges between
     * environments; surfacing it in the precheck is cheap.
     *
     * @return list<array{filename:string,recorded:string,current:string}>
     */
    public function driftedMigrations(): array
    {
        $drifted = [];

        foreach (MigrationRecord::appliedFilenames() as $filename) {
            $path = $this->migrationsPath . '/' . $filename;
            if (!is_file($path)) {
                continue; // a removed migration is not drift; it is history
            }

            $record = MigrationRecord::findByFilename($filename);
            if ($record === null) {
                continue;
            }

            $current = (string) hash_file('sha256', $path);
            $recorded = (string) $record['checksum'];

            if ($recorded !== '' && !hash_equals($recorded, $current)) {
                $drifted[] = ['filename' => $filename, 'recorded' => $recorded, 'current' => $current];
            }
        }

        return $drifted;
    }

    /**
     * Run every pending migration.
     *
     * @param list<string>|null $only restrict to these filenames (the manifest's list)
     * @return array{applied:list<string>,skipped:list<string>,batch:int,elapsed_ms:int}
     * @throws UpdateException on the first failure — the caller rolls back
     */
    public function run(?array $only = null): array
    {
        $pending = $this->pending();

        if ($only !== null) {
            // The manifest names what this release expects; anything else on
            // disk is left for its own release to apply.
            $pending = array_values(array_filter(
                $pending,
                static fn (string $file): bool => in_array($file, $only, true)
            ));

            foreach ($only as $named) {
                if (!in_array($named, $this->available(), true)) {
                    throw new UpdateException(
                        'The manifest names a migration that is not in this release: ' . $named,
                        'MIGRATE'
                    );
                }
            }
        }

        if ($pending === []) {
            return ['applied' => [], 'skipped' => [], 'batch' => 0, 'elapsed_ms' => 0];
        }

        $batch = MigrationRecord::nextBatch();
        $applied = [];
        $started = microtime(true);

        foreach ($pending as $filename) {
            $path = $this->migrationsPath . '/' . $filename;
            $checksum = (string) hash_file('sha256', $path);
            $fileStarted = microtime(true);

            try {
                str_ends_with(strtolower($filename), '.php')
                    ? $this->runPhpMigration($path, 'up')
                    : $this->runSqlMigration($path);

                $elapsed = (int) round((microtime(true) - $fileStarted) * 1000);
                MigrationRecord::record($filename, $batch, $checksum, $elapsed, true);
                $applied[] = $filename;

                Logger::info('migration', 'Migration applied', ['file' => $filename, 'ms' => $elapsed, 'batch' => $batch]);
            } catch (\Throwable $e) {
                $elapsed = (int) round((microtime(true) - $fileStarted) * 1000);
                MigrationRecord::record($filename, $batch, $checksum, $elapsed, false, $e->getMessage());

                Logger::error('migration', 'Migration failed', ['file' => $filename, 'error' => $e->getMessage()]);

                throw new UpdateException(
                    sprintf('Migration %s failed: %s', $filename, $e->getMessage()),
                    'MIGRATE'
                );
            }
        }

        return [
            'applied'    => $applied,
            'skipped'    => [],
            'batch'      => $batch,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Reverse migrations applied during a failed update.
     *
     * Only .php migrations can define down(); a .sql migration is not
     * reversible, which is why the database is restored from the dump rather
     * than relying on this alone. This exists to unwind the reversible part
     * cleanly before that heavier step.
     *
     * @param list<string> $filenames newest first is handled internally
     * @return array{reversed:list<string>,irreversible:list<string>}
     */
    public function rollback(array $filenames): array
    {
        $reversed = [];
        $irreversible = [];

        foreach (array_reverse($filenames) as $filename) {
            $path = $this->migrationsPath . '/' . $filename;

            if (!is_file($path) || !str_ends_with(strtolower($filename), '.php')) {
                $irreversible[] = $filename;
                continue;
            }

            try {
                if ($this->runPhpMigration($path, 'down')) {
                    MigrationRecord::forget($filename);
                    $reversed[] = $filename;
                    Logger::info('migration', 'Migration reversed', ['file' => $filename]);
                } else {
                    $irreversible[] = $filename;
                }
            } catch (\Throwable $e) {
                $irreversible[] = $filename;
                Logger::error('migration', 'Migration rollback failed', ['file' => $filename, 'error' => $e->getMessage()]);
            }
        }

        return ['reversed' => $reversed, 'irreversible' => $irreversible];
    }

    // ------------------------------------------------------------- internals

    private function runSqlMigration(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new UpdateException('Cannot read migration: ' . basename($path), 'MIGRATE');
        }

        $sql = str_replace('__PREFIX__', $this->tablePrefix, $sql);
        $statements = self::splitStatements($sql);

        if ($statements === []) {
            return;
        }

        // MySQL commits implicitly on DDL, so a transaction around a file that
        // contains any would silently do nothing. Wrap only pure-DML files,
        // where the guarantee is real.
        $hasDdl = false;
        foreach ($statements as $statement) {
            if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\s/i', $statement) === 1) {
                $hasDdl = true;
                break;
            }
        }

        $pdo = DB::write();

        if ($hasDdl) {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            return;
        }

        DB::transaction(static function () use ($statements, $pdo): void {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
        });
    }

    /** @return bool false when the requested direction is not defined */
    private function runPhpMigration(string $path, string $direction): bool
    {
        $migration = require $path;

        if (!is_array($migration) || !isset($migration[$direction])) {
            if ($direction === 'up') {
                throw new UpdateException(
                    'PHP migration ' . basename($path) . ' must return an array with an "up" callable.',
                    'MIGRATE'
                );
            }

            return false;
        }

        $callable = $migration[$direction];
        if (!is_callable($callable)) {
            throw new UpdateException('Migration ' . basename($path) . ' has a non-callable "' . $direction . '".', 'MIGRATE');
        }

        $callable(DB::write(), $this->tablePrefix);

        return true;
    }

    /**
     * Split a SQL file into statements.
     *
     * Handles quoted strings, backtick identifiers, `--`, `#` and `/* *\/`
     * comments, and DELIMITER blocks (needed for triggers and routines).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $delimiter = ';';

        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // DELIMITER directive — a client-side instruction, not SQL.
            if ($current === '' && stripos(substr($sql, $i, 10), 'DELIMITER ') === 0) {
                $lineEnd = strpos($sql, "\n", $i);
                $lineEnd = $lineEnd === false ? $length : $lineEnd;
                $delimiter = trim(substr($sql, $i + 10, $lineEnd - $i - 10));
                $i = $lineEnd + 1;
                continue;
            }

            // Line comments.
            if (($char === '-' && substr($sql, $i, 3) === '-- ') || $char === '#') {
                $lineEnd = strpos($sql, "\n", $i);
                $i = $lineEnd === false ? $length : $lineEnd + 1;
                continue;
            }

            // Block comments — but /*! ... */ is a MySQL conditional that must
            // be kept, since it carries real directives.
            if ($char === '/' && substr($sql, $i, 2) === '/*' && substr($sql, $i, 3) !== '/*!') {
                $end = strpos($sql, '*/', $i);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            // Quoted regions are copied verbatim.
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $inner = $sql[$i];
                    $current .= $inner;
                    if ($inner === '\\' && $quote !== '`' && $i + 1 < $length) {
                        $current .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    if ($inner === $quote) {
                        break;
                    }
                }
                continue;
            }

            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i += strlen($delimiter);
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
