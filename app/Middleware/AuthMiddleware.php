<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\AuthException;
use App\Core\Config;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Requires an authenticated session.
 *
 * Also enforces the two gates that must hold on every request rather than only
 * at login: a half-finished 2FA challenge cannot reach the app, and a super
 * admin who has not yet enrolled in 2FA is funnelled to do so when the policy
 * requires it.
 */
final class AuthMiddleware
{
    public static function handle(Request $request): ?Response
    {
        // A pending 2FA challenge is not a session; it is a half-open door.
        if (Session::has('2fa_pending_user')) {
            return $request->wantsJson()
                ? Response::apiError('Two-factor authentication is required.', 401, 'twofa_required')
                : Response::redirect(url('login/2fa'));
        }

        if (Auth::user() === null) {
            if ($request->wantsJson()) {
                throw new AuthException('Authentication required.');
            }

            // Remember where they were headed so login can return them there.
            Session::flash('intended_url', $request->path());

            return Response::redirect(url('login'));
        }

        if ((bool) Config::get('security.require_2fa_super', true)
            && Auth::role() === Rbac::SUPER_ADMIN
            && !Auth::isImpersonating()
            && (int) (Auth::user()['twofa_enabled'] ?? 0) !== 1
            && !str_starts_with($request->path(), '/account/2fa')
            && !str_starts_with($request->path(), '/logout')
        ) {
            Session::flash('warning', 'Two-factor authentication is mandatory for platform administrators. Please enrol now.');

            return Response::redirect(url('account/2fa'));
        }

        return null;
    }
}
