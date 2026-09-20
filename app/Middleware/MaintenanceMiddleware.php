<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Updater\MaintenanceMode;

/**
 * Serves 503 while an update is in progress.
 *
 * The operator driving the update is let through by IP or by the bypass cookie
 * set when maintenance mode was enabled — otherwise they would lock themselves
 * out of the progress screen they are watching.
 *
 * /health.php is intentionally not routed through here: monitoring must still
 * get an answer during maintenance.
 */
final class MaintenanceMiddleware
{
    public static function handle(Request $request): ?Response
    {
        $maintenance = MaintenanceMode::make();

        if (!$maintenance->isEnabled()) {
            return null;
        }

        $bypass = $request->cookie(MaintenanceMode::cookieName())
            ?? (is_string(Session::get('maintenance_bypass')) ? (string) Session::get('maintenance_bypass') : null);

        if ($maintenance->allows($request->ip(), $bypass)) {
            return null;
        }

        $retryAfter = $maintenance->retryAfter();
        $details = $maintenance->details() ?? [];

        if ($request->wantsJson()) {
            return Response::apiError(
                'The panel is temporarily unavailable while an update is applied.',
                503,
                'maintenance',
                ['retry_after' => $retryAfter]
            )->header('Retry-After', (string) $retryAfter);
        }

        return Response::html(
            View::render('errors.maintenance', [
                'reason'      => (string) ($details['reason'] ?? 'Scheduled maintenance'),
                'retry_after' => $retryAfter,
            ]),
            503
        )->header('Retry-After', (string) $retryAfter);
    }
}
