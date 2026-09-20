<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Core\Validator;
use App\Middleware\TenantScope;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\AuditService;

/**
 * Login, two-factor challenge, logout and password reset.
 *
 * The deliberate choices here are all about not leaking information: the same
 * message for every login failure, the same response whether or not a reset
 * address exists, and no distinction between a wrong TOTP code and a wrong
 * recovery code.
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth.login', ['title' => 'Sign in']);
    }

    public function login(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string|max:200',
        ], ['email' => 'Email address', 'password' => 'Password']);

        $user = Auth::attempt((string) $data['email'], (string) $data['password'], $request->ip());

        if ($user === null) {
            // One message for every cause: wrong password, unknown address,
            // disabled account, locked account.
            return $this->redirect('login', '', 'Those credentials do not match our records.');
        }

        if ((int) $user['twofa_enabled'] === 1) {
            // Not logged in yet — only a pending challenge. AuthMiddleware
            // treats this state as unauthenticated.
            Session::regenerate();
            Session::set('2fa_pending_user', (int) $user['id']);
            Session::set('2fa_pending_since', time());

            return Response::redirect(url('login/2fa'));
        }

        Auth::login($user, $request->ip());

        $intended = Session::flash('intended_url');

        return Response::redirect(
            is_string($intended) && $intended !== '' && $intended !== '/login'
                ? url(ltrim($intended, '/'))
                : url('dashboard')
        );
    }

    public function showTwoFactor(Request $request): Response
    {
        if (!Session::has('2fa_pending_user')) {
            return Response::redirect(url('login'));
        }

        return $this->view('auth.twofactor', ['title' => 'Two-factor authentication']);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $pendingId = Session::get('2fa_pending_user');
        $since = (int) Session::get('2fa_pending_since', 0);

        if (!is_int($pendingId) && !ctype_digit((string) $pendingId)) {
            return Response::redirect(url('login'));
        }

        // A challenge left open indefinitely is a standing invitation; five
        // minutes is ample for a code that rotates every thirty seconds.
        if ($since > 0 && (time() - $since) > 300) {
            Session::forget('2fa_pending_user');
            Session::forget('2fa_pending_since');

            return $this->redirect('login', '', 'That took too long. Please sign in again.');
        }

        $code = trim((string) $request->input('code', ''));

        $user = TenantScope::acrossAllTenants(
            '2fa challenge lookup',
            static fn (): ?array => User::findActiveById((int) $pendingId)
        );

        if ($user === null) {
            Session::forget('2fa_pending_user');

            return $this->redirect('login', '', 'Please sign in again.');
        }

        $secret = Crypto::decrypt((string) $user['twofa_secret']);
        $verified = $secret !== null && Totp::verify($secret, $code);

        // A recovery code is accepted in the same field; the user should not
        // have to tell us which kind they are typing.
        if (!$verified) {
            $recovery = $user['twofa_recovery_json'] ?? [];
            if (is_array($recovery) && $recovery !== []) {
                $remaining = Totp::consumeRecoveryCode($code, array_map('strval', $recovery));
                if ($remaining !== null) {
                    User::replaceRecoveryCodes((int) $user['id'], $remaining);
                    $verified = true;

                    AuditService::log('auth.2fa.recovery_used', 'user', (int) $user['id'], null, [
                        'codes_remaining' => count($remaining),
                    ]);

                    Session::flash('warning', sprintf(
                        'You used a recovery code. %d remain — generate new ones from your account settings.',
                        count($remaining)
                    ));
                }
            }
        }

        if (!$verified) {
            Logger::warning('security', '2FA verification failed', [
                'user_id' => $user['id'],
                'ip'      => $request->ip(),
            ]);
            AuditService::log('auth.2fa.failed', 'user', (int) $user['id'], null, null, 'failure');

            return $this->redirect('login/2fa', '', 'That code was not correct.');
        }

        Session::forget('2fa_pending_user');
        Session::forget('2fa_pending_since');

        Auth::login($user, $request->ip());
        AuditService::log('auth.2fa.success', 'user', (int) $user['id']);

        $intended = Session::flash('intended_url');

        return Response::redirect(
            is_string($intended) && $intended !== '' ? url(ltrim($intended, '/')) : url('dashboard')
        );
    }

    public function logout(Request $request): Response
    {
        // Ending an impersonation session should return the operator to their
        // own account rather than signing them out entirely.
        if (Auth::isImpersonating()) {
            Auth::stopImpersonation();

            return $this->redirect('admin/tenants', 'Impersonation ended.');
        }

        Auth::logout();

        return $this->redirect('login', 'You have been signed out.');
    }

    // ------------------------------------------------------- password reset

    public function showForgotPassword(Request $request): Response
    {
        return $this->view('auth.forgot', ['title' => 'Reset your password']);
    }

    public function sendResetLink(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['email' => 'required|email'], ['email' => 'Email address']);

        $user = User::findByEmail((string) $data['email']);

        if ($user !== null && $user['status'] === 'active') {
            $token = PasswordReset::issue((int) $user['id'], $request->ip());
            $link = url('reset-password?token=' . rawurlencode($token));

            $brand = (string) Config::get('brand.name', 'Panel');
            $result = Mailer::fromConfig()->send(
                (string) $user['email'],
                (string) $user['name'],
                $brand . ' — password reset',
                sprintf(
                    '<p>Hello %s,</p><p>Use the link below to choose a new password. It expires in 60 minutes '
                    . 'and can be used once.</p><p><a href="%s">Reset your password</a></p>'
                    . '<p style="color:#666;font-size:12px">If you did not request this, you can ignore this email — '
                    . 'nothing has changed.</p>',
                    htmlspecialchars((string) $user['name'], ENT_QUOTES),
                    htmlspecialchars($link, ENT_QUOTES)
                ),
                "Use this link to reset your password (valid for 60 minutes):\n" . $link
            );

            if (!$result['success']) {
                Logger::error('auth', 'Password reset email failed', ['error' => $result['error']]);
            }

            AuditService::log('auth.password_reset.requested', 'user', (int) $user['id']);
        }

        // Identical response either way — otherwise this endpoint becomes an
        // account-enumeration oracle.
        return $this->redirect(
            'login',
            'If that address has an account, a reset link is on its way. Check your inbox and spam folder.'
        );
    }

    public function showResetPassword(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $reset = $token !== '' ? PasswordReset::resolve($token) : null;

        if ($reset === null) {
            return $this->redirect('login', '', 'That reset link is invalid or has expired. Request a new one.');
        }

        return $this->view('auth.reset', [
            'title' => 'Choose a new password',
            'token' => $token,
            'email' => $reset['email'],
        ]);
    }

    public function resetPassword(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'token'    => 'required|string',
            'password' => 'required|password|confirmed',
        ], ['password' => 'Password']);

        $reset = PasswordReset::resolve((string) $data['token']);
        if ($reset === null) {
            return $this->redirect('login', '', 'That reset link is invalid or has expired.');
        }

        $userId = (int) $reset['user_id'];

        TenantScope::acrossAllTenants('password reset write', static function () use ($userId, $data, $reset): void {
            User::setPassword($userId, (string) $data['password']);
            User::clearFailedAttempts($userId);
            PasswordReset::consume((int) $reset['id']);
            // Every other session for this account is ended: if the reset was
            // triggered because of a compromise, the attacker's session must go.
            \App\Core\DbSessionHandler::destroyForUser($userId);
        });

        AuditService::log('auth.password_reset.completed', 'user', $userId);
        Logger::notice('security', 'Password reset completed', ['user_id' => $userId, 'ip' => $request->ip()]);

        return $this->redirect('login', 'Your password has been changed. Sign in with it now.');
    }
}
