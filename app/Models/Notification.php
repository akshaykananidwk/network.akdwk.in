<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Notification extends Model
{
    protected static string $table = 'notifications';
    protected static bool $tenantScoped = false; // platform alerts have tenant_id NULL
    protected static bool $softDeletes = false;
    protected static array $sortable = ['id', 'created_at'];
    protected static array $fillable = [
        'tenant_id', 'user_id', 'level', 'title', 'body', 'link', 'category', 'read_at', 'emailed_at',
    ];

    /** @return list<array<string,mixed>> */
    public static function recentForUser(int $userId, int $limit = 20): array
    {
        return DB::select(
            'SELECT * FROM ' . self::tableName() . ' WHERE user_id = :u ORDER BY id DESC LIMIT ' . max(1, min($limit, 100)),
            ['u' => $userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) DB::scalar(
            'SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId]
        );
    }

    public static function markRead(int $id, int $userId): bool
    {
        return DB::execute(
            'UPDATE ' . self::tableName() . ' SET read_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => $userId]
        )->rowCount() > 0;
    }

    public static function markAllRead(int $userId): int
    {
        return DB::execute(
            'UPDATE ' . self::tableName() . ' SET read_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId]
        )->rowCount();
    }
}
