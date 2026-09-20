<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Route-level permission gate.
 *
 * Used as "can:network.create" in a route's middleware list. It is a coarse
 * first pass — controllers still call Auth::authorize() for anything that
 * depends on the specific row — but it means a route can never be reachable
 * by a role that has no business calling it at all.
 */
final class RbacMiddleware
{
    public static function handle(Request $request, string $permission = ''): ?Response
    {
        if ($permission === '') {
            return null;
        }

        // Throws ForbiddenException, which the error handler renders as a 403
        // page or a JSON error depending on the caller.
        Auth::authorize($permission);

        return null;
    }
}
