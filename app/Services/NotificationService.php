<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Mailer;
use App\Models\Notification;
use App\Models\User;

/**
 * In-app notifications plus optional email delivery.
 *
 * Email is sent inline for critical alerts (update failed, backup failed) and
 * queued for anything else, so a slow SMTP server cannot stall a web request.
 */
final class NotificationService
{
    /** @param array<string,mixed> $options */
    public static function notifyUser(
        int $userId,
        string $level,
        string $title,
        string $body = '',
        string $link = '',
        string $category = '',
        bool $email = false
    ): int {
        $id = Notification::create([
            'user_id'  => $userId,
            'level'    => in_array($level, ['info', 'success', 'warning', 'critical'], true) ? $level : 'info',
            'title'    => substr($title, 0, 190),
            'body'     => $body,
            'link'     => $link !== '' ? substr($link, 0, 255) : null,
            'category' => $category !== '' ? substr($category, 0, 48) : null,
        ]);

        if ($email) {
            self::emailNotification($userId, $title, $body, $link);
        }

        return $id;
    }

    /**
     * Alert every platform super admin. Used for update/backup failures and
     * relay outages — things nobody else can act on.
     */
    public static function notifySuperAdmins(
        string $level,
        string $title,
        string $body = '',
        string $link = '',
        string $category = '',
        bool $email = true
    ): int {
        $sent = 0;
        foreach (User::superAdmins() as $admin) {
            self::notifyUser((int) $admin['id'], $level, $title, $body, $link, $category, $email);
            $sent++;
        }

        Logger::info('app', 'Platform alert raised', ['title' => $title, 'level' => $level, 'recipients' => $sent]);

        return $sent;
    }

    /** Alert every administrator of one tenant. */
    public static function notifyTenantAdmins(
        int $tenantId,
        string $level,
        string $title,
        string $body = '',
        string $link = '',
        string $category = '',
        bool $email = false
    ): int {
        $admins = \App\Middleware\TenantScope::asTenant(
            $tenantId,
            static fn (): array => User::where(['tenant_id' => $tenantId, 'role' => 'company_admin', 'status' => 'active'])
        );

        $sent = 0;
        foreach ($admins as $admin) {
            self::notifyUser((int) $admin['id'], $level, $title, $body, $link, $category, $email);
            $sent++;
        }

        return $sent;
    }

    private static function emailNotification(int $userId, string $title, string $body, string $link): void
    {
        $user = \App\Middleware\TenantScope::acrossAllTenants(
            'notification email lookup',
            static fn (): ?array => User::findActiveById($userId)
        );
        if ($user === null) {
            return;
        }

        $brand = (string) Config::get('brand.name', 'Panel');
        $linkHtml = $link !== ''
            ? '<p><a href="' . htmlspecialchars(rtrim((string) Config::get('app.url', ''), '/') . '/' . ltrim($link, '/'), ENT_QUOTES) . '">Open in ' . htmlspecialchars($brand, ENT_QUOTES) . '</a></p>'
            : '';

        $html = '<h2>' . htmlspecialchars($title, ENT_QUOTES) . '</h2>'
            . '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</p>'
            . $linkHtml
            . '<hr><p style="color:#666;font-size:12px">Sent by ' . htmlspecialchars($brand, ENT_QUOTES) . '</p>';

        $result = Mailer::fromConfig()->send((string) $user['email'], (string) $user['name'], '[' . $brand . '] ' . $title, $html, $body);

        if ($result['success']) {
            \App\Core\DB::execute(
                'UPDATE ' . \App\Core\DB::table('notifications') . '
                 SET emailed_at = UTC_TIMESTAMP() WHERE user_id = :u AND emailed_at IS NULL ORDER BY id DESC LIMIT 1',
                ['u' => $userId]
            );
        }
    }
}
