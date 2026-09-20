<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\LimitExceededException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Plan limits, enforced at the action rather than in the UI.
 *
 * The requirement is explicit (§13): approving the 26th device on a 25-device
 * plan must fail with a clear upgrade message. The UI meters are cosmetic; the
 * assert* methods below are the enforcement.
 */
final class BillingService
{
    /** @throws LimitExceededException */
    public static function assertCanAddDevice(int $tenantId): void
    {
        $tenant = self::tenant($tenantId);
        self::assertOperational($tenant);

        $limit = (int) $tenant['device_limit'];
        if ($limit <= 0) {
            return; // 0 = unmetered
        }

        $usage = Tenant::usage($tenantId);
        if ($usage['devices'] >= $limit) {
            throw new LimitExceededException(
                sprintf(
                    'Your plan allows %d device%s and %d are already in use. Upgrade, or revoke a device you no longer need.',
                    $limit,
                    $limit === 1 ? '' : 's',
                    $usage['devices']
                ),
                self::nextPlanHint($tenant)
            );
        }
    }

    /** @throws LimitExceededException */
    public static function assertCanAddNetwork(int $tenantId): void
    {
        $tenant = self::tenant($tenantId);
        self::assertOperational($tenant);

        $limit = (int) $tenant['network_limit'];
        if ($limit <= 0) {
            return;
        }

        $usage = Tenant::usage($tenantId);
        if ($usage['networks'] >= $limit) {
            throw new LimitExceededException(
                sprintf('Your plan allows %d network%s. Upgrade to create another.', $limit, $limit === 1 ? '' : 's'),
                self::nextPlanHint($tenant)
            );
        }
    }

    /** @throws LimitExceededException */
    public static function assertCanAddUser(int $tenantId): void
    {
        $tenant = self::tenant($tenantId);

        $limit = (int) $tenant['user_limit'];
        if ($limit <= 0) {
            return;
        }

        $usage = Tenant::usage($tenantId);
        if ($usage['users'] >= $limit) {
            throw new LimitExceededException(
                sprintf('Your plan allows %d user%s. Upgrade to invite more.', $limit, $limit === 1 ? '' : 's'),
                self::nextPlanHint($tenant)
            );
        }
    }

    /**
     * Feature gate for plan-restricted capabilities (advanced ACL, site-to-site,
     * API access, SSO, white-label).
     */
    public static function hasFeature(int $tenantId, string $feature): bool
    {
        $subscription = Subscription::activeFor($tenantId);
        if ($subscription === null) {
            $tenant = self::tenant($tenantId);
            if ($tenant['plan_id'] === null) {
                return false;
            }
            $plan = Plan::find((int) $tenant['plan_id']);

            return $plan !== null && Plan::hasFeature($plan, $feature);
        }

        $features = $subscription['features_json'] ?? null;
        if (is_string($features)) {
            $decoded = json_decode($features, true);
            $features = is_array($decoded) ? $decoded : [];
        }

        return is_array($features) && !empty($features[$feature]);
    }

    /** @throws LimitExceededException */
    public static function assertFeature(int $tenantId, string $feature, string $label): void
    {
        if (!self::hasFeature($tenantId, $feature)) {
            throw new LimitExceededException(
                $label . ' is not included in your current plan.',
                'Upgrade to Business or Enterprise to enable it.'
            );
        }
    }

    /**
     * Usage against limits, for the meters on the dashboard and billing page.
     *
     * @return array<string,array{used:int,limit:int,percent:int}>
     */
    public static function usageMeters(int $tenantId): array
    {
        $tenant = self::tenant($tenantId);
        $usage = Tenant::usage($tenantId);

        $meter = static function (int $used, int $limit): array {
            return [
                'used'    => $used,
                'limit'   => $limit,
                'percent' => $limit > 0 ? (int) min(100, round(($used / $limit) * 100)) : 0,
            ];
        };

        return [
            'devices'  => $meter($usage['devices'], (int) $tenant['device_limit']),
            'networks' => $meter($usage['networks'], (int) $tenant['network_limit']),
            'users'    => $meter($usage['users'], (int) $tenant['user_limit']),
        ];
    }

    /**
     * Apply a plan's limits to a tenant. Called when a subscription starts or
     * changes; the tenant row carries the effective limits so every check is
     * one read rather than a join.
     */
    public static function applyPlan(int $tenantId, int $planId): void
    {
        $plan = Plan::find($planId);
        if ($plan === null) {
            return;
        }

        Tenant::update($tenantId, [
            'plan_id'        => $planId,
            'device_limit'   => (int) $plan['device_limit'],
            'network_limit'  => (int) $plan['network_limit'],
            'user_limit'     => (int) $plan['user_limit'],
            'relay_gb_month' => (int) $plan['relay_gb_month'],
        ]);

        AuditService::log('billing.plan_applied', 'tenant', $tenantId, null, [
            'plan'   => $plan['slug'],
            'limits' => [
                'devices'  => $plan['device_limit'],
                'networks' => $plan['network_limit'],
                'users'    => $plan['user_limit'],
            ],
        ]);
    }

    /**
     * A suspended or expired tenant keeps its tunnels (R6 — existing sessions
     * are data plane and do not depend on us) but cannot add capacity.
     *
     * @param array<string,mixed> $tenant
     * @throws LimitExceededException
     */
    private static function assertOperational(array $tenant): void
    {
        if (Tenant::isOperational($tenant)) {
            return;
        }

        throw new LimitExceededException(
            $tenant['status'] === 'suspended'
                ? 'This account is suspended. Existing connections keep working, but new devices cannot be added.'
                : 'This subscription has expired. Renew to add devices or networks.',
            'Renew or contact support to restore full access.'
        );
    }

    /** @param array<string,mixed> $tenant */
    private static function nextPlanHint(array $tenant): string
    {
        $plans = Plan::publicPlans();
        $currentDevices = (int) $tenant['device_limit'];

        foreach ($plans as $plan) {
            if ((int) $plan['device_limit'] > $currentDevices) {
                return sprintf('%s raises the limit to %d devices.', $plan['name'], $plan['device_limit']);
            }
        }

        return 'Contact us for a custom plan.';
    }

    /** @return array<string,mixed> */
    private static function tenant(int $tenantId): array
    {
        return \App\Middleware\TenantScope::acrossAllTenants(
            'plan limit check',
            static fn (): array => Tenant::findOrFail($tenantId)
        );
    }
}
