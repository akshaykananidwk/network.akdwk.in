<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Rbac;
use App\Core\Totp;
use App\Middleware\TenantScope;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\JoinCode;
use App\Models\Network;
use App\Models\Setting;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CoordinatorSettings;
use App\Services\DeviceService;
use App\Services\NetworkService;

/**
 * End-to-end tests over real HTTP.
 *
 * These exercise the parts that only behave correctly on the wire: session
 * cookies, redirects, the CSRF round-trip, middleware ordering, security
 * headers, and the API's bearer-token authentication.
 *
 * Fixtures are committed (an HTTP request runs in its own process and cannot
 * see an open transaction) and removed in a finally block.
 */
final class HttpTests
{
    private static string $baseUrl = '';
    /** @var array<string,mixed> */
    private static array $fixtures = [];

    public static function run(string $baseUrl): void
    {
        self::$baseUrl = $baseUrl;

        // The suite deliberately trips the login limiter, and enrolment has a
        // limiter of its own. Left behind, that state makes the next run fail
        // with 429s that look like broken endpoints — so each run starts from
        // a clean slate rather than inheriting the last one's.
        DB::execute('DELETE FROM ' . DB::table('rate_limits'));

        try {
            self::createFixtures();
            self::publicSurface();
            self::securityHeaders();
            self::authentication();
            self::csrfProtection();
            self::authorisation();
            self::crossTenantOverHttp();
            self::apiAuthentication();
            self::joinCodeForms();
            self::agentEndpoints();
            self::rateLimiting();
            // 1.9.2: the edge's own upgrade path, end to end over HTTP.
            self::edgeUpgrade();
            self::installLink();
            self::selfUpdate();
        } finally {
            self::removeFixtures();
        }
    }

    // ------------------------------------------------------------ fixtures

    private static function createFixtures(): void
    {
        TestCase::group('HTTP fixtures');

        $plan = Plan::findBySlug('business') ?? Plan::publicPlans()[0];
        $suffix = bin2hex(random_bytes(4));

        foreach (['alpha', 'beta'] as $key) {
            $tenantId = TenantScope::acrossAllTenants('http fixture', static fn (): int => Tenant::create([
                'company_name'  => ucfirst($key) . ' HTTP Test',
                'slug'          => 'http-' . $key . '-' . $suffix,
                'email'         => $key . '-' . $suffix . '@http.test',
                'plan_id'       => (int) $plan['id'],
                'device_limit'  => 20,
                'network_limit' => 5,
                'user_limit'    => 5,
                'status'        => 'active',
            ]));

            $password = 'HttpTest!' . strtoupper($key) . '2026';
            $email = $key . '-' . $suffix . '@http.test';

            $userId = TenantScope::asTenant($tenantId, static fn (): int => User::create([
                'tenant_id'     => $tenantId,
                'name'          => ucfirst($key) . ' Admin',
                'email'         => $email,
                'password_hash' => Crypto::hashPassword($password),
                'role'          => Rbac::COMPANY_ADMIN,
                'status'        => 'active',
            ]));

            self::$fixtures[$key] = [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'email'     => $email,
                'password'  => $password,
            ];
        }

        // A network and an approved device for Alpha, and a network for Beta.
        foreach (['alpha' => '10.121.0.0/24', 'beta' => '10.122.0.0/24'] as $key => $cidr) {
            $tenantId = self::$fixtures[$key]['tenant_id'];

            TenantScope::asTenant($tenantId, static function () use ($key, $cidr, $tenantId): void {
                \App\Core\Auth::setApiActor(null, $tenantId, ['*']);

                $network = NetworkService::create(['name' => ucfirst($key) . ' Net', 'cidr' => $cidr]);
                self::$fixtures[$key]['network_id'] = (int) $network['id'];
                self::$fixtures[$key]['network_name'] = (string) $network['name'];

                $code = JoinCode::issue($tenantId, (int) $network['id'], null, 0, 120);
                self::$fixtures[$key]['join_code'] = $code['code'];

                $enrolment = DeviceService::enroll([
                    'join_code'     => $code['code'],
                    'public_key'    => base64_encode(random_bytes(32)),
                    'hostname'      => $key . '-http-01',
                    'os'            => 'linux',
                    'agent_version' => '1.0.0',
                ]);

                $device = Device::findByUid($enrolment['device_uid']);
                $approval = DeviceService::approve((int) $device['id']);

                self::$fixtures[$key]['device_id'] = (int) $device['id'];
                self::$fixtures[$key]['device_uid'] = $enrolment['device_uid'];
                self::$fixtures[$key]['device_token'] = $approval['token'];
            });

            \App\Core\Auth::reset();
            TenantScope::reset();
        }

        // An API key for Alpha with a narrow scope, to prove scopes bite.
        $alphaKey = TenantScope::asTenant(self::$fixtures['alpha']['tenant_id'], static fn (): array => ApiKey::issue(
            self::$fixtures['alpha']['tenant_id'],
            self::$fixtures['alpha']['user_id'],
            'HTTP test key',
            ['network.view', 'device.view']
        ));
        self::$fixtures['alpha']['api_key'] = $alphaKey['plain'];

        TestCase::assert(isset(self::$fixtures['alpha']['device_token']), 'Alpha fixture built');
        TestCase::assert(isset(self::$fixtures['beta']['device_token']), 'Beta fixture built');
        TestCase::assert(str_starts_with(self::$fixtures['alpha']['api_key'], 'ak_'), 'API key issued');
    }

    private static function removeFixtures(): void
    {
        TenantScope::acrossAllTenants('http fixture cleanup', static function (): void {
            foreach (['alpha', 'beta'] as $key) {
                $tenantId = self::$fixtures[$key]['tenant_id'] ?? null;
                if ($tenantId === null) {
                    continue;
                }
                // Cascades remove networks, devices, allocations and keys.
                DB::execute('DELETE FROM ' . DB::table('tenants') . ' WHERE id = :id', ['id' => $tenantId]);
            }
        });
    }

