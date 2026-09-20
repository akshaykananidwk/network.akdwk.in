<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Applies security response headers.
 *
 * The CSP is the interesting one: no 'unsafe-eval' anywhere, and inline
 * scripts are permitted only via a per-request nonce, which is what lets the
 * views pass server data to JavaScript without opening the door to injected
 * script.
 */
final class SecurityHeadersMiddleware
{
    private static string $nonce = '';

    public static function nonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = base64_encode(random_bytes(16));
        }

        return self::$nonce;
    }

    public static function apply(Response $response, Request $request): Response
    {
        $nonce = self::nonce();

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Tailwind's utility classes are compiled into a local stylesheet,
            // but component state still sets inline styles (progress bars,
            // meters), so style-src needs 'unsafe-inline'. script-src does not.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $response
            ->header('Content-Security-Policy', $csp)
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()')
            ->header('X-Request-Id', Request::id());

        if ($request->isSecure()) {
            $maxAge = (int) Config::get('security.hsts_max_age', 31536000);
            $response->header('Strict-Transport-Security', 'max-age=' . $maxAge . '; includeSubDomains');
        }

        return $response;
    }
}
