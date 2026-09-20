<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\RateLimit;
use App\Core\RateLimitException;
use App\Core\Request;
use App\Core\Response;

/**
 * Per-IP throttling for unauthenticated, abusable endpoints: login, password
 * reset, signup and device enrolment.
 *
 * Authenticated API traffic is limited per key in ApiKeyMiddleware instead,
 * where the limit can be attributed to an account rather than an address.
 */
final class RateLimitMiddleware
{
    public static function handle(Request $request, string $bucket = 'default'): ?Response
    {
        [$max, $window] = self::limitsFor($bucket);

        $key = sprintf('%s:%s', $bucket, $request->ip());

        try {
            RateLimit::enforce($key, $max, $window);
        } catch (RateLimitException $e) {
            if ($request->wantsJson()) {
                return Response::apiError('Too many requests. Please wait and try again.', 429, 'rate_limited', [
                    'retry_after' => $e->retryAfter,
                ])->header('Retry-After', (string) $e->retryAfter);
            }

            throw $e;
        }

        return null;
    }

    /** @return array{0:int,1:int} max attempts, window in seconds */
    private static function limitsFor(string $bucket): array
    {
        return match ($bucket) {
            'login'  => [(int) Config::get('security.login_rate_per_15min', 20), 900],
            'reset'  => [5, 900],
            'signup' => [5, 3600],
            'enroll' => [(int) Config::get('security.enroll_rate_per_hour', 60), 3600],
            default  => [(int) Config::get('security.api_rate_per_minute', 120), 60],
        };
    }
}
