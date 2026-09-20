<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\ForbiddenException;
use App\Core\Logger;
use App\Core\Rbac;

/**
 * The single place that decides "whose rows may this request see".
 *
 * Rule R3 is enforced here and nowhere else, so there is exactly one thing to
 * audit. Every tenant-scoped query in the application routes through
 * constrain(), which either appends `tenant_id = :__tenant` or — for a genuine
 * platform super admin — deliberately appends nothing.
 *
 * The failure mode is closed: if we cannot establish a tenant and the actor is
 * not a platform super admin, we throw rather than returning an unscoped query.
 */
final class TenantScope
{
    public const BIND = '__tenant_scope_id';

    /** Set by an explicit, audited super-admin "view as tenant" action. */
    private static ?int $forcedTenantId = null;
    private static bool $platformQueryAllowed = false;

    /**
     * Resolve the tenant id to filter on.
     *
     * @return int|null null means "no filter" — only ever returned for a
     *                  platform super admin or an explicitly allowed
     *                  platform-level query.
     * @throws ForbiddenException
     */
    public static function currentTenantId(): ?int
    {
        // An explicit "act as this tenant" always wins, including inside a
        // cross-tenant block.
        if (self::$forcedTenantId !== null) {
            return self::$forcedTenantId;
        }

        // An explicit cross-tenant block lifts the filter even when an actor
        // is signed in. Checking the actor first would silently re-apply their
        // tenant and make acrossAllTenants() a no-op for every authenticated
        // request — which is exactly when the platform dashboards need it.
        if (self::$platformQueryAllowed) {
            return null;
        }

        $tenantId = Auth::tenantId();
        if ($tenantId !== null) {
            return $tenantId;
        }

        if (self::isPlatformActor()) {
            return null;
        }

        Logger::error('security', 'Tenant-scoped query attempted with no tenant context', [
            'actor_type' => Auth::actorType(),
            'user_id'    => Auth::id(),
            'role'       => Auth::role(),
        ]);

        throw new ForbiddenException('No tenant context for a tenant-scoped query.');
    }

    /** True only for a real platform super admin who is not impersonating. */
    public static function isPlatformActor(): bool
    {
        if (self::$platformQueryAllowed) {
            return true;
        }

        return Auth::role() === Rbac::SUPER_ADMIN
            && Auth::tenantId() === null
            && !Auth::isImpersonating();
    }

    /**
     * Append the tenant predicate to a WHERE clause.
     *
     * @param array<string,mixed> $bindings modified in place
     * @return string the (possibly unchanged) WHERE fragment
     */
    public static function constrain(string $where, array &$bindings, string $tableAlias = ''): string
    {
        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            return $where;
        }

        $column = ($tableAlias !== '' ? $tableAlias . '.' : '') . 'tenant_id';
        $bindings[self::BIND] = $tenantId;
        $predicate = $column . ' = :' . self::BIND;

        return trim($where) === '' ? $predicate : '(' . $where . ') AND ' . $predicate;
    }

    /**
     * Assert a row that was already fetched belongs to the caller.
     *
     * Belt-and-braces for code paths that load by a globally unique key (a
     * device_uid, an api key hash) where the tenant cannot be in the lookup.
     *
     * @param array<string,mixed>|null $row
     * @throws ForbiddenException
     */
    public static function assertOwned(?array $row, string $what = 'resource'): void
    {
        if ($row === null) {
            return;
        }
        $tenantId = self::currentTenantId();
        if ($tenantId === null) {
            return;
        }
        $rowTenant = isset($row['tenant_id']) ? (int) $row['tenant_id'] : null;
        if ($rowTenant !== $tenantId) {
            Logger::error('security', 'Cross-tenant access blocked', [
                'what'           => $what,
                'row_tenant_id'  => $rowTenant,
                'actor_tenant_id' => $tenantId,
                'user_id'        => Auth::id(),
            ]);
            throw new ForbiddenException('Cross-tenant access denied.');
        }
    }

    /**
     * Run a callback as a specific tenant.
     *
     * Used by the worker (which has no session) and by super-admin drill-down.
     * It is never reachable from a tenant user's request path.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function asTenant(int $tenantId, callable $callback): mixed
    {
        $previous = self::$forcedTenantId;
        self::$forcedTenantId = $tenantId;
        try {
            return $callback();
        } finally {
            self::$forcedTenantId = $previous;
        }
    }

    /**
     * Run a callback with tenant filtering lifted.
     *
     * Only for genuinely cross-tenant work: the scheduler, platform dashboards,
     * the backup dumper. Every call site is expected to be reviewable, so this
     * logs at notice level when invoked from a web request.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function acrossAllTenants(string $reason, callable $callback): mixed
    {
        if (PHP_SAPI !== 'cli' && !self::isPlatformActor()) {
            Logger::notice('security', 'Cross-tenant query requested', ['reason' => $reason, 'user_id' => Auth::id()]);
        }
        $previousForced = self::$forcedTenantId;
        $previousAllowed = self::$platformQueryAllowed;
        self::$forcedTenantId = null;
        self::$platformQueryAllowed = true;
        try {
            return $callback();
        } finally {
            self::$forcedTenantId = $previousForced;
            self::$platformQueryAllowed = $previousAllowed;
        }
    }

    /** Test/CLI seam. */
    public static function reset(): void
    {
        self::$forcedTenantId = null;
        self::$platformQueryAllowed = false;
    }
}