    private static function client(): HttpClient
    {
        return new HttpClient(self::$baseUrl);
    }

    /** Sign in and return a client holding the session. */
    private static function signIn(string $key): HttpClient
    {
        $client = self::client();
        $client->get('/login');

        $client->post('/login', [
            '_token'   => $client->csrfToken(),
            'email'    => self::$fixtures[$key]['email'],
            'password' => self::$fixtures[$key]['password'],
        ]);

        return $client;
    }

    // ------------------------------------------------------- public surface

    private static function publicSurface(): void
    {
        TestCase::group('HTTP — public surface');

        $client = self::client();

        $client->get('/health.php');
        TestCase::assertSame(200, $client->status(), 'health endpoint returns 200');
        $health = $client->json();
        TestCase::assertSame('ok', $health['status'] ?? '', 'health reports ok');
        TestCase::assert(($health['checks']['database'] ?? false) === true, 'health confirms the database');
        TestCase::assertNotContains('password', strtolower($client->body()),
            'health leaks no credentials');

        $client->get('/');
        TestCase::assertSame(302, $client->status(), 'the root redirects');
        TestCase::assertContains('/login', (string) $client->location(), 'and it redirects to login');

        $client->get('/login');
        TestCase::assertSame(200, $client->status(), 'the login page renders');
        TestCase::assert($client->csrfToken() !== null, 'and carries a CSRF token');

        $client->get('/__rewrite_probe');
        TestCase::assertSame(200, $client->status(), 'the rewrite probe answers');

        // Application directories must not be served.
        foreach (['/config/config.php', '/app/Core/DB.php', '/storage/logs/app.log',
                  '/database/schema.sql', '/cli/worker.php', '/tests/run.php'] as $path) {
            $client->get($path);
            TestCase::assert(in_array($client->status(), [403, 404], true),
                'not served: ' . $path, 'HTTP ' . $client->status());
        }

        // .env must not be readable even though it sits in the web root.
        $client->get('/.env');
        TestCase::assert($client->status() !== 200 || !str_contains($client->body(), 'DB_PASS'),
            '.env does not expose the database password', 'HTTP ' . $client->status());

        $client->get('/dashboard');
        TestCase::assertSame(302, $client->status(), 'the dashboard requires a session');
        TestCase::assertContains('/login', (string) $client->location(), 'and redirects to login');

        $client->get('/admin/updates');
        TestCase::assertSame(302, $client->status(), 'the update screen requires a session');

        $client->get('/definitely-not-a-route');
        TestCase::assertSame(404, $client->status(), 'an unknown path returns 404');
        TestCase::assertNotContains('Fatal error', $client->body(), 'and not a stack trace');
    }

    private static function securityHeaders(): void
    {
        TestCase::group('HTTP — security headers (§11)');

        $client = self::client();
        $client->get('/login');

        $csp = (string) $client->header('Content-Security-Policy');
        TestCase::assert($csp !== '', 'Content-Security-Policy is set');
        TestCase::assertContains("default-src 'self'", $csp, 'CSP defaults to self');
        TestCase::assertContains("object-src 'none'", $csp, 'CSP blocks plugins');
        TestCase::assertContains("frame-ancestors 'none'", $csp, 'CSP blocks framing');
        TestCase::assertNotContains('unsafe-eval', $csp, "CSP does not allow 'unsafe-eval'");
        TestCase::assertNotContains("script-src 'self' 'unsafe-inline'", $csp,
            'CSP does not allow inline script without a nonce');
        TestCase::assertContains('nonce-', $csp, 'CSP uses a per-request nonce for inline script');

        TestCase::assertSame('nosniff', $client->header('X-Content-Type-Options'), 'X-Content-Type-Options');
        TestCase::assertSame('DENY', $client->header('X-Frame-Options'), 'X-Frame-Options');
        TestCase::assertSame('strict-origin-when-cross-origin', $client->header('Referrer-Policy'), 'Referrer-Policy');
        TestCase::assert(str_contains((string) $client->header('Permissions-Policy'), 'camera=()'),
            'Permissions-Policy restricts device APIs');
        TestCase::assert($client->header('X-Request-Id') !== null, 'a request id is returned for log correlation');

        // The nonce must change between requests, or it is not a nonce.
        $first = $client->header('Content-Security-Policy');
        $client->get('/login');
        TestCase::assert($first !== $client->header('Content-Security-Policy'),
            'the CSP nonce differs on every request');
    }

    // ------------------------------------------------------- authentication

