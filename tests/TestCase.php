<?php

declare(strict_types=1);

namespace Tests;

/**
 * Minimal assertion harness.
 *
 * No PHPUnit: Composer is off the table by design, and a dependency-free
 * runner keeps the suite usable on the same shared hosting the product
 * targets. It does what this codebase needs — named assertions, grouped
 * output, and an exit code CI can read.
 */
abstract class TestCase
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static int $skipped = 0;
    /** @var list<array{group:string,name:string,detail:string}> */
    private static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n" . self::colour($name, 'bold') . "\n";
        echo self::colour(str_repeat('─', min(72, strlen($name))), 'grey') . "\n";
    }

    public static function assert(bool $condition, string $name, string $detail = ''): bool
    {
        if ($condition) {
            self::$passed++;
            echo '  ' . self::colour('✓', 'green') . ' ' . $name . ($detail !== '' ? self::colour('  ' . $detail, 'grey') : '') . "\n";

            return true;
        }

        self::$failed++;
        self::$failures[] = ['group' => self::$group, 'name' => $name, 'detail' => $detail];
        echo '  ' . self::colour('✗', 'red') . ' ' . $name . ($detail !== '' ? self::colour('  ' . $detail, 'red') : '') . "\n";

        return false;
    }

    public static function assertSame(mixed $expected, mixed $actual, string $name): bool
    {
        return self::assert(
            $expected === $actual,
            $name,
            $expected === $actual ? '' : sprintf('expected %s, got %s', self::stringify($expected), self::stringify($actual))
        );
    }

    public static function assertContains(string $needle, string $haystack, string $name): bool
    {
        return self::assert(
            str_contains($haystack, $needle),
            $name,
            str_contains($haystack, $needle) ? '' : sprintf('%s not found', self::stringify($needle))
        );
    }

    public static function assertNotContains(string $needle, string $haystack, string $name): bool
    {
        return self::assert(
            !str_contains($haystack, $needle),
            $name,
            str_contains($haystack, $needle) ? sprintf('%s WAS found', self::stringify($needle)) : ''
        );
    }

    /** @param callable():mixed $callback */
    public static function assertThrows(string $exceptionClass, callable $callback, string $name): bool
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            return self::assert(
                $e instanceof $exceptionClass,
                $name,
                $e instanceof $exceptionClass ? '' : 'threw ' . $e::class . ' instead'
            );
        }

        return self::assert(false, $name, 'nothing was thrown');
    }

    public static function skip(string $name, string $reason): void
    {
        self::$skipped++;
        echo '  ' . self::colour('·', 'yellow') . ' ' . $name . self::colour('  skipped: ' . $reason, 'grey') . "\n";
    }

    public static function summary(): int
    {
        $total = self::$passed + self::$failed;

        echo "\n" . str_repeat('═', 72) . "\n";
        printf(
            "  %s passed, %s failed, %s skipped  (%d assertions)\n",
            self::colour((string) self::$passed, 'green'),
            self::$failed > 0 ? self::colour((string) self::$failed, 'red') : '0',
            (string) self::$skipped,
            $total
        );

        if (self::$failures !== []) {
            echo "\n" . self::colour('  Failures:', 'red') . "\n";
            foreach (self::$failures as $failure) {
                printf("    [%s] %s%s\n", $failure['group'], $failure['name'],
                    $failure['detail'] !== '' ? ' — ' . $failure['detail'] : '');
            }
        }

        echo "\n";

        return self::$failed === 0 ? 0 : 1;
    }

    public static function passed(): int
    {
        return self::$passed;
    }

    public static function failed(): int
    {
        return self::$failed;
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . (strlen($value) > 60 ? substr($value, 0, 57) . '…' : $value) . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        return (string) $value;
    }

    private static function colour(string $text, string $colour): string
    {
        static $supported = null;
        if ($supported === null) {
            $supported = function_exists('posix_isatty') && @posix_isatty(STDOUT) && getenv('NO_COLOR') === false;
        }
        if (!$supported) {
            return $text;
        }

        $codes = ['red' => '0;31', 'green' => '0;32', 'yellow' => '0;33', 'grey' => '0;90', 'bold' => '1'];

        return "\033[" . ($codes[$colour] ?? '0') . 'm' . $text . "\033[0m";
    }
}
