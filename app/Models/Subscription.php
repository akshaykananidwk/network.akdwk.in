<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Subscription extends Model
{
    protected static string $table = 'subscriptions';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = true;
    protected static array $sortable = ['id', 'current_period_end', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'plan_id', 'billing_cycle', 'status', 'gateway',
        'gateway_subscription_id', 'current_period_start', 'current_period_end', 'cancelled_at',
    ];

    /** @return array<string,mixed>|null */
    public static function activeFor(int $tenantId): ?array
    {
        return DB::selectOne(
            'SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.features_json
             FROM ' . self::tableName() . ' s
             JOIN ' . DB::table('plans') . ' p ON p.id = s.plan_id
             WHERE s.tenant_id = :t AND s.deleted_at IS NULL AND s.status IN (\'trialing\',\'active\',\'past_due\')
             ORDER BY s.id DESC LIMIT 1',
            ['t' => $tenantId]
        );
    }

    /** @return list<array<string,mixed>> subscriptions expiring within N days */
    public static function expiringWithin(int $days): array
    {
        return DB::select(
            'SELECT s.*, t.company_name, t.email
             FROM ' . self::tableName() . ' s
             JOIN ' . DB::table('tenants') . ' t ON t.id = s.tenant_id
             WHERE s.deleted_at IS NULL AND s.status IN (\'trialing\',\'active\')
               AND s.current_period_end IS NOT NULL
               AND s.current_period_end BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL :d DAY)',
            ['d' => $days]
        );
    }
}
