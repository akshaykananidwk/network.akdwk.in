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
use App\Services\EdgeRelease;
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

    /** @var array{0:string,1:string} the upload tests' edge key: [public hex, raw secret] */
    private static array $edgeKey = ['', ''];

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
            self::deviceUpdateNow();
            self::deviceUpdateState();
            self::coordinatorSaysUnanswered();
            self::selfUpdate();
            // 1.9.7-dev.23: the shared secret alone publishes nothing.
            self::edgeUploadTrust();
            // 1.9.7-dev.24: Online is the device; direct / via server is the pair.
            self::pairLinks();
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
            self::withEdgeKey(static fn () => self::edgeUpgradeChecks($client, $secret));
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
            $body = self::edgeSigned([
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
        $body = self::edgeSigned([
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
            self::withEdgeKey(static fn () => self::selfUpdateChecks($secret, $controllerKey));
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

        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', self::edgeSigned([
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
     * 1.9.7-dev.24: each pair says "direct" or "via server"; the device says
     * Online.
     *
     * The agent has sent a path per peer on every heartbeat since 1.9.2, and
     * the panel dropped it, showing one word for the whole device instead —
     * "connecting", for any device with no live WireGuard session, which was
     * what the operator saw on two PCs that were working.
     */
    private static function pairLinks(): void
    {
        TestCase::group('HTTP — Online is the device; direct or via server is the pair (1.9.7-dev.24)');

        $tenantId = (int) self::$fixtures['alpha']['tenant_id'];
        $alphaId = (int) self::$fixtures['alpha']['device_id'];

        // A second device in Alpha's network, to be the peer.
        $peer = TenantScope::asTenant($tenantId, static function () use ($tenantId): array {
            \App\Core\Auth::setApiActor(null, $tenantId, ['*']);
            $enrolment = DeviceService::enroll([
                'join_code'     => self::$fixtures['alpha']['join_code'],
                'public_key'    => base64_encode(random_bytes(32)),
                'hostname'      => 'alpha-http-02',
                'os'            => 'windows',
                'agent_version' => '1.0.0',
            ]);
            $device = Device::findByUid($enrolment['device_uid']);
            DeviceService::approve((int) $device['id']);

            return ['id' => (int) $device['id'], 'uid' => (string) $enrolment['device_uid']];
        });
        \App\Core\Auth::reset();
        TenantScope::reset();

        $agent = [
            'Authorization' => 'Bearer ' . (string) self::$fixtures['alpha']['device_token'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        $client = self::client();
        $links = static fn (): array => TenantScope::asTenant($tenantId,
            static fn (): array => \App\Models\DeviceLink::forDevice($tenantId, $alphaId));

        // Relayed, plus two entries that must be ignored: another customer's
        // device, and something that is not a uid at all.
        $client->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'connecting',
            'peers'           => [
                ['uid' => $peer['uid'], 'path' => 'relay-udp'],
                ['uid' => (string) self::$fixtures['beta']['device_uid'], 'path' => 'direct'],
                ['uid' => '<script>', 'path' => 'direct'],
            ],
        ]), $agent);
        TestCase::assertSame(200, $client->status(), 'a heartbeat with per-peer paths is accepted');

        $now = $links();
        TestCase::assert(count($now) === 1 && ($now[$peer['id']]['path'] ?? '') === 'server',
            'the relayed peer is recorded as via server, and nothing else is recorded',
            (string) json_encode(array_map(static fn (array $l): string => (string) $l['path'], $now)));

        $page = self::signIn('alpha');
        $page->get('/devices/' . $alphaId);
        TestCase::assertContains('via server', $page->body(), 'the device page says via server');
        TestCase::assert(str_contains($page->body(), 'conn-online') && !preg_match('/>\s*connecting\s*</', $page->body()),
            'and the device is Online, with no "connecting" anywhere');
        $page->get('/devices');
        TestCase::assertContains('via server', $page->body(), 'the device list says so too');

        // Direct now: the label follows, and "since" moves because the path did.
        TenantScope::asTenant($tenantId, static fn (): int => DB::execute(
            'UPDATE ' . DB::table('device_links') . ' SET since_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
             WHERE tenant_id = :t AND device_id = :d',
            ['t' => $tenantId, 'd' => $alphaId]
        )->rowCount());
        $client->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'peers'           => [['uid' => $peer['uid'], 'path' => 'direct', 'latency_ms' => 21]],
        ]), $agent);
        $now = $links();
        $since = strtotime((string) ($now[$peer['id']]['since_at'] ?? '') . ' UTC');
        TestCase::assert(($now[$peer['id']]['path'] ?? '') === 'direct' && $since > time() - 600,
            'a pair that goes direct is recorded as direct, since now');
        TestCase::assertSame(21, (int) ($now[$peer['id']]['latency_ms'] ?? 0), 'with its latency');

        // The same path again leaves "since" alone.
        TenantScope::asTenant($tenantId, static fn (): int => DB::execute(
            'UPDATE ' . DB::table('device_links') . ' SET since_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
             WHERE tenant_id = :t AND device_id = :d',
            ['t' => $tenantId, 'd' => $alphaId]
        )->rowCount());
        $client->post('/api/v1/agent/heartbeat', (string) json_encode([
            'peers' => [['uid' => $peer['uid'], 'path' => 'direct']],
        ]), $agent);
        $since = strtotime((string) ($links()[$peer['id']]['since_at'] ?? '') . ' UTC');
        TestCase::assert($since < time() - 3000, 'and staying on it does not reset "since"');

        $page->get('/devices/' . $alphaId);
        TestCase::assertContains('direct', $page->body(), 'the device page says direct');

        // Back after a gap, on the same path: "since" is now, not before the
        // gap — the pair was down in between.
        TenantScope::asTenant($tenantId, static fn (): int => DB::execute(
            'UPDATE ' . DB::table('device_links') . '
             SET since_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
             WHERE tenant_id = :t AND device_id = :d',
            ['t' => $tenantId, 'd' => $alphaId]
        )->rowCount());
        $client->post('/api/v1/agent/heartbeat', (string) json_encode([
            'peers' => [['uid' => $peer['uid'], 'path' => 'direct']],
        ]), $agent);
        $since = strtotime((string) ($links()[$peer['id']]['since_at'] ?? '') . ' UTC');
        TestCase::assert($since > time() - 600, 'a pair back after a gap on the same path is "since now", not since before the gap');
    }

    /**
     * 1.9.7-dev.23: an upload carrying only the shared secret is never offered.
     *
     * The shared secret was printed by the installer up to dev.21, and the
     * panel signed whatever agent binary arrived with it and offered it to
     * every device. Now it publishes only what the edge's own release key
     * signed — the key it was told to trust locally or by an administrator —
     * and holds or refuses the rest. Asserted over HTTP, because the attacker
     * this is about has nothing else.
     */
    private static function edgeUploadTrust(): void
    {
        TestCase::group('HTTP — the shared secret alone publishes nothing (1.9.7-dev.23)');

        $secret = (string) CoordinatorSettings::current()['shared_secret'];
        if ($secret === '' || (string) CoordinatorSettings::current()['signing_key'] === '') {
            TestCase::skip('upload trust', 'this panel has no coordinator secret or signing key');

            return;
        }

        $before = [];
        foreach (['file', 'version', 'sha256', 'size', 'published_at'] as $key) {
            $before[$key] = Setting::get('edge.windows-agent.' . $key, null);
        }

        try {
            self::withEdgeKey(static fn () => self::edgeUploadTrustChecks($secret));
        } finally {
            foreach ($before as $key => $value) {
                Setting::set('edge.windows-agent.' . $key, $value === null ? null : (string) $value);
            }
            Setting::flushCache();

            DB::execute(
                'DELETE FROM ' . DB::table('agent_releases') . ' WHERE version IN (:a, :b, :c)',
                ['a' => '9.9.9-held', 'b' => '9.9.9-test', 'c' => '9.9.8-old']
            );

            foreach (glob(APP_ROOT . '/storage/downloads/akconnect-agent-9.9.[89]-*.exe') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    private static function edgeUploadTrustChecks(string $secret): void
    {
        $client = self::client();

        $payload = random_bytes(2048);
        $digest = hash('sha256', $payload);
        $fields = [
            'kind'    => 'windows-agent',
            'version' => '9.9.9-held',
            'sha256'  => $digest,
            'offset'  => 0,
            'total'   => strlen($payload),
            'data'    => base64_encode($payload),
        ];

        $offered = static function (): bool {
            return DB::selectOne(
                'SELECT id FROM ' . DB::table('agent_releases') . ' WHERE version = :v AND deleted_at IS NULL',
                ['v' => '9.9.9-held']
            ) !== null;
        };

        // 1. The shared secret and nothing else: refused outright.
        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', (string) json_encode($fields), $secret);
        TestCase::assertSame(422, $client->status(), 'an upload with no edge signature is refused');
        TestCase::assert(!$offered(), 'and no device is offered it');

        // 2. A real signature, but over another version: a signature is for
        //    one kind, one version and one digest.
        $other = $fields;
        $other['version'] = '9.9.9-other';
        $body = json_decode(self::edgeSigned($other), true);
        $body['version'] = '9.9.9-held';
        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', (string) json_encode($body), $secret);
        TestCase::assertSame(422, $client->status(), 'a signature made for another version is refused');

        // 3. Signed, by a key this panel does not trust: held, not offered.
        $pair = sodium_crypto_sign_keypair();
        $stranger = [bin2hex(sodium_crypto_sign_publickey($pair)), sodium_crypto_sign_secretkey($pair)];

        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', self::edgeSigned($fields, $stranger), $secret);
        TestCase::assertSame(200, $client->status(), 'an upload signed by another edge key is taken in');
        $data = $client->json()['data'] ?? [];
        TestCase::assert(($data['held'] ?? false) === true, 'but held', (string) json_encode($data));
        TestCase::assertSame('other_key', (string) ($data['reason'] ?? ''), 'because it is not the trusted key');
        TestCase::assert(!isset($data['url']), 'with no download address');
        TestCase::assert(!$offered(), 'and no device is offered it');

        Setting::flushCache();
        $held = EdgeRelease::held();
        TestCase::assert(isset($held['windows-agent']), 'it waits for an administrator');
        TestCase::assertSame(EdgeRelease::fingerprint($stranger[0]), (string) ($held['windows-agent']['fingerprint'] ?? ''),
            'showing the fingerprint of the key that signed it');

        // 3b. Approving is a platform administrator's act: a customer's own
        //     administrator, signed in with a valid CSRF token, is refused.
        $customer = self::signIn('alpha');
        $customer->get('/dashboard');
        $customer->post('/admin/coordinator/held/approve', [
            '_token'      => $customer->csrfToken(),
            'kind'        => 'windows-agent',
            'fingerprint' => EdgeRelease::fingerprint($stranger[0]),
        ]);
        TestCase::assert(in_array($customer->status(), [302, 403, 404], true) && !$offered(),
            'a customer administrator cannot approve a held upload', 'HTTP ' . $customer->status());
        Setting::flushCache();
        TestCase::assert(isset(EdgeRelease::held()['windows-agent']), 'and it is still waiting');

        // 4. An approval for what was not on screen does nothing.
        $refused = false;
        try {
            EdgeRelease::approveHeld('windows-agent', EdgeRelease::fingerprint(self::$edgeKey[0]), 'http test');
        } catch (\App\Core\UpdateException) {
            $refused = true;
        }
        TestCase::assert($refused, 'approving under another fingerprint is refused');
        TestCase::assert(!$offered(), 'and publishes nothing');

        // 5. The administrator approves what they were shown: that key is now
        //    trusted, and the upload is published — signed by the panel.
        EdgeRelease::approveHeld('windows-agent', EdgeRelease::fingerprint($stranger[0]), 'http test');
        Setting::flushCache();
        TestCase::assert($offered(), 'an approved upload is offered to devices');
        TestCase::assertSame($stranger[0], EdgeRelease::trustedReleaseKey(), 'and its key is the trusted edge key');
        TestCase::assert(EdgeRelease::held() === [], 'and nothing is left waiting');

        // 6. With no key trusted at all, a signed upload is held, not published.
        Setting::set('edge.release_key', null);
        Setting::flushCache();
        DB::execute('DELETE FROM ' . DB::table('agent_releases') . ' WHERE version = :v', ['v' => '9.9.9-held']);

        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', self::edgeSigned($fields, $stranger), $secret);
        $data = $client->json()['data'] ?? [];
        TestCase::assert(($data['held'] ?? false) === true && ($data['reason'] ?? '') === 'no_trusted_key',
            'with no trusted key, a signed upload is held', (string) json_encode($data));
        TestCase::assert(!$offered(), 'and not offered');

        // 7. Discarded, it is gone.
        Setting::flushCache();
        $file = (string) (EdgeRelease::held()['windows-agent']['file'] ?? '');
        EdgeRelease::discardHeld('windows-agent', 'http test');
        Setting::flushCache();
        TestCase::assert(EdgeRelease::held() === [], 'a discarded upload no longer waits');
        TestCase::assert($file !== '' && !is_file(APP_ROOT . '/storage/downloads/held/' . $file), 'and its file is deleted');

        // 8. Held, then its key trusted some other way (edge-trust.php without
        //    --publish-held, or approving the other kind): the edge must not
        //    be told it is still waiting, or it never sends it again.
        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', self::edgeSigned($fields, $stranger), $secret);
        EdgeRelease::trustReleaseKey($stranger[0], 'http test');
        Setting::flushCache();
        self::signedRequest($client, 'GET', '/api/v1/edge/release', '', $secret);
        $release = $client->json()['data'] ?? [];
        TestCase::assertSame('', (string) ($release['held_agent'] ?? 'missing'),
            'an upload held under a key that is now trusted is not reported as waiting');

        // 9. And sent again, it publishes — and the stale held record goes.
        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', self::edgeSigned($fields, $stranger), $secret);
        TestCase::assert(($client->json()['data']['complete'] ?? false) === true && !isset($client->json()['data']['held']),
            'the same upload, sent again, publishes');
        Setting::flushCache();
        TestCase::assert(EdgeRelease::held() === [], 'and publishing it clears what was held for that kind');

        // 10. An older build held under another key cannot be approved over
        //     the newer one that is published: that put the old agent back in
        //     front of every device.
        $pair = sodium_crypto_sign_keypair();
        $third = [bin2hex(sodium_crypto_sign_publickey($pair)), sodium_crypto_sign_secretkey($pair)];
        $older = $fields;
        $older['version'] = '9.9.8-old';
        self::signedRequest($client, 'POST', '/api/v1/edge/artifact', self::edgeSigned($older, $third), $secret);
        Setting::flushCache();
        $refused = false;
        try {
            EdgeRelease::approveHeld('windows-agent', EdgeRelease::fingerprint($third[0]), 'http test');
        } catch (\App\Core\UpdateException) {
            $refused = true;
        }
        Setting::flushCache();
        TestCase::assert($refused && EdgeRelease::held() === [] && EdgeRelease::trustedReleaseKey() === $stranger[0],
            'an older held build is refused and discarded, and its key is not trusted');

        // 11. Nothing an upload wrote is left lying around.
        $left = array_merge(
            glob(APP_ROOT . '/storage/downloads/.*.part') ?: [],
            glob(APP_ROOT . '/storage/downloads/.*.claimed') ?: [],
            glob(APP_ROOT . '/storage/downloads/.*.verified') ?: []
        );
        TestCase::assert($left === [], 'no partial or private upload files are left behind', implode(', ', array_map('basename', $left)));
    }

    /**
     * A throwaway edge release key, trusted while $body runs, and whatever
     * this panel trusted and held before put back afterwards.
     */
    private static function withEdgeKey(callable $body): void
    {
        $saved = ['edge.release_key' => Setting::get('edge.release_key', null)];
        foreach (EdgeRelease::KINDS as $kind) {
            $saved['edge.held.' . $kind] = Setting::get('edge.held.' . $kind, null);
        }
        $keep = glob(APP_ROOT . '/storage/downloads/held/*') ?: [];

        $pair = sodium_crypto_sign_keypair();
        self::$edgeKey = [bin2hex(sodium_crypto_sign_publickey($pair)), sodium_crypto_sign_secretkey($pair)];
        Setting::set('edge.release_key', self::$edgeKey[0]);
        // Nothing held while the tests run: a held record here would have its
        // file deleted by the tests' own uploads (hold() replaces one per
        // kind), and restoring the setting afterwards would then point at a
        // file that is gone. With the records cleared the real files are never
        // touched, and the restore below brings both back as they were.
        foreach (EdgeRelease::KINDS as $kind) {
            Setting::set('edge.held.' . $kind, null);
        }
        Setting::flushCache();

        try {
            $body();
        } finally {
            foreach ($saved as $key => $value) {
                Setting::set($key, $value);
            }
            Setting::flushCache();

            foreach (glob(APP_ROOT . '/storage/downloads/held/*') ?: [] as $file) {
                if (!in_array($file, $keep, true)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * An upload body signed the way upgrade-edge.sh signs one.
     *
     * @param array<string,mixed> $fields
     * @param array{0:string,1:string}|null $key [public hex, raw secret]; the trusted test key by default
     */
    private static function edgeSigned(array $fields, ?array $key = null): string
    {
        $key ??= self::$edgeKey;
        $fields['edge_key'] = $key[0];
        $fields['edge_signature'] = bin2hex(sodium_crypto_sign_detached(
            EdgeRelease::artifactMessage((string) $fields['kind'], (string) $fields['version'], (string) $fields['sha256']),
            $key[1]
        ));

        return (string) json_encode($fields);
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

    /**
     * 1.9.5: the coordinator reporting that a device cannot hear it.
     *
     * The fault a device cannot report about itself: its announcements arrive
     * at the coordinator, the coordinator answers every one, and not one
     * answer arrives back. From the device's side that is indistinguishable
     * from "still connecting", which is what it said for an afternoon on a
     * real office Wi-Fi. Only the coordinator sees both halves.
     */
    private static function coordinatorSaysUnanswered(): void
    {
        TestCase::group('HTTP — the coordinator reports a device that cannot hear it (1.9.5)');

        $secret = (string) CoordinatorSettings::current()['shared_secret'];
        if ($secret === '') {
            TestCase::skip('coordinator report', 'no coordinator shared secret is configured');

            return;
        }

        $deviceId = (int) self::$fixtures['alpha']['device_id'];
        $tenantId = (int) self::$fixtures['alpha']['tenant_id'];
        $uid = TenantScope::asTenant($tenantId, static fn (): string => (string) Device::find($deviceId)['device_uid']);

        // Heartbeat first, so the device is online and has no peer path — the
        // exact state the office laptop was in, and the state in which the
        // panel used to say "connecting" indefinitely.
        $agent = [
            'Authorization' => 'Bearer ' . (string) self::$fixtures['alpha']['device_token'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        $client = self::client();
        $client->post('/api/v1/agent/heartbeat',
            (string) json_encode(['connection_type' => 'connecting']), $agent);

        $body = (string) json_encode(['devices' => [[
            'device_uid'       => $uid,
            'endpoint'         => '150.129.167.50:53722',
            'unanswered'       => true,
            'unanswered_known' => true,
        ]]]);
        self::signedRequest($client, 'POST', '/api/v1/coordinator/endpoints', $body, $secret);
        TestCase::assertSame(200, $client->status(), 'the coordinator can report it');

        $device = TenantScope::asTenant($tenantId, static fn (): ?array => Device::find($deviceId));
        TestCase::assert(
            ($device['coordinator_unanswered_at'] ?? null) !== null,
            'the panel records that the replies are not arriving'
        );
        TestCase::assertContains(
            'not arriving',
            (string) ($device['last_error'] ?? ''),
            'and says so in words on the device page'
        );
        TestCase::assertSame(
            '150.129.167.50:53722',
            (string) ($device['last_endpoint'] ?? ''),
            'and the endpoint in the same report still landed'
        );

        // The device page says so in words — and the device itself is still
        // Online: it is heartbeating. "connecting" is not shown anywhere.
        $panel = self::signIn('alpha');
        $panel->get('/devices/' . $deviceId);
        TestCase::assertContains('Nothing answers this device', $panel->body(),
            'the device page says nothing answers its announcements');
        TestCase::assert(str_contains($panel->body(), 'conn-online') && !str_contains($panel->body(), 'conn-connecting'),
            'while its status stays Online, not "connecting"');

        // A heartbeat without an endpoint leaves the coordinator's alone. It
        // used to write '' over it every ten seconds.
        $client->post('/api/v1/agent/heartbeat', (string) json_encode(['connection_type' => 'connecting']), $agent);
        $device = TenantScope::asTenant($tenantId, static fn (): ?array => Device::find($deviceId));
        TestCase::assertSame('150.129.167.50:53722', (string) ($device['last_endpoint'] ?? ''),
            'a heartbeat that sends no endpoint does not blank the one the coordinator reported');

        // Nor does one that is not an address: it would be handed to every
        // peer, and the agent refuses a push at the first line it cannot parse.
        $client->post('/api/v1/agent/heartbeat', (string) json_encode(['endpoint' => 'edge.example.com:443']), $agent);
        $device = TenantScope::asTenant($tenantId, static fn (): ?array => Device::find($deviceId));
        TestCase::assertSame('150.129.167.50:53722', (string) ($device['last_endpoint'] ?? ''),
            'an endpoint that is not an IP and a port is not stored');

        // And when it starts hearing again, that clears — without a heartbeat
        // from the device, because the device was never the one reporting it.
        $body = (string) json_encode(['devices' => [[
            'device_uid'       => $uid,
            'unanswered'       => false,
            'unanswered_known' => true,
        ]]]);
        self::signedRequest($client, 'POST', '/api/v1/coordinator/endpoints', $body, $secret);

        $device = TenantScope::asTenant($tenantId, static fn (): ?array => Device::find($deviceId));
        TestCase::assert(
            ($device['coordinator_unanswered_at'] ?? null) === null,
            'and it clears when the coordinator says the replies are getting through'
        );

        // A report that says nothing about it must not clear it either way.
        TenantScope::asTenant($tenantId, static fn (): mixed => Device::recordUnanswered($deviceId, true));
        $body = (string) json_encode(['devices' => [[
            'device_uid' => $uid,
            'endpoint'   => '150.129.167.50:53999',
        ]]]);
        self::signedRequest($client, 'POST', '/api/v1/coordinator/endpoints', $body, $secret);

        $device = TenantScope::asTenant($tenantId, static fn (): ?array => Device::find($deviceId));
        TestCase::assert(
            ($device['coordinator_unanswered_at'] ?? null) !== null,
            'an ordinary endpoint report does not silently clear it'
        );

        TenantScope::asTenant($tenantId, static fn (): mixed => Device::recordUnanswered($deviceId, false));
    }

    /**
     * 1.9.6: what a device did about the last release, visible in the panel.
     *
     * An all-in-one at a customer site stayed on 1.9.3 for days after 1.9.4
     * was published and nobody found out. Everything the agent does about an
     * update it does silently — the check, the offer, the download, the
     * signature, the swap — and every failure path is a line in a log file on
     * the customer's machine. From the panel a device that never checked and
     * a device that refused a badly signed release looked identical: a
     * version number that had not moved.
     *
     * So the agent reports where it got to, on the heartbeat it already
     * sends, and the device page says it in a sentence.
     */
    private static function deviceUpdateState(): void
    {
        TestCase::group('HTTP — what a device did about the last release (1.9.6)');

        $client = self::signIn('alpha');
        $deviceId = (int) self::$fixtures['alpha']['device_id'];
        $tenantId = (int) self::$fixtures['alpha']['tenant_id'];

        $agent = [
            'Authorization' => 'Bearer ' . (string) self::$fixtures['alpha']['device_token'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        $api = self::client();

        $read = static fn (): array => (array) TenantScope::asTenant(
            $tenantId,
            static fn (): ?array => Device::find($deviceId)
        );

        // A device that checked and had nothing to do. Worth recording: it is
        // what tells "up to date" apart from "has never asked".
        $api->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'update'          => ['state' => 'idle'],
        ]), $agent);
        TestCase::assertSame(200, $api->status(), 'a heartbeat carrying an update report is accepted');

        $device = $read();
        TestCase::assertSame('idle', (string) ($device['update_state'] ?? ''), 'the state is recorded');
        TestCase::assert(
            ($device['update_checked_at'] ?? null) !== null,
            'and when it was checked, which is what separates up to date from never asked'
        );

        // A refusal. This is the one that has to reach a person: the device is
        // healthy, heartbeating, and running software it should have replaced.
        $api->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'update'          => [
                'state'   => 'failed',
                'version' => '1.9.6',
                'error'   => 'refused: the signature does not verify against this panel\'s key',
            ],
        ]), $agent);

        $device = $read();
        TestCase::assertSame('failed', (string) ($device['update_state'] ?? ''), 'a refusal is recorded');
        TestCase::assertSame('1.9.6', (string) ($device['update_version'] ?? ''), 'with the version it refused');
        TestCase::assertContains(
            'does not verify',
            (string) ($device['update_error'] ?? ''),
            'and why, in the agent\'s own words'
        );

        $client->get('/devices/' . $deviceId);
        TestCase::assertContains(
            'Update to 1.9.6 failed',
            $client->body(),
            'and the device page says so rather than showing a version that has not moved'
        );

        // A state the column does not hold is filed as idle rather than
        // refused: a newer agent inventing one must not cost its heartbeat.
        $api->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'update'          => ['state' => 'rolling-back', 'version' => '1.9.7'],
        ]), $agent);
        TestCase::assertSame(200, $api->status(), 'an unknown state does not cost the heartbeat');
        TestCase::assertSame('idle', (string) ($read()['update_state'] ?? ''), 'and is filed as idle');

        // An error longer than the column is truncated, not refused, for the
        // same reason.
        $api->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'update'          => [
                'state'   => 'failed',
                'version' => '1.9.6',
                'error'   => str_repeat('x', 400),
            ],
        ]), $agent);
        TestCase::assertSame(200, $api->status(), 'an over-long error does not cost the heartbeat either');
        TestCase::assert(
            mb_strlen((string) ($read()['update_error'] ?? '')) <= 255,
            'and is stored truncated'
        );

        // Installed, which is the ordinary outcome and must read as one.
        $api->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'update'          => ['state' => 'installed', 'version' => '1.9.6'],
        ]), $agent);

        $client->get('/devices/' . $deviceId);
        TestCase::assertContains(
            'Installed 1.9.6',
            $client->body(),
            'a successful update is shown as one'
        );
    }

    /**
     * 1.9.5: "Update now", and the two things the device page must answer.
     *
     * The button does not push anything — there is no channel from the panel
     * into a PC behind a shop router. It records a request, the agent collects
     * it on its next configuration poll, and the panel clears it. What is
     * tested here is that round trip, including the part that is easy to get
     * wrong: the request must be consumed, or one click makes the agent check
     * for an update on every poll for ever.
     */
    private static function deviceUpdateNow(): void
    {
        TestCase::group('HTTP — Update now, and what the device page answers (1.9.5)');

        $client = self::signIn('alpha');
        $deviceId = (int) self::$fixtures['alpha']['device_id'];

        // A heartbeat carrying an uptime and a problem, as an agent sends it.
        $agent = [
            'Authorization' => 'Bearer ' . (string) self::$fixtures['alpha']['device_token'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];

        $api = self::client();
        $api->post('/api/v1/agent/heartbeat', (string) json_encode([
            'connection_type' => 'direct',
            'endpoint'        => '203.0.113.9:51820',
            'uptime_seconds'  => 7200,
            'problems'        => [[
                'code'   => 'dns_nrpt_refused',
                'detail' => 'Windows refused the DNS policy rule.',
            ]],
        ]), $agent);
        TestCase::assertSame(200, $api->status(), 'the heartbeat is accepted');

        $device = TenantScope::asTenant(
            (int) self::$fixtures['alpha']['tenant_id'],
            static fn (): ?array => Device::find($deviceId)
        );

        TestCase::assert(
            ($device['agent_started_at'] ?? null) !== null,
            'the panel knows when the agent started, so "has it restarted?" has an answer'
        );
        TestCase::assertContains(
            'Windows refused',
            (string) ($device['last_error'] ?? ''),
            'and keeps the last problem after it clears'
        );

        // A heartbeat with nothing wrong clears the current problems and must
        // NOT erase the record of the last one — that is the whole point.
        $api->post('/api/v1/agent/heartbeat',
            (string) json_encode(['connection_type' => 'direct']), $agent);
        $device = TenantScope::asTenant(
            (int) self::$fixtures['alpha']['tenant_id'],
            static fn (): ?array => Device::find($deviceId)
        );
        TestCase::assert(
            ($device['problems_json'] ?? null) === null,
            'a clean heartbeat clears what is wrong now'
        );
        TestCase::assertContains(
            'Windows refused',
            (string) ($device['last_error'] ?? ''),
            'and the last problem survives it, which is why the column exists'
        );

        // The button.
        $client->get('/devices/' . $deviceId);
        TestCase::assertContains('/update-now', $client->body(), 'the device page offers Update now');

        $client->post('/devices/' . $deviceId . '/update-now', ['_token' => (string) $client->csrfToken()]);
        TestCase::assertSame(302, $client->status(), 'pressing it is accepted');

        // The agent collects it on its next configuration poll — and a real
        // agent sends the revision it already has, which is the case this got
        // wrong. The endpoint answers "nothing has changed" whenever that
        // matches the network's, and an Update now request is not a change to
        // the network: without asking about it separately, the click was
        // never delivered to any agent that had ever polled before.
        $api->get('/api/v1/agent/config', $agent);
        TestCase::assertSame(200, $api->status(), 'the agent fetches its configuration');
        $config = json_decode($api->body(), true);
        $data = is_array($config) ? ($config['data'] ?? []) : [];
        $revision = (int) ($data['revision'] ?? 0);
        TestCase::assert(
            is_array($data) && ($data['update_requested'] ?? false) === true,
            'and is asked to check for an update'
        );

        // Again, as a settled agent asks: with its revision, which matches.
        // The page is re-fetched first for a fresh CSRF token — the one from
        // before was spent on the POST above.
        $client->get('/devices/' . $deviceId);
        $client->post('/devices/' . $deviceId . '/update-now', ['_token' => (string) $client->csrfToken()]);
        TestCase::assertSame(302, $client->status(), 'a second Update now is accepted');

        $api->get('/api/v1/agent/config?revision=' . $revision, $agent);
        $config = json_decode($api->body(), true);
        $data = is_array($config) ? ($config['data'] ?? []) : [];
        TestCase::assert(
            is_array($data) && ($data['update_requested'] ?? false) === true,
            'a settled agent, sending the revision it holds, is asked too'
        );

        // And that path still answers "nothing has changed" when there is no
        // request, or every settled agent would rebuild its configuration on
        // every poll for ever.
        $api->get('/api/v1/agent/config?revision=' . $revision, $agent);
        $config = json_decode($api->body(), true);
        $data = is_array($config) ? ($config['data'] ?? []) : [];
        TestCase::assert(
            is_array($data) && ($data['changed'] ?? true) === false,
            'and with nothing outstanding it is still told nothing has changed'
        );

        // And once only. Without this, one click makes every poll for ever
        // after ask for an update.
        $api->get('/api/v1/agent/config', $agent);
        $config = json_decode($api->body(), true);
        $data = is_array($config) ? ($config['data'] ?? []) : [];
        TestCase::assert(
            is_array($data) && ($data['update_requested'] ?? false) !== true,
            'the request is consumed, not repeated on every poll'
        );
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
