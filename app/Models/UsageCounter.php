<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * Monthly per-tenant metering. Relay bytes are the billable one; the rest are
 * there so the dashboard can chart without scanning devices.
 */
final class UsageCounter extends Model
{
    protected static string $table = 'usage_counters';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = false;
    protected static array $fillable = ['tenant_id', 'period', 'metric', 'value_num'];

    public const METRIC_RELAY_BYTES = 'relay_bytes';
    public const METRIC_DIRECT_BYTES = 'direct_bytes';
    public const METRIC_PEAK_DEVICES = 'peak_devices';

    public static function currentPeriod(): string
    {
        return gmdate('Y-m');
    }

    /** Add to a counter, creating it on first use. */
    public static function increment(int $tenantId, string $metric, int $delta, ?string $period = null): void
    {
        if ($delta <= 0) {
            return;
        }

        TenantScope::acrossAllTenants('usage metering write', static function () use ($tenantId, $metric, $delta, $period): void {
            DB::execute(
                'INSERT INTO ' . self::tableName() . ' (tenant_id, period, metric, value_num, created_at, updated_at)
                 VALUES (:t, :p, :m, :v, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE value_num = value_num + VALUES(value_num), updated_at = UTC_TIMESTAMP()',
                ['t' => $tenantId, 'p' => $period ?? self::currentPeriod(), 'm' => $metric, 'v' => $delta]
            );
        });
    }

    /** Raise a high-water mark without lowering it. */
    public static function setPeak(int $tenantId, string $metric, int $value, ?string $period = null): void
    {
        TenantScope::acrossAllTenants('usage peak write', static function () use ($tenantId, $metric, $value, $period): void {
            DB::execute(
                'INSERT INTO ' . self::tableName() . ' (tenant_id, period, metric, value_num, created_at, updated_at)
                 VALUES (:t, :p, :m, :v, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE value_num = GREATEST(value_num, VALUES(value_num)), updated_at = UTC_TIMESTAMP()',
                ['t' => $tenantId, 'p' => $period ?? self::currentPeriod(), 'm' => $metric, 'v' => $value]
            );
        });
    }

    public static function value(int $tenantId, string $metric, ?string $period = null): int
    {
        return (int) (DB::scalar(
            'SELECT value_num FROM ' . self::tableName() . ' WHERE tenant_id = :t AND period = :p AND metric = :m',
            ['t' => $tenantId, 'p' => $period ?? self::currentPeriod(), 'm' => $metric]
        ) ?? 0);
    }

    /** @return array<string,int> metric => value for one period */
    public static function forPeriod(int $tenantId, ?string $period = null): array
    {
        $rows = DB::select(
            'SELECT metric, value_num FROM ' . self::tableName() . ' WHERE tenant_id = :t AND period = :p',
            ['t' => $tenantId, 'p' => $period ?? self::currentPeriod()]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['metric']] = (int) $row['value_num'];
        }

        return $out;
    }
}
