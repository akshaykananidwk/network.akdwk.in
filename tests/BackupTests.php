<?php

declare(strict_types=1);

namespace Tests;

use App\Core\DB;
use App\Updater\DatabaseDumper;
use App\Updater\SqlDumpWriter;

/**
 * Backup dump and restore (§20.G).
 *
 * These are regression tests for three faults found by running a real rollback
 * against a live MariaDB, each of which made a backup unrestorable:
 *
 *   1. mysqldump writes STORED generated columns into its INSERT statements —
 *      with or without --complete-insert — and the restore then dies with
 *      error 1906. A backup you cannot restore is not a backup.
 *   2. The PHP dumper had the same flaw: it selected * and listed every column
 *      it got back.
 *   3. A restore that failed between LOCK TABLES and UNLOCK TABLES left the
 *      connection holding locks, so the rollback could not even record why it
 *      had failed.
 *
 * The probe table is created and dropped by the suite, so it runs against the
 * live test database without disturbing anything else in it.
 */
final class BackupTests
{
    /**
     * Unique per run: two suites running at once against the same database
     * must not collide on the probe table, or one drops the other's out from
     * under it and the failure looks like a dumper bug.
     */
    private static string $probe = '';

    public static function run(): void
    {
        self::$probe = 'zz_backup_probe_' . bin2hex(random_bytes(4));

        self::createProbe();

        try {
            $dump = self::dumpExcludesGeneratedColumns();
            self::dumpRestoresCleanly($dump);
            self::restoreReleasesTableLocks();
        } finally {
            DB::write()->exec('DROP TABLE IF EXISTS `' . self::$probe . '`');
            foreach (glob(sys_get_temp_dir() . '/backup-probe-*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    /**
     * A table shaped like the ones that broke: a STORED generated column, and
     * values carrying the characters that defeat a naive dumper.
     */
    private static function createProbe(): void
    {
        $pdo = DB::write();
        $pdo->exec('DROP TABLE IF EXISTS `' . self::$probe . '`');
        $pdo->exec(
            'CREATE TABLE `' . self::$probe . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` INT UNSIGNED NULL,
                `key_name` VARCHAR(100) NOT NULL,
                `payload` TEXT NULL,
                `tenant_key` VARCHAR(160)
                    AS (CONCAT(IFNULL(`tenant_id`, 0), \':\', `key_name`)) STORED,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_probe_tenant_key` (`tenant_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $insert = $pdo->prepare(
            'INSERT INTO `' . self::$probe . '` (`tenant_id`, `key_name`, `payload`) VALUES (?, ?, ?)'
        );

        // NULL tenant_id is the case the generated column exists to handle.
        $insert->execute([null, 'platform.setting', "a ';' inside a value"]);
        $insert->execute([1, 'quote.single', "O'Brien said \"hi\""]);
        $insert->execute([1, 'newline', "line one\nline two\r\nline three"]);
        $insert->execute([2, 'backslash', 'C:\\path\\to\\file -- not a comment']);
        $insert->execute([2, 'unicode', 'સ્વાગત છે — 中文 — emoji 🔐']);
        $insert->execute([3, 'empty', '']);
        $insert->execute([3, 'null.payload', null]);
    }

    /** @return string path to the dump */
    private static function dumpExcludesGeneratedColumns(): string
    {
        TestCase::group('Backup — generated columns are never written back');

        $writer = new SqlDumpWriter((string) \App\Core\Config::get('db.name'));

        $generated = $writer->generatedColumns();
        TestCase::assert(
            in_array(self::$probe . '.tenant_key', $generated, true),
            'the dumper sees the probe\'s generated column',
            count($generated) . ' generated column(s) in this schema'
        );

        $path = tempnam(sys_get_temp_dir(), 'backup-probe-') . '.sql.gz';
        $writer->writeTo($path);

        $sql = (string) gzdecode((string) file_get_contents($path));

        $inserts = self::insertsFor($sql, self::$probe);
        TestCase::assert($inserts !== [], 'the probe\'s rows were dumped', count($inserts) . ' INSERT statement(s)');

        $listsGenerated = false;
        foreach ($inserts as $statement) {
            $columnList = substr($statement, 0, (int) strpos($statement, 'VALUES'));
            if (str_contains($columnList, 'tenant_key')) {
                $listsGenerated = true;
            }
        }
        TestCase::assert(!$listsGenerated,
            'no INSERT lists the generated column (regression: error 1906)');

        // An explicit column list, not a bare INSERT INTO t VALUES, is what
        // makes that guarantee hold when the schema later gains a column.
        TestCase::assertContains('`id`, `tenant_id`, `key_name`, `payload`', $inserts[0],
            'the INSERT names its columns explicitly');

        // And the awkward values survived the trip out.
        TestCase::assertContains('🔐', $sql, 'multi-byte UTF-8 is dumped intact');

        // On a schema with generated columns, mysqldump is not an option.
        $method = DatabaseDumper::fromConfig()->dump(
            tempnam(sys_get_temp_dir(), 'backup-probe-') . '.sql.gz'
        )['method'];
        TestCase::assertSame('php', $method,
            'a schema with generated columns always uses the PHP dumper');

        return $path;
    }

    /**
     * The real proof: drop the table and put it back from the dump.
     *
     * Only the probe's own section is replayed, so the rest of the test
     * database is untouched — but it goes through the production
     * DatabaseDumper::restore() parser, semicolons in values and all.
     */
    private static function dumpRestoresCleanly(string $dumpPath): void
    {
        TestCase::group('Backup — a dump restores without losing a row');

        $sql = (string) gzdecode((string) file_get_contents($dumpPath));
        $section = self::sectionFor($sql, self::$probe);
        TestCase::assert($section !== '', 'the probe\'s section was found in the dump');

        $before = DB::select('SELECT * FROM `' . self::$probe . '` ORDER BY id');

        $sectionPath = tempnam(sys_get_temp_dir(), 'backup-probe-') . '.sql';
        file_put_contents($sectionPath, $section);

        DB::write()->exec('DROP TABLE `' . self::$probe . '`');

        $executed = DatabaseDumper::fromConfig()->restore($sectionPath);
        TestCase::assert($executed > 0, 'the restore ran', $executed . ' statement(s)');

        $after = DB::select('SELECT * FROM `' . self::$probe . '` ORDER BY id');

        TestCase::assertSame(count($before), count($after), 'every row came back');
        TestCase::assertSame($before, $after, 'and every value, generated column included, is identical');

        // The generated column was recomputed by the server, not restored from
        // the dump — which is precisely why it must not be in the INSERT.
        $platform = DB::selectOne(
            'SELECT tenant_key FROM `' . self::$probe . '` WHERE key_name = :k',
            ['k' => 'platform.setting']
        );
        TestCase::assertSame('0:platform.setting', $platform['tenant_key'] ?? null,
            'a NULL tenant still generates its composite key');
    }

    /**
     * A restore that dies mid-lock must not poison the connection.
     *
     * This is the fault that turned one failed restore into a rollback that
     * could not write its own status row: every later query answered
     * "table ... was not locked with LOCK TABLES".
     */
    private static function restoreReleasesTableLocks(): void
    {
        TestCase::group('Backup — a failed restore releases its table locks');

        $path = tempnam(sys_get_temp_dir(), 'backup-probe-') . '.sql';
        file_put_contents($path, implode("\n", [
            'LOCK TABLES `' . self::$probe . '` WRITE;',
            'INSERT INTO `' . self::$probe . '` (`id`, `key_name`) VALUES (900, \'locked\');',
            // A statement the restore cannot survive, thrown while locks are held.
            'INSERT INTO `' . self::$probe . '` (`no_such_column`) VALUES (1);',
            'UNLOCK TABLES;',
        ]) . "\n");

        TestCase::assertThrows(\PDOException::class,
            static fn () => DatabaseDumper::fromConfig()->restore($path),
            'the broken dump fails loudly rather than silently');

        // The connection must still be usable — this is the regression.
        $usable = true;
        $error = '';
        try {
            DB::scalar('SELECT COUNT(*) FROM ' . DB::table('app_updates'));
        } catch (\Throwable $e) {
            $usable = false;
            $error = $e->getMessage();
        }

        TestCase::assert($usable,
            'an unrelated table is queryable after the failure', $error);
        TestCase::assertNotContains('was not locked', $error,
            'and no lock is still held');
    }

    /**
     * Every INSERT statement in the dump that targets one table.
     *
     * @return list<string>
     */
    private static function insertsFor(string $sql, string $table): array
    {
        $found = [];
        foreach (explode("\nINSERT INTO ", "\n" . $sql) as $chunk) {
            if (str_starts_with($chunk, '`' . $table . '`')) {
                $found[] = 'INSERT INTO ' . $chunk;
            }
        }

        return $found;
    }

    /** The DROP + CREATE + INSERTs for one table, as the dumper wrote them. */
    private static function sectionFor(string $sql, string $table): string
    {
        $marker = "\n-- Table: {$table}\n";
        $start = strpos($sql, $marker);
        if ($start === false) {
            return '';
        }

        $start += strlen($marker);
        $end = strpos($sql, "\n-- ", $start);

        return $end === false ? substr($sql, $start) : substr($sql, $start, $end - $start);
    }
}
