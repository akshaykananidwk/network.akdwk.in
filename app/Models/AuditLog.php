<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Middleware\TenantScope;

/**
 * Append-only activity record. There is no update() or delete() path on
 * purpose: the only writer is AuditService::log() and the only remover is the
 * retention pruner.
 */
final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';
    protected static bool $tenantScoped = true;
    protected static bool $softDeletes = false;
    protected static array $jsonColumns = ['before_json', 'after_json'];
    protected static array $sortable = ['id', 'created_at', 'action'];
    protected static array $fillable = [
        'tenant_id', 'user_id', 'impersonator_id', 'actor_type', 'action',
        'target_type', 'target_id', 'ip', 'user_agent', 'before_json', 'after_json', 'result',
    ];

    /**
     * Audit list with the actor's name joined in.
     *
     * @param array{action?:string,result?:string,user_id?:int,from?:string,to?:string,q?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function search(array $filters, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 200));

        $where = '1 = 1';
        $bindings = [];

        if (!empty($filters['action'])) {
            $where .= ' AND a.action = :action';
            $bindings['action'] = $filters['action'];
        }
        if (!empty($filters['result'])) {
            $where .= ' AND a.result = :result';
            $bindings['result'] = $filters['result'];
        }
        if (!empty($filters['user_id'])) {
            $where .= ' AND a.user_id = :user_id';
            $bindings['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['target_type'])) {
            $where .= ' AND a.target_type = :target_type';
            $bindings['target_type'] = $filters['target_type'];
        }
        if (!empty($filters['from'])) {
            $where .= ' AND a.created_at >= :from';
            $bindings['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where .= ' AND a.created_at <= :to';
            $bindings['to'] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (a.action LIKE :q OR a.ip LIKE :q OR u.email LIKE :q)';
            $bindings['q'] = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
        }

        $where = TenantScope::constrain($where, $bindings, 'a');

        $total = (int) DB::scalar(
            'SELECT COUNT(*) FROM ' . self::tableName() . ' a
             LEFT JOIN ' . DB::table('users') . ' u ON u.id = a.user_id
             WHERE ' . $where,
            $bindings
        );

        $rows = DB::select(
            'SELECT a.*, u.name AS user_name, u.email AS user_email, i.email AS impersonator_email
             FROM ' . self::tableName() . ' a
             LEFT JOIN ' . DB::table('users') . ' u ON u.id = a.user_id
             LEFT JOIN ' . DB::table('users') . ' i ON i.id = a.impersonator_id
             WHERE ' . $where . '
             ORDER BY a.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bindings
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /** @return list<string> distinct actions, for the filter dropdown */
    public static function distinctActions(): array
    {
        $where = '1 = 1';
        $bindings = [];
        $where = TenantScope::constrain($where, $bindings);

        $rows = DB::select(
            'SELECT DISTINCT action FROM ' . self::tableName() . ' WHERE ' . $where . ' ORDER BY action LIMIT 200',
            $bindings
        );

        return array_map(static fn (array $r): string => (string) $r['action'], $rows);
    }

    public static function prune(int $retentionDays): int
    {
        return TenantScope::acrossAllTenants('audit retention', static fn (): int => DB::execute(
            'DELETE FROM ' . self::tableName() . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY) LIMIT 10000',
            ['d' => $retentionDays]
        )->rowCount());
    }
}
