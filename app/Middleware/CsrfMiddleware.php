<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ForbiddenException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/**
 * Rejects state-changing requests without a valid CSRF token.
 *
 * API-key and device callers are exempt: they authenticate with a bearer
 * token that a browser will never attach automatically, so there is no
 * cross-site request to forge. Session-authenticated callers are never exempt,
 * including on /api paths.
 */
final class CsrfMiddleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function handle(Request $request): ?Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return null;
        }

        if (in_array(Auth::actorType(), ['api_key', 'device'], true)) {
            return null;
        }

        if (Csrf::verify(Csrf::fromRequest($request))) {
            return null;
        }

        Logger::warning('security', 'CSRF check failed', [
            'path'    => $request->path(),
            'method'  => $request->method(),
            'ip'      => $request->ip(),
            'user_id' => Auth::id(),
            'referer' => $request->header('Referer'),
        ]);

        if ($request->wantsJson()) {
            return Response::apiError(
                'Your session token is missing or expired. Reload the page and try again.',
                403,
                'csrf_failed'
            );
        }

        throw new ForbiddenException('CSRF token missing or invalid.');
    }
}
