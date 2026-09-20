<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/** Keeps a signed-in user out of the login and signup pages. */
final class GuestMiddleware
{
    public static function handle(Request $request): ?Response
    {
        return Auth::user() !== null ? Response::redirect(url('dashboard')) : null;
    }
}
