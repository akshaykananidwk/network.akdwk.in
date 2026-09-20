<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Plan extends Model
{
    protected static string $table = 'plans';
    protected static bool $tenantScoped = false;
    protected static bool $softDeletes = true;
    protected static array $jsonColumns = ['features_json'];
    protected static array $sortable = ['sort_order', 'price_monthly', 'name'];
    protected static array $fillable = [
        'name', 'slug', 'price_monthly', 'price_yearly', 'currency',
        'device_limit', 'network_limit', 'user_limit', 'relay_gb_month',
        'features_json', 'support_tier', 'is_public', 'sort_order',
    ];

    /** @return list<array<string,mixed>> */
    public static function publicPlans(): array
    {
        return array_map(self::hydrate(...), DB::select(
            'SELECT * FROM ' . self::tableName() . ' WHERE is_public = 1 AND deleted_at IS NULL ORDER BY sort_order, price_monthly'
        ));
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return self::findBy(['slug' => $slug]);
    }

    /** @param array<string,mixed> $plan */
    public static function hasFeature(array $plan, string $feature): bool
    {
        $features = $plan['features_json'] ?? [];

        return is_array($features) && !empty($features[$feature]);
    }
}
