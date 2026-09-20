<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Middleware\SecurityHeadersMiddleware;
use App\Models\Notification;
use App\Models\Tenant;

/**
 * Shared controller behaviour: rendering with the standard page context,
 * redirect-with-flash, and the pagination/sort parsing every list screen needs.
 */
abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $this->shareViewContext();

        return Response::html(View::render($template, $data), $status);
    }

    protected function redirect(string $path, string $successMessage = '', string $errorMessage = ''): Response
    {
        if ($successMessage !== '') {
            Session::flash('success', $successMessage);
        }
        if ($errorMessage !== '') {
            Session::flash('error', $errorMessage);
        }

        return Response::redirect(str_starts_with($path, 'http') ? $path : url($path));
    }

    protected function back(Request $request, string $successMessage = '', string $errorMessage = ''): Response
    {
        $referer = $request->header('Referer');
        $target = $referer !== null && $this->isOwnHost($referer) ? $referer : url('dashboard');

        if ($successMessage !== '') {
            Session::flash('success', $successMessage);
        }
        if ($errorMessage !== '') {
            Session::flash('error', $errorMessage);
        }

        return Response::redirect($target);
    }

    /**
     * Page/per-page/sort, validated.
     *
     * The sort column is checked against an explicit allow-list rather than
     * passed to the model, so a crafted `?sort=` can never reach SQL.
     *
     * @param list<string> $sortable
     * @return array{page:int,per_page:int,sort:string,direction:string,q:string}
     */
    protected function listParams(Request $request, array $sortable, string $defaultSort = 'id'): array
    {
        $sort = (string) $request->query('sort', $defaultSort);
        if (!in_array($sort, $sortable, true)) {
            $sort = $defaultSort;
        }

        return [
            'page'      => max(1, (int) $request->query('page', '1')),
            'per_page'  => min(200, max(5, (int) $request->query('per_page', '25'))),
            'sort'      => $sort,
            'direction' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC',
            'q'         => trim((string) $request->query('q', '')),
        ];
    }

    /**
     * Data every rendered page needs: the signed-in user, branding, flash
     * messages, the CSP nonce and the notification count.
     */
    protected function shareViewContext(): void
    {
        $user = Auth::user();

        View::share('auth_user', $user);
        View::share('auth_role', Auth::role());
        View::share('is_super_admin', Auth::isSuperAdmin());
        View::share('is_impersonating', Auth::isImpersonating());
        View::share('csp_nonce', SecurityHeadersMiddleware::nonce());
        View::share('app_version', (string) Config::get('app.version', ''));

        $tenant = null;
        $tenantId = $user !== null && $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        if ($tenantId !== null) {
            $tenant = \App\Middleware\TenantScope::acrossAllTenants(
                'page branding lookup',
                static fn (): ?array => Tenant::find($tenantId)
            );
        }

        View::share('tenant', $tenant);
        View::share('tenant_branding', is_array($tenant['branding_json'] ?? null) ? $tenant['branding_json'] : []);
        View::share('timezone', (string) ($user['timezone'] ?? ($tenant['timezone'] ?? Config::get('app.timezone', 'UTC'))));

        View::share('unread_notifications', $user !== null ? Notification::unreadCount((int) $user['id']) : 0);

        // Flash values are read-and-clear, so they must be pulled exactly once
        // per render — here.
        View::share('flash_success', Session::flash('success'));
        View::share('flash_error', Session::flash('error'));
        View::share('flash_warning', Session::flash('warning'));
        View::share('errors', Session::flash('errors') ?? []);
        View::share('old', Session::flash('old') ?? []);
    }

    private function isOwnHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return true;
        }

        return $host === parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
    }
}
