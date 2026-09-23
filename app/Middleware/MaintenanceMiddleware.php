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

        // The link `php cli/maintenance.php on` prints: ?maintenance_bypass=<token>.
        // It used to be printed and never read, so the one way in the command
        // offered answered 503 like everything else. A valid token is kept in
        // the session, so the rest of the visit does not need it in the URL.
        //
        // Looked at whatever is stored already. Each maintenance window has a
        // token of its own, and the one a browser kept from the last window —
        // in its session, or the cookie a panel update sets — is stale in the
        // next: when a stored value stopped the link being read, the second
        // drill of the day was locked out by the first.
        $fromLink = $request->query('maintenance_bypass');
        if (is_string($fromLink) && $fromLink !== ''
            && $maintenance->allows('', $fromLink)) {
            Session::set('maintenance_bypass', $fromLink);
            $bypass = $fromLink;
        }

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
