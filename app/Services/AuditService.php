<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;
use App\Middleware\TenantScope;

/**
 * Writes the audit trail.
 *
 * Two properties matter: it never throws (an audit failure must not break the
 * action being audited — it is logged instead), and before/after payloads are
 * passed through Logger::redact() so a password hash or token that happens to
 * be in a changed row never lands in the table.
 */
final class AuditService
{
    /** Columns never recorded in before/after snapshots. */
    private const NEVER_RECORD = [
        'password_hash', 'twofa_secret', 'twofa_recovery_json', 'token_hash',
        'token_encrypted', 'key_hash', 'payload', 'private_key',
    ];

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $before = null,
        ?array $after = null,
        string $result = 'success'
    ): void {
        try {
            $request = Request::current();

            $tenantId = null;
            try {
                $tenantId = Auth::tenantId();
            } catch (\Throwable) {
                // A platform action has no tenant; that is recorded as NULL.
            }

            TenantScope::acrossAllTenants('audit write', static function () use (
                $tenantId, $action, $targetType, $targetId, $before, $after, $result, $request
            ): void {
                DB::execute(
                    'INSERT INTO ' . DB::table('audit_logs') . '
                        (tenant_id, user_id, impersonator_id, actor_type, action, target_type, target_id,
                         ip, user_agent, before_json, after_json, result, created_at)
                     VALUES
                        (:tenant_id, :user_id, :impersonator_id, :actor_type, :action, :target_type, :target_id,
                         :ip, :user_agent, :before_json, :after_json, :result, UTC_TIMESTAMP())',
                    [
                        'tenant_id'       => $tenantId,
                        'user_id'         => Auth::id(),
                        'impersonator_id' => Auth::impersonatorId(),
                        'actor_type'      => Auth::actorType(),
                        'action'          => substr($action, 0, 64),
                        'target_type'     => $targetType !== null ? substr($targetType, 0, 48) : null,
                        'target_id'       => $targetId,
                        'ip'              => $request?->ip(),
                        'user_agent'      => $request?->userAgent(),
                        'before_json'     => self::encode($before),
                        'after_json'      => self::encode($after),
                        'result'          => $result === 'failure' ? 'failure' : 'success',
                    ]
                );
            });
        } catch (\Throwable $e) {
            // Auditing must never be the reason an action fails.
            Logger::error('security', 'Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Record only what actually changed between two rows.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function logChange(string $action, string $targetType, int $targetId, array $before, array $after): void
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach ($after as $key => $value) {
            if (in_array($key, self::NEVER_RECORD, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            if ((string) json_encode($old) !== (string) json_encode($value)) {
                $changedBefore[$key] = $old;
                $changedAfter[$key] = $value;
            }
        }

        if ($changedAfter === []) {
            return; // A no-op save is not an audit event.
        }

        self::log($action, $targetType, $targetId, $changedBefore, $changedAfter);
    }

    /** @param array<string,mixed>|null $data */
    private static function encode(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        foreach (self::NEVER_RECORD as $key) {
            unset($data[$key]);
        }

        /** @var array<string,mixed> $redacted */
        $redacted = Logger::redact($data);
        $encoded = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            return null;
        }

        // Keep one row from being enormous; the full picture lives in the logs.
        return strlen($encoded) > 60000 ? substr($encoded, 0, 60000) : $encoded;
    }
}
