<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Logger;
use App\Core\RateLimit;
use App\Core\Request;
use App\Core\Response;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;

/**
 * Bearer-token authentication for the API.
 *
 * Two kinds of bearer token are accepted, and they are deliberately not
 * interchangeable:
 *
 *   ak_live_… / ak_test_…  a tenant API key. Establishes the tenant scope and
 *                          narrows permissions to the key's scopes.
 *   anything else          a device token. Establishes the tenant scope but
 *                          grants nothing except the device's own endpoints.
 *
 * In both cases the token is what sets the tenant context, so the lookup is
 * necessarily unscoped and the result is adopted immediately as the scope.
 */
final class ApiKeyMiddleware
{
    public static function handle(Request $request): ?Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            // A session may still authenticate an API call from the dashboard's
            // own JavaScript; fall through to AuthMiddleware in that case.
            if (Auth::user() !== null) {
                return null;
            }

            return self::missingCredential($request, 'Provide an API key as a Bearer token.');
        }

        return str_starts_with($token, 'ak_')
            ? self::authenticateApiKey($request, $token)
            : self::authenticateDevice($request, $token);
    }

    /** Device endpoints (enroll aside) authenticate only with a device token. */
    public static function handleDevice(Request $request): ?Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return self::missingCredential($request, 'Provide the device token as a Bearer token.');
        }

        return self::authenticateDevice($request, $token);
    }

    /**
     * No credential at all — which is not the same as a rejected one.
     *
     * On Apache with PHP-FPM the Authorization header is dropped unless the
     * server is told to pass it, and then *every* request arrives like this.
     * A production deployment spent an evening on it: the panel said "provide
     * the device token", the agent concluded the device had been revoked, and
     * told the customer to run `reset` — which would have destroyed a working
     * device's identity to fix a web server setting.
     *
     * So the message names the likely cause, and a distinct error code lets
     * the agent tell "you sent nothing" apart from "what you sent is no
     * good". The two need different advice and only one of them is the
     * customer's problem.
     */
    private static function missingCredential(Request $request, string $what): Response
    {
        $hint = '';
        if ($request->authorizationHeader() === null && self::looksLikeApacheFastCGI($request)) {
            $hint = ' This server did not pass the Authorization header to PHP at all, which Apache'
                . ' does not do by default with PHP-FPM. The .htaccess this software ships sets'
                . ' CGIPassAuth On; check it is present and that AllowOverride permits it.';

            Logger::warning('security', 'Authorization header absent; the web server is probably stripping it', [
                'ip'       => $request->ip(),
                'software' => $request->server('SERVER_SOFTWARE'),
            ]);
        }

        return Response::apiError($what . $hint, 401, 'no_credential');
    }

    /** Apache with a FastCGI PHP is the combination that drops the header. */
    private static function looksLikeApacheFastCGI(Request $request): bool
    {
        $software = strtolower((string) $request->server('SERVER_SOFTWARE'));
        if (!str_contains($software, 'apache')) {
            return false;
        }

        return str_contains(strtolower(PHP_SAPI), 'fpm') || str_contains(strtolower(PHP_SAPI), 'cgi');
    }

    private static function authenticateApiKey(Request $request, string $token): ?Response
    {
        $key = ApiKey::authenticate($token);

        if ($key === null) {
            Logger::warning('security', 'API key rejected', ['ip' => $request->ip(), 'prefix' => substr($token, 0, 12)]);
            // Same message for unknown, revoked and expired: an API client has
            // no legitimate need to tell them apart, an attacker does.
            return Response::apiError('Invalid or expired API key.', 401, 'unauthenticated');
        }

        $tenantId = (int) $key['tenant_id'];

        $tenant = TenantScope::acrossAllTenants(
            'api key tenant lookup',
            static fn (): ?array => Tenant::find($tenantId)
        );

        if ($tenant === null || in_array($tenant['status'], ['cancelled'], true)) {
            return Response::apiError('This account is no longer active.', 403, 'account_inactive');
        }

        $scopes = $key['scopes_json'] ?? [];
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? $decoded : [];
        }

        // The key acts as its creating user, so it can never exceed what that
        // user could do. A key whose owner was deleted acts as read-only.
        $owner = $key['user_id'] !== null
            ? TenantScope::acrossAllTenants('api key owner lookup', static fn (): ?array => User::findActiveById((int) $key['user_id']))
            : null;

        Auth::setApiActor($owner, $tenantId, is_array($scopes) ? array_map('strval', $scopes) : []);

        $limit = (int) Config::get('security.api_rate_per_minute', 120);
        $response = self::enforceRateLimit('apikey:' . $key['id'], $limit, 60);
        if ($response !== null) {
            return $response;
        }

        ApiKey::touch((int) $key['id'], $request->ip());

        return null;
    }

    private static function authenticateDevice(Request $request, string $token): ?Response
    {
        $device = Device::findByToken($token);

        if ($device === null) {
            Logger::warning('agent', 'Device token rejected', ['ip' => $request->ip()]);

            // 401 with an explicit code: the agent uses this to distinguish
            // "re-enrol" from "retry later".
            return Response::apiError('Device is not authorized. Re-enrol this device.', 401, 'device_unauthorized');
        }

        $tenantId = (int) $device['tenant_id'];
        Auth::setDeviceActor($tenantId);

        // Devices heartbeat frequently by design; the limit is per device and
        // generous enough that a normal agent never approaches it.
        $response = self::enforceRateLimit('device:' . $device['id'], 120, 60);
        if ($response !== null) {
            return $response;
        }

        $request->setDeviceContext($device);

        return null;
    }

    private static function enforceRateLimit(string $key, int $max, int $window): ?Response
    {
        try {
            RateLimit::enforce($key, $max, $window);
        } catch (\App\Core\RateLimitException $e) {
            return Response::apiError('Rate limit exceeded. Slow down.', 429, 'rate_limited', [
                'retry_after' => $e->retryAfter,
            ])->header('Retry-After', (string) $e->retryAfter);
        }

        return null;
    }
}
