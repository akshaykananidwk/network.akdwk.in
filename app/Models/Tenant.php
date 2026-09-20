<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * A customer company. The `tenants` table is itself platform-level — a tenant
 * user never lists tenants — so tenantScoped is false and access is gated by
 * the `tenant.manage` permission instead.
 */
final class Tenant extends Model
{
    protected static string $table = 'tenants';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['branding_json'];
    protected static array $sortable = ['id', 'company_name', 'status', 'created_at', 'subscription_expires_at'];
    protected static array $fillable = [
        'company_name', 'slug', 'contact_person', 'mobile', 'email', 'plan_id',
        'device_limit', 'network_limit', 'user_limit', 'relay_gb_month',
        'status', 'timezone', 'locale', 'trial_ends_at', 'subscription_expires_at',
        'grace_until', 'branding_json', 'custom_domain', 'notes',
    ];

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return self::findBy(['slug' => $slug]);
    }

    /** @return array<string,mixed>|null */
    public static function findByCustomDomain(string $domain): ?array
    {
        return self::findBy(['custom_domain' => $domain]);
    }

    public static function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) DB::scalar($sql, $params) > 0;
    }

    /** Derive a unique URL slug from a company name. */
    public static function uniqueSlug(string $companyName): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $companyName), '-'));
        $base = $base === '' ? 'tenant' : substr($base, 0, 48);
        $slug = $base;
        $suffix = 1;
        while (self::slugExists($slug)) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }

    /**
     * Live usage against plan limits, for the meters on the billing screen and
     * for the server-side checks in BillingService.
     *
     * @return array{devices:int,networks:int,users:int}
     */
    public static function usage(int $tenantId): array
    {
        return TenantScope::acrossAllTenants('tenant usage counters', static fn (): array => [
            'devices' => (int) DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('devices') . ' WHERE tenant_id = :t AND deleted_at IS NULL AND status IN (\'authorized\',\'disabled\')',
                ['t' => $tenantId]
            ),
            'networks' => (int) DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('networks') . ' WHERE tenant_id = :t AND deleted_at IS NULL AND status <> \'archived\'',
                ['t' => $tenantId]
            ),
            'users' => (int) DB::scalar(
                'SELECT COUNT(*) FROM ' . DB::table('users') . ' WHERE tenant_id = :t AND deleted_at IS NULL',
                ['t' => $tenantId]
            ),
        ]);
    }

    /** Suspended tenants keep existing tunnels but may not add devices. */
    public static function isOperational(array $tenant): bool
    {
        if (in_array($tenant['status'], ['suspended', 'cancelled'], true)) {
            return false;
        }
        $expiry = $tenant['subscription_expires_at'] ?? null;
        $grace = $tenant['grace_until'] ?? null;
        if ($expiry === null) {
            return true;
        }
        $deadline = $grace ?? $expiry;

        return strtotime((string) $deadline . ' UTC') > time();
    }

    /** @return list<array<string,mixed>> */
    public static function allActive(): array
    {
        return DB::select(
            'SELECT * FROM ' . self::tableName() . ' WHERE deleted_at IS NULL AND status IN (\'trial\',\'active\') ORDER BY company_name'
        );
    }
}
