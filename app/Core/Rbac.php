<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Role → permission matrix.
 *
 * Kept as code rather than rows: the set of permissions changes only when code
 * changes, and a DB-driven matrix invites a privilege-escalation bug where a
 * tenant admin edits their own grants. Per-tenant customisation, when it comes,
 * will be a *subtractive* overlay on top of this.
 */
final class Rbac
{
    public const SUPER_ADMIN   = 'super_admin';
    public const COMPANY_ADMIN = 'company_admin';
    public const NETWORK_ADMIN = 'network_admin';
    public const SUPPORT_AGENT = 'support_agent';
    public const READ_ONLY     = 'read_only';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::SUPER_ADMIN, self::COMPANY_ADMIN, self::NETWORK_ADMIN, self::SUPPORT_AGENT, self::READ_ONLY];
    }

    /** Roles a tenant admin is allowed to assign — never super_admin. */
    public static function assignableRoles(): array
    {
        return [self::COMPANY_ADMIN, self::NETWORK_ADMIN, self::SUPPORT_AGENT, self::READ_ONLY];
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::SUPER_ADMIN   => 'Super Admin',
            self::COMPANY_ADMIN => 'Company Admin',
            self::NETWORK_ADMIN => 'Network Admin',
            self::SUPPORT_AGENT => 'Support Agent',
            self::READ_ONLY     => 'Read Only',
            default             => ucfirst(str_replace('_', ' ', $role)),
        };
    }

    /**
     * Every permission the application checks. Grouped by area for the UI.
     *
     * @return array<string,list<string>>
     */
    public static function catalogue(): array
    {
        return [
            'Networks' => ['network.view', 'network.create', 'network.update', 'network.delete'],
            'Devices'  => ['device.view', 'device.approve', 'device.update', 'device.revoke', 'device.delete'],
            'Access'   => ['acl.view', 'acl.manage', 'route.view', 'route.manage'],
            'People'   => ['user.view', 'user.create', 'user.update', 'user.delete'],
            'API'      => ['apikey.view', 'apikey.create', 'apikey.revoke'],
            'Billing'  => ['billing.view', 'billing.manage'],
            'Audit'    => ['audit.view'],
            'Settings' => ['settings.view', 'settings.manage'],
            'Platform' => ['tenant.manage', 'relay.manage', 'update.manage', 'backup.manage', 'impersonate', 'platform.settings'],
        ];
    }

    /** @return list<string> */
    public static function allPermissions(): array
    {
        return array_merge(...array_values(self::catalogue()));
    }

    /**
     * @return array<string,list<string>> role => granted permissions ('*' = all)
     */
    public static function matrix(): array
    {
        $companyAdmin = [
            'network.view', 'network.create', 'network.update', 'network.delete',
            'device.view', 'device.approve', 'device.update', 'device.revoke', 'device.delete',
            'acl.view', 'acl.manage', 'route.view', 'route.manage',
            'user.view', 'user.create', 'user.update', 'user.delete',
            'apikey.view', 'apikey.create', 'apikey.revoke',
            'billing.view', 'billing.manage',
            'audit.view',
            'settings.view', 'settings.manage',
        ];

        return [
            self::SUPER_ADMIN   => ['*'],
            self::COMPANY_ADMIN => $companyAdmin,
            self::NETWORK_ADMIN => [
                'network.view', 'network.create', 'network.update',
                'device.view', 'device.approve', 'device.update', 'device.revoke',
                'acl.view', 'acl.manage', 'route.view', 'route.manage',
                'audit.view', 'settings.view',
            ],
            self::SUPPORT_AGENT => [
                'network.view', 'device.view', 'device.update',
                'acl.view', 'route.view', 'user.view', 'audit.view', 'settings.view',
            ],
            self::READ_ONLY => [
                'network.view', 'device.view', 'acl.view', 'route.view', 'settings.view',
            ],
        ];
    }

    public static function roleHas(string $role, string $permission): bool
    {
        $granted = self::matrix()[$role] ?? [];
        if (in_array('*', $granted, true)) {
            return true;
        }
        if (in_array($permission, $granted, true)) {
            return true;
        }

        // "device.*" grants every device permission.
        foreach ($granted as $grant) {
            if (str_ends_with($grant, '.*') && str_starts_with($permission, substr($grant, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /** Platform-only permissions can never be held by a tenant-scoped user. */
    public static function isPlatformPermission(string $permission): bool
    {
        return in_array($permission, self::catalogue()['Platform'], true);
    }
}
