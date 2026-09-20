<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP view renderer with layout inheritance.
 *
 * Views receive data as extracted locals. `e()` is the only sanctioned way to
 * emit a value; raw() exists but is deliberately awkward to type so it shows up
 * in review.
 */
final class View
{
    private static string $viewPath = '';
    /** @var array<string,mixed> */
    private static array $shared = [];
    /** @var array<string,string> */
    private static array $sections = [];
    private static ?string $currentSection = null;
    private static string $layout = '';

    public static function configure(string $viewPath): void
    {
        self::$viewPath = rtrim($viewPath, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @return array<string,mixed> */
    public static function sharedData(): array
    {
        return self::$shared;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        self::$sections = [];
        self::$layout = '';

        $content = self::renderFile($template, $data);

        // A view may declare a layout via layout(); render it with the captured
        // sections plus the view's own output available as $content.
        while (self::$layout !== '') {
            $layout = self::$layout;
            self::$layout = '';
            $content = self::renderFile($layout, array_merge($data, ['content' => $content]));
        }

        return $content;
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, $data);
    }

    public static function layout(string $template): void
    {
        self::$layout = $template;
    }

    public static function startSection(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    public static function endSection(): void
    {
        if (self::$currentSection === null) {
            return;
        }
        self::$sections[self::$currentSection] = (string) ob_get_clean();
        self::$currentSection = null;
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    public static function hasSection(string $name): bool
    {
        return isset(self::$sections[$name]);
    }

    /** @param array<string,mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        $file = self::$viewPath . '/' . str_replace('.', '/', $template) . '.php';
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, self::$viewPath . '/')) {
            throw new AppException('View not found or outside view root: ' . $template);
        }

        $vars = array_merge(self::$shared, $data);
        extract($vars, EXTR_SKIP);

        ob_start();
        try {
            include $real;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
