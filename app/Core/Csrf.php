<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Double-submit CSRF protection.
 *
 * A per-session secret is kept server-side; each rendered token is
 * random_nonce + HMAC(nonce, secret). Tokens are therefore unguessable and
 * verifiable without keeping a growing list of issued tokens, and rotating the
 * session (on login) invalidates every outstanding token at once.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_secret';
    private const FIELD = '_token';
    private const HEADER = 'X-CSRF-Token';

    public static function token(): string
    {
        $secret = self::secret();
        $nonce = bin2hex(random_bytes(16));
        $mac = hash_hmac('sha256', $nonce, $secret);

        return $nonce . '.' . $mac;
    }

    public static function verify(?string $token): bool
    {
        if ($token === null || !str_contains($token, '.')) {
            return false;
        }
        [$nonce, $mac] = explode('.', $token, 2);
        if ($nonce === '' || $mac === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $nonce, self::secret());

        return hash_equals($expected, $mac);
    }

    /** Pull the token from the form field or the AJAX header. */
    public static function fromRequest(Request $request): ?string
    {
        return $request->input(self::FIELD) ?? $request->header(self::HEADER);
    }

    public static function fieldName(): string
    {
        return self::FIELD;
    }

    public static function headerName(): string
    {
        return self::HEADER;
    }

    private static function secret(): string
    {
        $secret = Session::get(self::SESSION_KEY);
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $secret);
        }

        return $secret;
    }
}
