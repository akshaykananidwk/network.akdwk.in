<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Core\Validator;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuditService;

/** The signed-in user's own profile, password and two-factor enrolment. */
final class AccountController extends Controller
{
    public function profile(Request $request): Response
    {
        return $this->view('settings.account', [
            'title'     => 'My account',
            'timezones' => \DateTimeZone::listIdentifiers(),
            'locales'   => \App\Core\Lang::available(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $userId = Auth::id();
        if ($userId === null) {
            return $this->redirect('login');
        }

        $data = Validator::validate($request->all(), [
            'name'     => 'required|string|max:120',
            'timezone' => 'nullable|timezone',
            'locale'   => 'nullable|string|max:8',
        ], ['name' => 'Name']);

        User::update($userId, [
            'name'     => $data['name'],
            'timezone' => $data['timezone'],
            'locale'   => $data['locale'],
        ]);

        AuditService::log('account.update', 'user', $userId);

        return $this->redirect('account', 'Profile updated.');
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->redirect('login');
        }

        $data = Validator::validate($request->all(), [
            'current_password' => 'required|string',
            'password'         => 'required|password|confirmed|different:current_password',
        ], ['current_password' => 'Current password', 'password' => 'New password']);

        if (!Crypto::verifyPassword((string) $data['current_password'], (string) $user['password_hash'])) {
            AuditService::log('account.password_change', 'user', (int) $user['id'], null, null, 'failure');

            return $this->redirect('account', '', 'Your current password was not correct.');
        }

        User::setPassword((int) $user['id'], (string) $data['password']);
        AuditService::log('account.password_change', 'user', (int) $user['id']);

        return $this->redirect('account', 'Password changed.');
    }

    // ------------------------------------------------------------ two-factor

    public function showTwoFactor(Request $request): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->redirect('login');
        }

        if ((int) $user['twofa_enabled'] === 1) {
            return $this->view('settings.twofactor', [
                'title'   => 'Two-factor authentication',
                'enabled' => true,
                'remaining_codes' => is_array($user['twofa_recovery_json'] ?? null)
                    ? count($user['twofa_recovery_json'])
                    : 0,
            ]);
        }

        // The secret is held in the session until the user proves they can
        // generate a code from it; enabling on an un-scanned secret is how
        // people lock themselves out.
        $secret = Session::get('2fa_setup_secret');
        if (!is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            Session::set('2fa_setup_secret', $secret);
        }

        return $this->view('settings.twofactor', [
            'title'   => 'Two-factor authentication',
            'enabled' => false,
            'secret'  => $secret,
            'uri'     => Totp::provisioningUri(
                $secret,
                (string) $user['email'],
                (string) Config::get('brand.name', 'Panel')
            ),
        ]);
    }

    public function enableTwoFactor(Request $request): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->redirect('login');
        }

        $secret = Session::get('2fa_setup_secret');
        if (!is_string($secret) || $secret === '') {
            return $this->redirect('account/2fa', '', 'That setup expired. Start again.');
        }

        $code = trim((string) $request->input('code', ''));
        if (!Totp::verify($secret, $code)) {
            return $this->redirect('account/2fa', '', 'That code was not correct. Check your authenticator app\'s clock.');
        }

        $recovery = Totp::generateRecoveryCodes(8);

        User::enableTwoFactor((int) $user['id'], Crypto::encrypt($secret), $recovery['hashes']);
        Session::forget('2fa_setup_secret');

        // Shown once, on the next page, then unrecoverable.
        Session::flash('recovery_codes', $recovery['plain']);

        AuditService::log('account.2fa.enabled', 'user', (int) $user['id']);

        return $this->redirect('account/2fa', 'Two-factor authentication is on. Save your recovery codes now.');
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->redirect('login');
        }

        if ((bool) Config::get('security.require_2fa_super', true) && Auth::isSuperAdmin()) {
            return $this->redirect('account/2fa', '', 'Two-factor authentication is mandatory for platform administrators.');
        }

        // Re-authenticate: an unattended open session must not be enough to
        // remove the second factor.
        $password = (string) $request->input('password', '');
        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            return $this->redirect('account/2fa', '', 'Enter your password to turn two-factor off.');
        }

        User::disableTwoFactor((int) $user['id']);
        AuditService::log('account.2fa.disabled', 'user', (int) $user['id']);

        return $this->redirect('account/2fa', 'Two-factor authentication is off.');
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = Auth::user();
        if ($user === null || (int) $user['twofa_enabled'] !== 1) {
            return $this->redirect('account/2fa', '', 'Two-factor authentication is not enabled.');
        }

        $recovery = Totp::generateRecoveryCodes(8);
        User::replaceRecoveryCodes((int) $user['id'], $recovery['hashes']);
        Session::flash('recovery_codes', $recovery['plain']);

        AuditService::log('account.2fa.recovery_regenerated', 'user', (int) $user['id']);

        return $this->redirect('account/2fa', 'New recovery codes generated. The old ones no longer work.');
    }

    // --------------------------------------------------------- notifications

    public function notifications(Request $request): Response
    {
        $userId = Auth::id();
        if ($userId === null) {
            return $this->redirect('login');
        }

        return $this->view('settings.notifications', [
            'title'         => 'Notifications',
            'notifications' => Notification::recentForUser($userId, 50),
        ]);
    }

    public function markNotificationRead(Request $request, array $params): Response
    {
        $userId = Auth::id();
        if ($userId === null) {
            return Response::apiError('Not signed in.', 401);
        }

        Notification::markRead((int) $params['id'], $userId);

        return $request->wantsJson()
            ? Response::api(['unread' => Notification::unreadCount($userId)])
            : $this->back($request);
    }

    public function markAllNotificationsRead(Request $request): Response
    {
        $userId = Auth::id();
        if ($userId === null) {
            return Response::apiError('Not signed in.', 401);
        }

        $count = Notification::markAllRead($userId);

        return $request->wantsJson()
            ? Response::api(['marked' => $count, 'unread' => 0])
            : $this->back($request, $count . ' notification(s) marked as read.');
    }
}
