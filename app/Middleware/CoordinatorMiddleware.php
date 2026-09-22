<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\CoordinatorSettings;
use App\Services\EdgeRelease;

/**
 * Authenticates the coordinator service to the panel (§7.3).
 *
 * The coordinator is not a user and not a device: it is another service run by
 * the same operator, and it asks questions no customer may ask — "is this
 * device token valid", "who may this device talk to". So it authenticates with
 * a shared secret rather than a session or a device token.
 *
 * The secret is never sent. Each request carries an HMAC-SHA256 of its
 * timestamp and body, which means a captured request cannot be replayed
 * outside its window and cannot be altered at all.
 */
final class CoordinatorMiddleware
{
    /** How far apart the two clocks may be before a request is refused. */
    private const MAX_SKEW_SECONDS = 300;

    public static function handle(Request $request): ?Response
    {
        $secret = (string) CoordinatorSettings::current()['shared_secret'];
        if ($secret === '') {
            // Refusing is the safe default: an unconfigured secret must not
            // silently become an unauthenticated endpoint.
            return Response::apiError('The coordinator interface is not configured.', 503, 'not_configured');
        }

        $timestamp = (string) ($request->header('X-Coordinator-Timestamp') ?? '');
        $signature = (string) ($request->header('X-Coordinator-Signature') ?? '');

        if ($timestamp === '' || $signature === '') {
            return self::reject($request, 'missing signature headers');
        }

        if (!self::withinSkew($timestamp)) {
            return self::reject($request, 'timestamp outside the accepted window');
        }

        $expected = hash_hmac('sha256', $timestamp . "\n" . $request->rawBody(), $secret);

        // hash_equals, not ===: a timing-variable comparison here would leak
        // the expected digest one byte at a time.
        if (!hash_equals($expected, $signature)) {
            return self::reject($request, 'signature mismatch');
        }

        // Recorded only after the signature checks out, so an unauthenticated
        // caller cannot write anything the panel displays.
        $version = (string) ($request->header('X-Coordinator-Version') ?? '');
        if ($version !== '') {
            EdgeRelease::noteCoordinatorVersion($version);
        }

        return null;
    }

    private static function withinSkew(string $timestamp): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= self::MAX_SKEW_SECONDS;
    }

    private static function reject(Request $request, string $why): Response
    {
        Logger::warning('security', 'Coordinator request rejected', [
            'ip'     => $request->ip(),
            'reason' => $why,
        ]);

        // One message for every cause: a caller that cannot sign has no
        // legitimate need to know which part it got wrong.
        return Response::apiError('Coordinator authentication failed.', 401, 'unauthenticated');
    }
}
