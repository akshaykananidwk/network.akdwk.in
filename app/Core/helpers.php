<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\View;

/**
 * Global helpers available inside views and controllers. Kept deliberately
 * small — anything with logic belongs in a Core class or a Service.
 */

if (!function_exists('e')) {
    /** Escape for HTML output. The default for every value rendered in a view. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** Escape for an unquoted-ish attribute context (data-* payloads, JSON). */
    function e_attr(mixed $value): string
    {
        return htmlspecialchars(
            is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}

if (!function_exists('e_js')) {
    /** Safe JSON for a <script> block: no closing-tag or HTML-comment escape. */
    function e_js(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('brand')) {
    /** Tenant branding overrides the global brand; never hardcode either. */
    function brand(string $key = 'name', mixed $default = null): mixed
    {
        $tenantBrand = View::sharedData()['tenant_branding'] ?? [];
        if (is_array($tenantBrand) && isset($tenantBrand[$key]) && $tenantBrand[$key] !== '' && $tenantBrand[$key] !== null) {
            return $tenantBrand[$key];
        }

        return Config::get('brand.' . $key, $default);
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route')) {
    /** @param array<string,string|int> $params */
    function route(string $name, array $params = []): string
    {
        $router = Config::get('runtime.router');

        return $router instanceof Router ? $router->url($name, $params) : '/';
    }
}

if (!function_exists('asset')) {
    /** Cache-busted asset URL — the updater bumps APP_VERSION, browsers refetch. */
    function asset(string $path): string
    {
        $base = rtrim((string) Config::get('app.base_path', ''), '/');

        return $base . '/assets/' . ltrim($path, '/') . '?v=' . rawurlencode((string) Config::get('app.version', '0'));
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string,mixed>|null */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('__')) {
    /** @param array<string,string|int> $replace */
    function __(string $key, array $replace = []): string
    {
        return \App\Core\Lang::get($key, $replace);
    }
}

if (!function_exists('local_time')) {
    /** Timestamps are stored UTC and rendered in the viewer's timezone. */
    function local_time(?string $utc, string $format = 'd M Y, H:i'): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            $tz = (string) (View::sharedData()['timezone'] ?? Config::get('app.timezone', 'UTC'));

            return $dt->setTimezone(new DateTimeZone($tz))->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'never';
        }
        try {
            $then = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return 'never';
        }
        $seconds = time() - $then->getTimestamp();
        if ($seconds < 0) {
            return 'just now';
        }
        if ($seconds < 60) {
            return $seconds . 's ago';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ago';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ago';
        }

        return intdiv($seconds, 86400) . 'd ago';
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes(int|float $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max((float) $bytes, 0);
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }
}

if (!function_exists('old')) {
    /** Redisplay a rejected form without losing what the user typed. */
    function old(string $key, string $default = ''): string
    {
        $old = View::sharedData()['old'] ?? [];

        return is_array($old) && isset($old[$key]) && is_scalar($old[$key]) ? (string) $old[$key] : $default;
    }
}

if (!function_exists('field_error')) {
    function field_error(string $key): string
    {
        $errors = View::sharedData()['errors'] ?? [];

        return is_array($errors) && isset($errors[$key]) ? (string) $errors[$key] : '';
    }
}
