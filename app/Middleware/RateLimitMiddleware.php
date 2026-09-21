<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Logger;
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
            // Enrolment is checked against two counters, not one. See
            // enforceEnrolment() for why counting successes strictly is the
            // wrong control for this endpoint.
            if ($bucket === 'enroll') {
                self::enforceEnrolment($request->ip(), $max, $window);
            } else {
                RateLimit::enforce($key, $max, $window);
            }
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

    /**
     * Throttle enrolment on failures, not on volume.
     *
     * A single office is one public address. Forty machines enrolling in one
     * afternoon is forty enrolments plus their claim polls, and a limit that
     * counted those the same way a brute-force attempt is counted cut the
     * installation off partway through — in front of the customer, with a
     * message that sounded like our fault because it was.
     *
     * What is worth limiting tightly is a *failed* enrolment: a join code that
     * does not exist, has expired, or is used up. That is the only shape the
     * abuse takes, because a successful enrolment requires a code an
     * administrator issued and every code carries its own use limit. So:
     *
     *   failures   strict, and the strictness is what stops enumeration
     *   everything  generous, high enough that a real rollout never meets it,
     *               low enough that a flood still stops
     *
     * The failure counter is recorded by the controller after the attempt,
     * through RateLimitMiddleware::recordEnrolmentFailure().
     */
    private static function enforceEnrolment(string $ip, int $max, int $window): void
    {
        // peek(), not enforce(). enforce() increments as it checks, so using
        // it here made every enrolment count as a failure: fifteen requests in,
        // a legitimate rollout throttled itself. The drill caught it at twelve
        // of fifty.
        //
        // The failure counter is incremented by the controller, and only when
        // an attempt actually failed — which is the whole point of separating
        // the two.
        $failures = RateLimit::peek(sprintf('enroll_fail:%s', $ip), 900);
        $allowed = (int) Config::get('security.enroll_failures_per_15min', 15);

        if ($failures > $allowed) {
            Logger::warning('security', 'Enrolment blocked: too many failed attempts', [
                'ip'       => $ip,
                'failures' => $failures,
                'limit'    => $allowed,
            ]);

            throw new RateLimitException(900 - (time() % 900));
        }

        RateLimit::enforce(sprintf('enroll:%s', $ip), $max, $window);
    }

    /**
     * Count one failed enrolment or claim against the strict counter.
     *
     * Called by the controller, because only the controller knows whether the
     * join code was real. The middleware runs before that is decided.
     */
    public static function recordEnrolmentFailure(string $ip): void
    {
        RateLimit::hit(sprintf('enroll_fail:%s', $ip), 900);
    }

    /** @return array{0:int,1:int} max attempts, window in seconds */
    private static function limitsFor(string $bucket): array
    {
        return match ($bucket) {
            'login'  => [(int) Config::get('security.login_rate_per_15min', 20), 900],
            'reset'  => [5, 900],
            'signup' => [5, 3600],
            // Generous on purpose: this is the flood ceiling, not the abuse
            // control. Fifty devices enrolling and then polling for approval
            // is several hundred requests from one address in an hour, and
            // every one of them is legitimate.
            //
            // A NEW key, deliberately. The old `enroll_rate_per_hour` was a
            // combined limit of 60 and is written into every config.php
            // already installed — and config.php is a protected path the
            // updater never overwrites, so raising its default would have
            // changed nothing for existing customers while looking like it
            // had. It did exactly that once: a drill passed because the old
            // ceiling throttled the guessing, not because the failure counter
            // did, and the two are not the same control.
            'enroll' => [(int) Config::get('security.enroll_requests_per_hour', 1200), 3600],
            default  => [(int) Config::get('security.api_rate_per_minute', 120), 60],
        };
    }
}
