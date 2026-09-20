<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Services\AuditService;

/**
 * Authentication state for the current request.
 *
 * Three identity sources feed this: an interactive session, an API key, and a
 * device token. Whichever is in play, actor() answers "who is acting" and
 * tenantId() answers "whose data may be touched" — TenantScope reads the
 * latter and nothing else.
 */
final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?int $tenantOverride = null;
    /** @var list<string>|null */
    private static ?array $scopeOverride = null;
    private static string $actorType = 'guest';

    // ----------------------------------------------------------------- login

    /**
     * Verify credentials without establishing a session.
     *
     * Returns the user row on success. Timing is deliberately levelled: we hash
     * against a dummy when the account does not exist so an attacker cannot
     * enumerate addresses from response time.
     *
     * @return array<string,mixed>|null
     */
    public static function attempt(string $email, string $password, string $ip): ?array
    {
        $user = User::findByEmail($email);

        if ($user === null) {
            Crypto::verifyPassword($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            self::recordFailure(null, $email, $ip, 'unknown_account');

            return null;
        }

        if (self::isLocked($user)) {
            self::recordFailure((int) $user['id'], $email, $ip, 'locked');

            return null;
        }

        if ($user['status'] !== 'active') {
            self::recordFailure((int) $user['id'], $email, $ip, 'inactive');

            return null;
        }

        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            self::recordFailure((int) $user['id'], $email, $ip, 'bad_password');

            return null;
        }

        // Opportunistic rehash when the cost parameters have been raised.
        if (Crypto::passwordNeedsRehash((string) $user['password_hash'])) {
            User::updatePasswordHash((int) $user['id'], Crypto::hashPassword($password));
        }

        User::clearFailedAttempts((int) $user['id']);

        return $user;
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user, string $ip): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('tenant_id', $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null);
        Session::set('role', (string) $user['role']);
        Session::set('logged_in_at', time());
        Session::forget('2fa_pending_user');
        Session::forget('impersonator_id');

        User::recordLogin((int) $user['id'], $ip);

        self::$user = $user;
        self::$resolved = true;
        self::$actorType = 'user';

        AuditService::log('auth.login', 'user', (int) $user['id'], null, null, 'success');
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            AuditService::log('auth.logout', 'user', (int) $user['id'], null, null, 'success');
        }
        Session::destroy();
        self::$user = null;
        self::$resolved = true;
        self::$actorType = 'guest';
    }

    // ------------------------------------------------------------ resolution

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $userId = Session::get('user_id');
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        $user = User::findActiveById((int) $userId);
        if ($user === null) {
            // The account was disabled or deleted while the session lived.
            Session::destroy();

            return null;
        }

        self::$user = $user;
        self::$actorType = 'user';

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null || self::$actorType === 'api_key' || self::$actorType === 'device';
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user !== null ? (int) $user['id'] : null;
    }

    public static function role(): string
    {
        $user = self::user();

        return $user !== null ? (string) $user['role'] : 'guest';
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === Rbac::SUPER_ADMIN && !self::isImpersonating();
    }

    /**
     * The tenant whose data this request may touch.
     *
     * null means "platform scope" — only a super admin who has not selected a
     * tenant. Every tenant-scoped query treats null as "no filter", so it is
     * critical that this returns null *only* for a genuine super admin.
     */
    public static function tenantId(): ?int
    {
        if (self::$tenantOverride !== null) {
            return self::$tenantOverride;
        }
        $user = self::user();
        if ($user === null) {
            return null;
        }

        return $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    }

    /** Used by the API-key middleware and by super-admin tenant selection. */
    public static function setTenantContext(?int $tenantId): void
    {
        self::$tenantOverride = $tenantId;
    }

    public static function actorType(): string
    {
        return self::$actorType;
    }

    /** @param array<string,mixed>|null $user @param list<string> $scopes */
    public static function setApiActor(?array $user, int $tenantId, array $scopes): void
    {
        self::$user = $user;
        self::$resolved = true;
        self::$actorType = 'api_key';
        self::$tenantOverride = $tenantId;
        self::$scopeOverride = $scopes;
    }

    public static function setDeviceActor(int $tenantId): void
    {
        self::$user = null;
        self::$resolved = true;
        self::$actorType = 'device';
        self::$tenantOverride = $tenantId;
        self::$scopeOverride = ['device:self'];
    }

    // ----------------------------------------------------------- permissions

    public static function can(string $permission): bool
    {
        // An API key can only ever narrow what its owner could do.
        if (self::$scopeOverride !== null) {
            $scoped = in_array('*', self::$scopeOverride, true) || in_array($permission, self::$scopeOverride, true);
            if (!$scoped) {
                return false;
            }
        }

        $role = self::role();
        if ($role === 'guest') {
            return false;
        }

        // While impersonating, platform powers are dropped: the operator acts
        // as the tenant user, and cannot use the tenant session to update the
        // platform or reach another tenant.
        if (self::isImpersonating() && Rbac::isPlatformPermission($permission)) {
            return false;
        }

        // A tenant-scoped user never holds a platform permission, whatever
        // their role string says.
        if (Rbac::isPlatformPermission($permission) && self::user() !== null && self::user()['tenant_id'] !== null) {
            return false;
        }

        return Rbac::roleHas($role, $permission);
    }

    /** @throws ForbiddenException */
    public static function authorize(string $permission): void
    {
        if (!self::can($permission)) {
            Logger::warning('security', 'Permission denied', [
                'permission' => $permission,
                'user_id'    => self::id(),
                'role'       => self::role(),
            ]);
            throw new ForbiddenException('Missing permission: ' . $permission);
        }
    }

    // --------------------------------------------------------- impersonation

    public static function isImpersonating(): bool
    {
        return Session::get('impersonator_id') !== null;
    }

    public static function impersonatorId(): ?int
    {
        $id = Session::get('impersonator_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** @param array<string,mixed> $target */
    public static function startImpersonation(array $target): void
    {
        $operator = self::user();
        if ($operator === null || (string) $operator['role'] !== Rbac::SUPER_ADMIN) {
            throw new ForbiddenException('Only a super admin may impersonate.');
        }

        AuditService::log('auth.impersonate.start', 'user', (int) $target['id'], null, [
            'target_email' => $target['email'],
            'tenant_id'    => $target['tenant_id'],
        ], 'success');

        Session::regenerate();
        Session::set('impersonator_id', (int) $operator['id']);
        Session::set('user_id', (int) $target['id']);
        Session::set('tenant_id', $target['tenant_id'] !== null ? (int) $target['tenant_id'] : null);
        Session::set('role', (string) $target['role']);

        self::$user = $target;
        self::$resolved = true;
        self::$tenantOverride = null;
    }

    public static function stopImpersonation(): void
    {
        $operatorId = self::impersonatorId();
        if ($operatorId === null) {
            return;
        }
        $impersonated = self::id();
        $operator = User::findActiveById($operatorId);
        if ($operator === null) {
            self::logout();

            return;
        }

        AuditService::log('auth.impersonate.stop', 'user', $impersonated, null, null, 'success');

        Session::regenerate();
        Session::forget('impersonator_id');
        Session::set('user_id', $operatorId);
        Session::set('tenant_id', $operator['tenant_id'] !== null ? (int) $operator['tenant_id'] : null);
        Session::set('role', (string) $operator['role']);

        self::$user = $operator;
        self::$resolved = true;
        self::$tenantOverride = null;
    }

    // ------------------------------------------------------------- lockout

    /** @param array<string,mixed> $user */
    public static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        if ($until === null || $until === '') {
            return false;
        }

        return strtotime((string) $until . ' UTC') > time();
    }

    /**
     * Progressive lockout: the first few failures cost nothing, then the lock
     * grows so an online guessing attack becomes impractical while a genuine
     * user who mistypes twice is not shut out for the day.
     */
    public static function lockoutSeconds(int $failedAttempts): int
    {
        $threshold = (int) Config::get('security.lockout_threshold', 5);
        if ($failedAttempts < $threshold) {
            return 0;
        }
        $over = $failedAttempts - $threshold;
        $seconds = 60 * (2 ** min($over, 6)); // 1m, 2m, 4m … capped at 64m

        return min($seconds, (int) Config::get('security.lockout_max_seconds', 3600));
    }

    private static function recordFailure(?int $userId, string $email, string $ip, string $reason): void
    {
        if ($userId !== null) {
            $attempts = User::incrementFailedAttempts($userId);
            $lockSeconds = self::lockoutSeconds($attempts);
            if ($lockSeconds > 0) {
                User::lock($userId, $lockSeconds);
            }
        }

        Logger::warning('security', 'Failed login', [
            'email'  => $email,
            'ip'     => $ip,
            'reason' => $reason,
        ]);

        AuditService::log('auth.login.failed', 'user', $userId, null, ['reason' => $reason, 'email' => $email], 'failure');
    }

    /** Test/CLI seam. */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
        self::$tenantOverride = null;
        self::$scopeOverride = null;
        self::$actorType = 'guest';
    }
}
