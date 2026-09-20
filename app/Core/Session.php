<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Static facade over the PHP session, backed by DbSessionHandler.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = session_status() === PHP_SESSION_ACTIVE;

            return;
        }

        $secure = Request::current()?->isSecure() ?? false;

        session_set_save_handler(new DbSessionHandler(), true);
        session_name((string) Config::get('session.name', 'ak_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim((string) Config::get('app.base_path', ''), '/') . '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) Config::get('session.lifetime_minutes', 720) * 60));

        session_start();
        self::$started = true;

        // Idle timeout is enforced server-side; the cookie alone is not trusted.
        $idleLimit = (int) Config::get('session.idle_minutes', 240) * 60;
        $last = (int) ($_SESSION['_last_activity'] ?? time());
        if ($idleLimit > 0 && (time() - $last) > $idleLimit) {
            self::destroy();
            session_start();
            self::$started = true;
        }
        $_SESSION['_last_activity'] = time();
    }

    public static function started(): bool
    {
        return self::$started;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Write a one-shot value (toast, form errors) or read-and-clear one. */
    public static function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;

            return null;
        }
        $stored = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return $stored;
    }

    public static function regenerate(): void
    {
        if (self::$started) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (!self::$started) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }

    public static function id(): string
    {
        return self::$started ? (string) session_id() : '';
    }
}