    private static function authentication(): void
    {
        TestCase::group('HTTP — authentication');

        $client = self::client();
        $client->get('/login');
        $token = (string) $client->csrfToken();

        // Wrong password.
        $client->post('/login', [
            '_token'   => $token,
            'email'    => self::$fixtures['alpha']['email'],
            'password' => 'definitely-not-the-password',
        ]);
        TestCase::assertSame(302, $client->status(), 'a bad password redirects back');
        TestCase::assertContains('/login', (string) $client->location(), 'to the login page');

        $client->get('/login');
        $body = $client->body();
        TestCase::assertContains('do not match our records', $body, 'with a generic failure message');
        TestCase::assertNotContains('no such user', strtolower($body), 'that does not reveal whether the account exists');

        // Unknown address gets the identical message.
        $client->get('/login');
        $client->post('/login', [
            '_token'   => (string) $client->csrfToken(),
            'email'    => 'nobody-' . bin2hex(random_bytes(4)) . '@http.test',
            'password' => 'whatever',
        ]);
        $client->get('/login');
        TestCase::assertContains('do not match our records', $client->body(),
            'an unknown address gets the same message as a wrong password');

        // Correct credentials.
        $client = self::signIn('alpha');
        TestCase::assertSame(302, $client->status(), 'correct credentials redirect');
        TestCase::assertContains('/dashboard', (string) $client->location(), 'to the dashboard');

        $client->get('/dashboard');
        TestCase::assertSame(200, $client->status(), 'the dashboard renders once signed in');
        TestCase::assertContains('Alpha HTTP Test', $client->body(), 'and shows the tenant name');

        // Session cookie hygiene.
        $cookies = $client->cookies();
        TestCase::assert(isset($cookies['ak_session']), 'a session cookie is set');

        $fresh = self::client();
        $fresh->get('/login');
        $setCookie = (string) $fresh->header('Set-Cookie');
        TestCase::assertContains('HttpOnly', $setCookie, 'the session cookie is HttpOnly');
        TestCase::assertContains('SameSite=Lax', $setCookie, 'and SameSite=Lax');

        // Sign out.
        $client->get('/account');
        $client->post('/logout', ['_token' => (string) $client->csrfToken()]);
        $client->get('/dashboard');
        TestCase::assertSame(302, $client->status(), 'after signing out the dashboard is gone');

        // Two-factor: the installed super admin has it enabled, so a correct
        // password alone must not produce a session.
        $superAdmin = TenantScope::acrossAllTenants('2fa test lookup', static fn (): ?array => DB::selectOne(
            'SELECT * FROM ' . DB::table('users') . " WHERE role = 'super_admin' AND twofa_enabled = 1 LIMIT 1"
        ));

        if ($superAdmin === null) {
            TestCase::skip('two-factor challenge', 'no super admin has 2FA enabled');
        } else {
            $secret = Crypto::decrypt((string) $superAdmin['twofa_secret']);
            TestCase::assert($secret !== null, 'the stored 2FA secret decrypts with APP_KEY');
        }
    }

    private static function csrfProtection(): void
    {
        TestCase::group('HTTP — CSRF protection');

        $client = self::signIn('alpha');
        $client->get('/networks/new');

        // A POST with no token.
        $client->post('/networks', ['name' => 'No token', 'cidr' => '10.150.0.0/24']);
        TestCase::assert(in_array($client->status(), [403, 302], true),
            'a state-changing POST without a token is refused', 'HTTP ' . $client->status());

        // A POST with a forged token.
        $client->post('/networks', [
            '_token' => 'deadbeef.0000000000000000000000000000000000000000000000000000000000000000',
            'name'   => 'Forged token',
            'cidr'   => '10.151.0.0/24',
        ]);
        TestCase::assert(in_array($client->status(), [403, 302], true),
            'a POST with a forged token is refused', 'HTTP ' . $client->status());

        TenantScope::asTenant(self::$fixtures['alpha']['tenant_id'], static function (): void {
            \App\Core\Auth::setApiActor(null, self::$fixtures['alpha']['tenant_id'], ['*']);
            TestCase::assertSame(null, Network::findBy(['name' => 'No token']),
                'and no network was created');
            TestCase::assertSame(null, Network::findBy(['name' => 'Forged token']),
                'nor by the forged request');
        });
        \App\Core\Auth::reset();
        TenantScope::reset();

        // The same request with a valid token succeeds.
        $client->get('/networks/new');
        $client->post('/networks', [
            '_token' => (string) $client->csrfToken(),
            'name'   => 'Valid token network',
            'cidr'   => '10.152.0.0/24',
        ]);
        TestCase::assertSame(302, $client->status(), 'the same POST with a valid token is accepted');

        TenantScope::asTenant(self::$fixtures['alpha']['tenant_id'], static function (): void {
            \App\Core\Auth::setApiActor(null, self::$fixtures['alpha']['tenant_id'], ['*']);
            TestCase::assert(Network::findBy(['name' => 'Valid token network']) !== null,
                'and the network exists');
        });
        \App\Core\Auth::reset();
        TenantScope::reset();

        // A GET is never CSRF-checked.
        $client->get('/networks');
        TestCase::assertSame(200, $client->status(), 'GET requests are not blocked by CSRF');
    }

    private static function authorisation(): void
    {
        TestCase::group('HTTP — authorisation');

        $client = self::signIn('alpha');

        // A tenant admin must not reach the platform screens.
        foreach (['/admin/tenants', '/admin/updates', '/admin/backups', '/admin/relays'] as $path) {
            $client->get($path);
            TestCase::assertSame(403, $client->status(), 'a tenant admin is refused ' . $path);
        }

        $client->post('/admin/updates/start', ['_token' => (string) $client->csrfToken()]);
        TestCase::assert(in_array($client->status(), [403, 302], true),
            'a tenant admin cannot start an update', 'HTTP ' . $client->status());

        // Its own screens work.
        foreach (['/networks', '/devices', '/settings/users', '/settings/audit', '/account'] as $path) {
            $client->get($path);
            TestCase::assertSame(200, $client->status(), 'a tenant admin may open ' . $path);
        }
    }

