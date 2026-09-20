<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Flat-key translation loader. Keys read "section.key"; a missing key returns
 * the key itself so a gap is visible in the UI rather than blank.
 */
final class Lang
{
    /** @var array<string,string> */
    private static array $messages = [];
    private static string $locale = 'en';
    private static string $langPath = '';

    public static function configure(string $langPath, string $locale = 'en'): void
    {
        self::$langPath = rtrim($langPath, '/');
        self::setLocale($locale);
    }

    public static function setLocale(string $locale): void
    {
        $locale = preg_replace('/[^a-z_\-]/i', '', $locale) ?: 'en';
        $file = self::$langPath . '/' . $locale . '.php';
        if (!is_file($file)) {
            $locale = 'en';
            $file = self::$langPath . '/en.php';
        }
        self::$locale = $locale;
        self::$messages = is_file($file) ? (array) require $file : [];
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @return list<string> */
    public static function available(): array
    {
        $out = [];
        foreach (glob(self::$langPath . '/*.php') ?: [] as $file) {
            $out[] = basename($file, '.php');
        }

        return $out;
    }

    /** @param array<string,string|int> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $message = self::$messages[$key] ?? $key;
        foreach ($replace as $search => $value) {
            $message = str_replace(':' . $search, (string) $value, $message);
        }

        return $message;
    }
}
