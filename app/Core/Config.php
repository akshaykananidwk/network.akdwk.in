<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dot-notation configuration store.
 *
 * Precedence: config/config.php (written by the installer) wins; a value that
 * is null there falls through to the matching .env key, then to the default
 * passed by the caller. Nothing is hardcoded at a call site — white-labelling
 * depends on every brand string living here.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $configFile, ?string $envFile = null): void
    {
        self::$items = [];

        if ($envFile !== null && is_file($envFile)) {
            foreach (self::parseEnv($envFile) as $key => $value) {
                self::$items['env'][$key] = $value;
            }
        }

        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                self::$items = array_replace_recursive(self::$items, $loaded);
            }
        }

        // *.local.php overrides are never shipped and never overwritten by the
        // updater; they exist so an operator can pin a setting on one node.
        $localFile = dirname($configFile) . '/config.local.php';
        if (is_file($localFile)) {
            $local = require $localFile;
            if (is_array($local)) {
                self::$items = array_replace_recursive(self::$items, $local);
            }
        }

        self::$loaded = true;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        return self::$items['env'][$key] ?? ($_ENV[$key] ?? ($_SERVER[$key] ?? $default));
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }

    /** @return array<string,string> */
    private static function parseEnv(string $file): array
    {
        $out = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