    private static function crossTenantOverHttp(): void
    {
        TestCase::group('HTTP — cross-tenant access by URL (§20.C)');

        $client = self::signIn('alpha');

        $betaNetwork = self::$fixtures['beta']['network_id'];
        $betaDevice = self::$fixtures['beta']['device_id'];
        $alphaNetwork = self::$fixtures['alpha']['network_id'];

        // Its own rows are reachable.
        $client->get('/networks/' . $alphaNetwork);
        TestCase::assertSame(200, $client->status(), "Alpha can open its own network page");

        // Beta's are not — and the response must not confirm they exist.
        $client->get('/networks/' . $betaNetwork);
        TestCase::assertSame(404, $client->status(), "Alpha gets 404 for Beta's network page");
        TestCase::assertNotContains('Beta Net', $client->body(), "and the body leaks nothing");

        $client->get('/devices/' . $betaDevice);
        TestCase::assertSame(404, $client->status(), "Alpha gets 404 for Beta's device page");
        TestCase::assertNotContains('beta-http-01', $client->body(), 'and the hostname does not leak');

        // Writes by id are refused.
        $client->get('/networks/' . $alphaNetwork);
        $token = (string) $client->csrfToken();

        $client->post('/networks/' . $betaNetwork, [
            '_token' => $token, 'name' => 'Hijacked', 'cidr' => '10.122.0.0/24',
        ]);
        TestCase::assertSame(404, $client->status(), "Alpha cannot POST an update to Beta's network");

        $client->post('/devices/' . $betaDevice . '/revoke', ['_token' => $token]);
        TestCase::assertSame(404, $client->status(), "Alpha cannot revoke Beta's device");

        $client->post('/devices/' . $betaDevice . '/approve', ['_token' => $token]);
        TestCase::assertSame(404, $client->status(), "Alpha cannot approve Beta's device");

        // Beta's row is untouched.
        TenantScope::acrossAllTenants('cross-tenant verification', static function () use ($betaNetwork, $betaDevice): void {
            $network = DB::selectOne('SELECT name FROM ' . DB::table('networks') . ' WHERE id = :id', ['id' => $betaNetwork]);
            TestCase::assertSame('Beta Net', $network['name'] ?? '', "Beta's network was not renamed");

            $device = DB::selectOne('SELECT status FROM ' . DB::table('devices') . ' WHERE id = :id', ['id' => $betaDevice]);
            TestCase::assertSame('authorized', $device['status'] ?? '', "Beta's device was not revoked");
        });

        // The API path is scoped identically.
        $client->get('/api/v1/networks/' . $betaNetwork, ['Accept' => 'application/json']);
        TestCase::assertSame(404, $client->status(), "the API also refuses Beta's network");
        $payload = $client->json();
        TestCase::assert(($payload['success'] ?? true) === false, 'and returns the error envelope');
    }

    // --------------------------------------------------------------- API

    private static function apiAuthentication(): void
    {
        TestCase::group('HTTP — API authentication and scopes');

        $client = self::client();
        $apiKey = self::$fixtures['alpha']['api_key'];

        // No credential.
        $client->get('/api/v1/networks', ['Accept' => 'application/json']);
        TestCase::assertSame(401, $client->status(), 'the API rejects an unauthenticated request');
        $payload = $client->json();
        TestCase::assert(($payload['success'] ?? true) === false, 'with success=false');
        TestCase::assert(isset($payload['error']['code']), 'and an error code',
            (string) ($payload['error']['code'] ?? ''));

        // Bad credential.
        $client->get('/api/v1/networks', ['Authorization' => 'Bearer ak_live_totallyinvalidkey', 'Accept' => 'application/json']);
        TestCase::assertSame(401, $client->status(), 'an invalid API key is rejected');

        // Valid credential.
        $client = self::client();
        $client->get('/api/v1/networks', ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json']);
        TestCase::assertSame(200, $client->status(), 'a valid API key is accepted');

        $payload = $client->json();
        TestCase::assert(($payload['success'] ?? false) === true, 'the envelope reports success');
        TestCase::assert(isset($payload['data']), 'and carries data');
        TestCase::assert(isset($payload['meta']), 'and meta');
        TestCase::assert(array_key_exists('error', $payload), 'and a null error field');

        $names = array_column((array) $payload['data'], 'name');
        TestCase::assert(in_array('Alpha Net', $names, true), "the key sees its own tenant's networks");
        TestCase::assert(!in_array('Beta Net', $names, true), "and not another tenant's");

        // Scopes narrow what the key can do: this one has view scopes only.
        $client->post('/api/v1/networks', '{"name":"Scope test","cidr":"10.153.0.0/24"}', [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);
        TestCase::assertSame(403, $client->status(), 'a view-only key cannot create a network');

        $client->post('/api/v1/devices/' . self::$fixtures['alpha']['device_id'] . '/revoke', '{}', [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);
        TestCase::assertSame(403, $client->status(), 'a view-only key cannot revoke a device');

        // Device listing respects the tenant.
        $client->get('/api/v1/devices', ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json']);
        TestCase::assertSame(200, $client->status(), 'the key may list devices');
        $hostnames = array_column((array) $client->json()['data'], 'hostname');
        TestCase::assert(!in_array('beta-http-01', $hostnames, true), "and sees no other tenant's device");

        // A revoked key stops working immediately.
        TenantScope::asTenant(self::$fixtures['alpha']['tenant_id'], static function (): void {
            \App\Core\Auth::setApiActor(null, self::$fixtures['alpha']['tenant_id'], ['*']);
            $key = ApiKey::findBy(['name' => 'HTTP test key']);
            if ($key !== null) {
                ApiKey::revoke((int) $key['id']);
            }
        });
        \App\Core\Auth::reset();
        TenantScope::reset();

        $client->get('/api/v1/networks', ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json']);
        TestCase::assertSame(401, $client->status(), 'a revoked key is rejected at once');
    }

    /**
     * The edge upgrade path: ask, upload, serve.
     *
     * Exercised over real HTTP with a real HMAC, because that signature is the
     * only thing standing between an unauthenticated caller and publishing an
     * executable this panel hands to customers. A unit test of the service
     * would not have caught a middleware that forgot to read the raw body.
     */
    private static function edgeUpgrade(): void
    {
        TestCase::group('HTTP — the edge asks, uploads and is served (1.9.2)');

        $secret = (string) CoordinatorSettings::current()['shared_secret'];
        if ($secret === '') {
            TestCase::skip('edge upgrade', 'no coordinator shared secret is configured');

            return;
        }

        $client = self::client();

        // Whatever this panel had published is put back at the end. A test
        // that leaves a 9.9.9-test installer as the live download would be a
        // test that broke the thing it was checking.
        $before = [];
        foreach (['file', 'version', 'sha256', 'size', 'published_at'] as $key) {
            $before[$key] = Setting::get('edge.windows-setup.' . $key, null);
        }

        try {
            self::edgeUpgradeChecks($client, $secret);
        } finally {
            foreach ($before as $key => $value) {
                Setting::set('edge.windows-setup.' . $key, $value === null ? null : (string) $value);
            }
            Setting::flushCache();

            foreach (glob(APP_ROOT . '/storage/downloads/akconnect-setup-9.9.9-test.exe') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    /** @see self::edgeUpgrade() — the body, so the restore above is a finally. */
    private static function edgeUpgradeChecks(HttpClient $client, string $secret): void
    {
        // 1. What should the edge build?
        self::signedRequest($client, 'GET', '/api/v1/edge/release', '', $secret);
        TestCase::assertSame(200, $client->status(), 'a signed caller is told the release');

        $release = json_decode($client->body(), true);
        $target = is_array($release) ? ($release['data'] ?? []) : [];
        TestCase::assert(
            is_array($target) && ($target['version'] ?? '') !== '',
            'and the answer names a version',
            is_array($target) ? (string) ($target['version'] ?? '') : ''
        );

        // 2. An unsigned caller is told nothing.
        $client->get('/api/v1/edge/release', ['Accept' => 'application/json']);
        TestCase::assertSame(401, $client->status(), 'an unsigned caller is refused');

        // 3. Publish something, in two chunks, so the chunking is exercised
        //    rather than assumed.
        $payload = random_bytes(200000);
        $digest = hash('sha256', $payload);
        $half = (int) (strlen($payload) / 2);

        foreach ([[0, substr($payload, 0, $half)], [$half, substr($payload, $half)]] as [$offset, $chunk]) {
            $body = (string) json_encode([
                'kind'    => 'windows-setup',
                'version' => '9.9.9-test',
                'sha256'  => $digest,
                'offset'  => $offset,
                'total'   => strlen($payload),
                'data'    => base64_encode($chunk),
            ]);

            self::signedRequest($client, 'POST', '/api/v1/edge/artifact', $body, $secret);
            TestCase::assertSame(200, $client->status(), 'chunk at offset ' . $offset . ' accepted');
        }

        $result = json_decode($client->body(), true);
        $data = is_array($result) ? ($result['data'] ?? []) : [];
        TestCase::assert(
            is_array($data) && ($data['complete'] ?? false) === true,
            'the last chunk completes the artefact'
        );

        // 4. And a customer, with no credential at all, can fetch it.
        $client->get('/download/setup.exe');
        TestCase::assertSame(200, $client->status(), 'the installer downloads without signing in');
        TestCase::assertSame(
            $digest,
            hash('sha256', $client->body()),
            'and arrives byte-identical to what was published'
        );
        TestCase::assertContains(
            'attachment',
            (string) $client->header('Content-Disposition'),
            'served as a download rather than rendered'
        );

        // 5. Bytes that do not match the digest are refused, not published.
        $body = (string) json_encode([
            'kind'    => 'windows-setup',
            'version' => '9.9.9-test',
            'sha256'  => str_repeat('0', 64),
            'offset'  => 0,
            'total'   => 8,
            'data'    => base64_encode('12345678'),
        ]);
        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', $body, $secret);
        TestCase::assertSame(422, $client->status(), 'an artefact that fails its checksum is discarded');

        // The published artefact is still the good one.
        $client->get('/download/setup.exe');
        TestCase::assertSame(
            $digest,
            hash('sha256', $client->body()),
            'and the refusal did not replace what was already published'
        );
    }

    /**
     * §14 over HTTP: a published agent binary is offered to a device, signed.
     *
     * This is the half of self-update the panel is responsible for. The other
     * half — verifying the signature and swapping the binary — is in Go, in
     * services/agent/internal/selfupdate. They meet at exactly two things: the
     * signature is ed25519 over the lowercase hex digest, and the download is
     * device-authenticated. Both are asserted here.
     */
    private static function selfUpdate(): void
    {
        TestCase::group('HTTP — a device is offered a signed agent (§14)');

        $secret = (string) CoordinatorSettings::current()['shared_secret'];
        $controllerKey = (string) Config::get('security.controller_public_key', '');

        if ($secret === '' || $controllerKey === '') {
            TestCase::skip('self-update', 'this panel has no coordinator secret or controller key');

            return;
        }

        $before = [];
        foreach (['file', 'version', 'sha256', 'size', 'published_at'] as $key) {
            $before[$key] = Setting::get('edge.windows-agent.' . $key, null);
        }

        try {
            self::selfUpdateChecks($secret, $controllerKey);
        } finally {
            foreach ($before as $key => $value) {
                Setting::set('edge.windows-agent.' . $key, $value === null ? null : (string) $value);
            }
            Setting::flushCache();

            DB::execute(
                'DELETE FROM ' . DB::table('agent_releases') . ' WHERE version = :v',
                ['v' => '9.9.9-test']
            );

            foreach (glob(APP_ROOT . '/storage/downloads/akconnect-agent-9.9.9-test.exe') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    /** @see self::selfUpdate() — the body, so the restore above is a finally. */
    private static function selfUpdateChecks(string $secret, string $controllerKey): void
    {
        $client = self::client();
        $token = self::$fixtures['alpha']['device_token'];

        $payload = random_bytes(4096);
        $digest = hash('sha256', $payload);

        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', (string) json_encode([
            'kind'    => 'windows-agent',
            'version' => '9.9.9-test',
            'sha256'  => $digest,
            'offset'  => 0,
            'total'   => strlen($payload),
            'data'    => base64_encode($payload),
        ]), $secret);
        TestCase::assertSame(200, $client->status(), 'an agent binary is accepted from the edge');

        // The device asks what it should be running.
        $client->get('/api/v1/agent/version?platform=windows&arch=amd64', [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ]);
        TestCase::assertSame(200, $client->status(), 'a device may ask for its release');

        $offer = $client->json()['data'] ?? [];
        TestCase::assert(($offer['update_available'] ?? false) === true, 'and is offered the new one');
        TestCase::assertSame('9.9.9-test', (string) ($offer['version'] ?? ''), 'by version');
        TestCase::assertSame($digest, (string) ($offer['sha256'] ?? ''), 'with the digest it was published under');

        // The signature is the security boundary: an agent installs nothing
        // without it, so a release published unsigned is a silent dead end.
        $signature = (string) ($offer['signature'] ?? '');
        TestCase::assert($signature !== '', 'and a signature');

        $raw = @hex2bin(substr($signature, strlen('ed25519:')));
        TestCase::assert(
            str_starts_with($signature, 'ed25519:')
                && $raw !== false
                && sodium_crypto_sign_verify_detached($raw, $digest, (string) hex2bin($controllerKey)),
            'that verifies against the controller public key the agent is given'
        );

        // The agent refuses a download that is not on the panel it enrolled
        // with, so the panel must publish an address on itself.
        $url = (string) ($offer['url'] ?? '');
        TestCase::assertContains(
            (string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST),
            $url,
            'and a download address on this panel'
        );

        // The test client speaks to the dev server, not to the public host
        // name in the configuration, so only the path travels.
        $path = (string) parse_url($url, PHP_URL_PATH);

        // Serving code is the one thing that must never be anonymous.
        $client->get($path, ['Accept' => 'application/octet-stream']);
        TestCase::assertSame(401, $client->status(), 'the binary is not served without a device token');

        $client->get($path, [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/octet-stream',
        ]);
        TestCase::assertSame(200, $client->status(), 'and is served to the device it is offered to');
        TestCase::assertSame($digest, hash('sha256', $client->body()), 'byte-identical to what was published');
    }

    /**
     * Sign a request the way the coordinator does, and send it.
     *
     * The scheme is sha256 HMAC over "<timestamp>\n<body>" — the same three
     * lines deploy/upgrade-edge.sh reproduces in openssl and
     * deploy/publish-artifact.py reproduces in Python. Three implementations
     * of one scheme is two too many to leave untested.
     */
    private static function signedRequest(
        HttpClient $client,
        string $method,
        string $path,
        string $body,
        string $secret
    ): void {
        $timestamp = (string) time();
        $headers = [
            'Accept'                  => 'application/json',
            'Content-Type'            => 'application/json',
            'X-Coordinator-Timestamp' => $timestamp,
            'X-Coordinator-Signature' => hash_hmac('sha256', $timestamp . "\n" . $body, $secret),
        ];

        if ($method === 'GET') {
            $client->get($path, $headers);

            return;
        }

        $client->post($path, $body, $headers);
    }

    /**
     * Both join-code buttons, driven exactly as the page drives them.
     *
     * Production defect (1.9.2): `$request->input('pre_approved', false)`
     * passed a bool where the signature takes ?string. Under
     * declare(strict_types=1) that is checked at the call, so it threw on
     * every request reaching the line and both buttons on the page returned
     * 500 — the ordinary "New code" one included, which is the one every
     * customer uses.
     *
     * It shipped because nothing had ever posted this form. Pre-approved codes
     * were new in 1.9.2 and were tested through the model and the enrolment
     * path; the controller that issues them was reached by no test at all. So
     * both buttons are driven here exactly as the page drives them — the page
     * posts two separate forms rather than a checkbox — and the drill would
     * have caught it with either one.
     */
    /**
     * 1.9.5: one link a supplier can send, with the code already in it.
     *
     * The page has to work for somebody who is not signed in and never will
     * be — that is the entire point of it — and it must not become a way to
     * ask the panel which codes are real.
     */
    private static function installLink(): void
    {
        TestCase::group('HTTP — the install link a customer is sent (1.9.5)');

        // The page offers the installer this panel publishes, so there has to
        // be one. Published here and put back afterwards, the same way
        // edgeUpgrade does it: a test that left a fake installer as the live
        // download would break the thing it was checking.
        $before = [];
        foreach (['file', 'version', 'sha256', 'size', 'published_at'] as $key) {
            $before[$key] = Setting::get('edge.windows-setup.' . $key, null);
        }

        try {
            self::installLinkChecks();
        } finally {
            foreach ($before as $key => $value) {
                Setting::set('edge.windows-setup.' . $key, $value === null ? null : (string) $value);
            }
            Setting::flushCache();

            @unlink(APP_ROOT . '/storage/downloads/akconnect-setup-1.9.5-installlink.exe');
        }
    }

    /** @see self::installLink() — the body, so the restore above is a finally. */
    private static function installLinkChecks(): void
    {
        $name = 'akconnect-setup-1.9.5-installlink.exe';
        $body = str_repeat('MZ', 64);
        file_put_contents(APP_ROOT . '/storage/downloads/' . $name, $body);

        Setting::set('edge.windows-setup.file', $name);
        Setting::set('edge.windows-setup.version', '1.9.5-installlink');
        Setting::set('edge.windows-setup.sha256', hash('sha256', $body));
        Setting::set('edge.windows-setup.size', (string) strlen($body));
        Setting::set('edge.windows-setup.published_at', gmdate('Y-m-d H:i:s'));
        Setting::flushCache();

        $client = self::signIn('alpha');
        $networkId = (int) self::$fixtures['alpha']['network_id'];

        $client->get('/networks/' . $networkId);
        $client->post('/networks/' . $networkId . '/join-code', [
            '_token' => (string) $client->csrfToken(),
        ]);

        $code = self::latestJoinCode($networkId);
        TestCase::assert($code !== null, 'a join code was issued to link to');

        $plain = (string) ($code['code'] ?? '');

        // The supplier's page offers the link, with the code in it.
        $client->get('/networks/' . $networkId);
        TestCase::assertContains('/join/' . $plain, $client->body(),
            'the network page offers a link with the code already in it');

        // And a customer, signed in to nothing, gets a page with the code on
        // it and a download button.
        $anonymous = new HttpClient(self::$baseUrl);
        $anonymous->get('/join/' . $plain);
        TestCase::assertSame(200, $anonymous->status(), 'the install page opens without signing in');
        TestCase::assertContains($plain, $anonymous->body(), 'and shows the code the customer has to type');
        TestCase::assertContains('/download/setup.exe', $anonymous->body(), 'and a download button');

        // Typed in lower case, or pasted with a space, is the same code. That
        // is not the customer's mistake to pay for.
        $anonymous->get('/join/' . strtolower($plain) . '%20');
        TestCase::assertSame(200, $anonymous->status(), 'a code typed in lower case still opens the page');
        TestCase::assertContains($plain, $anonymous->body(), 'and is shown back in the form the installer wants');

        // A code that is not real gets the same page, not an answer. A page
        // that said "that one is not valid" would say it for anyone asking,
        // which is a way to hunt for codes.
        $anonymous->get('/join/NOT-A-REAL-CODE');
        TestCase::assertSame(200, $anonymous->status(),
            'an unknown code is not treated as a question the panel answers');

        // Nothing about the network reaches the page: not its name, not its
        // customer. The link is sent over WhatsApp and gets forwarded.
        $networkName = (string) self::$fixtures['alpha']['network_name'];
        TestCase::assert(
            !str_contains($anonymous->body(), $networkName),
            'the install page does not name the network the code belongs to'
        );
    }

    private static function joinCodeForms(): void
    {
        TestCase::group('HTTP — issuing a join code, both buttons (1.9.2)');

        $client = self::signIn('alpha');
        $networkId = (int) self::$fixtures['alpha']['network_id'];
        $path = '/networks/' . $networkId . '/join-code';

        // 1. "New code": no pre_approved field at all. This is the one that
        //    500'd in production.
        $client->get('/networks/' . $networkId);
        TestCase::assertSame(200, $client->status(), 'the network page renders');

        $client->post($path, ['_token' => (string) $client->csrfToken()]);
        TestCase::assertSame(302, $client->status(),
            'issuing an ordinary code does not fail', 'HTTP ' . $client->status());

        $ordinary = self::latestJoinCode($networkId);
        TestCase::assert($ordinary !== null, 'and a code was created');
        TestCase::assertSame(0, (int) ($ordinary['pre_approved'] ?? 1),
            'and it is not pre-approved');

        // 2. "New pre-approved code": the hidden fields the page sends.
        $client->get('/networks/' . $networkId);
        $client->post($path, [
            '_token'       => (string) $client->csrfToken(),
            'pre_approved' => '1',
            'max_uses'     => '1',
            'ttl_minutes'  => '30',
        ]);
        TestCase::assertSame(302, $client->status(),
            'issuing a pre-approved code does not fail', 'HTTP ' . $client->status());

        $preApproved = self::latestJoinCode($networkId);
        TestCase::assert($preApproved !== null && $preApproved['code'] !== ($ordinary['code'] ?? ''),
            'and a second, different code was created');
        TestCase::assertSame(1, (int) ($preApproved['pre_approved'] ?? 0),
            'and this one is pre-approved (R4: the admin decided, in advance)');
        TestCase::assertSame(1, (int) ($preApproved['max_uses'] ?? 0),
            'single-use, so it is not a standing invitation');

        // 3. A pre-approved code may not be issued unlimited or long-lived,
        //    whatever the form asks for. The clamp is the whole reason
        //    pre-approval does not break R4.
        $client->get('/networks/' . $networkId);
        $client->post($path, [
            '_token'       => (string) $client->csrfToken(),
            'pre_approved' => 'on',
            'max_uses'     => '0',
            'ttl_minutes'  => '10080',
        ]);
        TestCase::assertSame(302, $client->status(), 'a wide-open pre-approved request is accepted');

        $clamped = self::latestJoinCode($networkId);
        TestCase::assertSame(1, (int) ($clamped['pre_approved'] ?? 0),
            'checkbox "on" is read as pre-approved, not ignored');
        TestCase::assert((int) ($clamped['max_uses'] ?? 0) > 0,
            'an unlimited pre-approved code is refused', 'max_uses=' . ($clamped['max_uses'] ?? '?'));
        TestCase::assert(
            strtotime((string) $clamped['expires_at']) - time() <= 121 * 60,
            'and it cannot outlive two hours',
            (string) $clamped['expires_at']
        );
    }

    /** @return array<string,mixed>|null */
    private static function latestJoinCode(int $networkId): ?array
    {
        $row = null;

        TenantScope::asTenant(self::$fixtures['alpha']['tenant_id'], static function () use ($networkId, &$row): void {
            \App\Core\Auth::setApiActor(null, self::$fixtures['alpha']['tenant_id'], ['*']);
            $row = DB::selectOne(
                'SELECT * FROM ' . DB::table('join_codes') . '
                 WHERE network_id = :n ORDER BY id DESC LIMIT 1',
                ['n' => $networkId]
            );
        });
        \App\Core\Auth::reset();
        TenantScope::reset();

        return $row;
    }

    private static function agentEndpoints(): void
    {
        TestCase::group('HTTP — agent endpoints (R4, R1)');

        $client = self::client();
        $token = self::$fixtures['alpha']['device_token'];

        // Enrolment is public but needs a valid join code.
        $client->post('/api/v1/enroll', (string) json_encode([
            'join_code'  => 'INVALIDCODE1',
            'public_key' => base64_encode(random_bytes(32)),
            'hostname'   => 'intruder',
        ]), ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        TestCase::assertSame(422, $client->status(), 'enrolment with a bad join code is refused');

        // A real enrolment returns pending and nothing usable (R4).
        $newKey = base64_encode(random_bytes(32));
        $client->post('/api/v1/enroll', (string) json_encode([
            'join_code'     => self::$fixtures['alpha']['join_code'],
            'public_key'    => $newKey,
            'hostname'      => 'alpha-http-02',
            'os'            => 'windows',
            'agent_version' => '1.0.0',
        ]), ['Content-Type' => 'application/json', 'Accept' => 'application/json']);

        TestCase::assertSame(201, $client->status(), 'a valid enrolment is accepted');
        $enrolment = $client->json()['data'] ?? [];
        TestCase::assertSame('pending', $enrolment['status'] ?? '', 'and the device is pending (R4)');
        TestCase::assertNotContains('device_token', $client->body(), 'no token is returned to a pending device');
        TestCase::assertNotContains('virtual_ip', $client->body(), 'and no address');

        // Claiming before approval yields nothing.
        $client->post('/api/v1/agent/claim', (string) json_encode([
            'device_uid' => $enrolment['device_uid'] ?? '',
            'public_key' => $newKey,
        ]), ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        TestCase::assertSame(200, $client->status(), 'claim answers a pending device');
        TestCase::assert(($client->json()['data']['authorized'] ?? true) === false,
            'but reports it is not authorised');
        TestCase::assertNotContains('device_token', $client->body(), 'and issues no token');

        // Agent endpoints require the device token.
        $client->get('/api/v1/agent/config', ['Accept' => 'application/json']);
        TestCase::assertSame(401, $client->status(), 'agent config requires a token');

        $client->get('/api/v1/agent/config', ['Authorization' => 'Bearer not-a-real-token', 'Accept' => 'application/json']);
        TestCase::assertSame(401, $client->status(), 'a wrong token is rejected');

        // With the real token, the config comes back — and honours R1.
        $client->get('/api/v1/agent/config', ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
        TestCase::assertSame(200, $client->status(), 'an authorised device receives its config');

        $body = $client->body();
        TestCase::assertNotContains('0.0.0.0/0', $body, 'the config contains no default route (R1)');
        TestCase::assertNotContains('::/0', $body, 'nor an IPv6 default route');

        $config = $client->json()['data'] ?? [];
        TestCase::assert(($config['policy']['split_tunnel_only'] ?? false) === true,
            'and declares split-tunnel-only');
        TestCase::assertSame('10.121.0.0/24', $config['network']['cidr'] ?? '', 'with the right network CIDR');
        TestCase::assertNotContains('private_key', $body, 'and no private key material (R5)');

        // Heartbeat.
        $client->post('/api/v1/agent/heartbeat', (string) json_encode([
            'endpoint'        => '203.0.113.9:51820',
            'connection_type' => 'direct',
            'rx_delta'        => 2048,
            'tx_delta'        => 1024,
            'latency_ms'      => 18,
        ]), ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json']);

        TestCase::assertSame(200, $client->status(), 'heartbeat is accepted');

        TenantScope::acrossAllTenants('heartbeat verification', static function (): void {
            $device = DB::selectOne(
                'SELECT connection_type, latency_ms, rx_bytes FROM ' . DB::table('devices') . ' WHERE id = :id',
                ['id' => self::$fixtures['alpha']['device_id']]
            );
            TestCase::assertSame('direct', $device['connection_type'] ?? '', 'and records the connection type');
            TestCase::assertSame(18, (int) ($device['latency_ms'] ?? 0), 'and the latency');
            TestCase::assert((int) ($device['rx_bytes'] ?? 0) >= 2048, 'and accumulates traffic counters');
        });

        // A revoked device loses access immediately.
        TenantScope::asTenant(self::$fixtures['beta']['tenant_id'], static function (): void {
            \App\Core\Auth::setApiActor(null, self::$fixtures['beta']['tenant_id'], ['*']);
            DeviceService::revoke(self::$fixtures['beta']['device_id'], 'http test');
        });
        \App\Core\Auth::reset();
        TenantScope::reset();

        $client->get('/api/v1/agent/config', [
            'Authorization' => 'Bearer ' . self::$fixtures['beta']['device_token'],
            'Accept'        => 'application/json',
        ]);
        TestCase::assertSame(401, $client->status(), "a revoked device's token stops working at once");
    }

    private static function rateLimiting(): void
    {
        TestCase::group('HTTP — rate limiting (§20.E)');

        $client = self::client();
        $client->get('/login');
        $token = (string) $client->csrfToken();

        // The login bucket allows 20 attempts per 15 minutes by default.
        $limit = (int) \App\Core\Config::get('security.login_rate_per_15min', 20);
        $tripped = false;

        for ($attempt = 0; $attempt < $limit + 6; $attempt++) {
            $client->post('/login', [
                '_token'   => $token,
                'email'    => 'ratelimit-' . bin2hex(random_bytes(3)) . '@http.test',
                'password' => 'wrong-password',
            ]);

            if ($client->status() === 429) {
                $tripped = true;
                break;
            }

            // The CSRF token rotates with the session; refresh it.
            $client->get('/login');
            $token = (string) $client->csrfToken() ?: $token;
        }

        TestCase::assert($tripped, 'repeated failed logins trip the rate limiter',
            'limit ' . $limit . '/15min');

        if ($tripped) {
            TestCase::assert($client->header('Retry-After') !== null,
                'and a Retry-After header is returned', (string) $client->header('Retry-After'));
        }

        // Clear the bucket so the rest of the suite is unaffected.
        \App\Core\RateLimit::clear('login:127.0.0.1');
    }
}
