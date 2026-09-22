<?php

declare(strict_types=1);

use App\Core\Router;

/**
 * Route table.
 *
 * Middleware order within a group is the order it runs:
 *   maintenance -> auth -> csrf -> can:<permission>
 *
 * Read the middleware list as the security contract for each route. Anything
 * without 'auth' is deliberately public; anything without 'csrf' on a POST is
 * deliberately token-authenticated.
 *
 * @var Router $router
 */

// ---------------------------------------------------------------- public

$router->group('', ['maintenance'], static function (Router $router): void {
    $router->get('/', static fn (): \App\Core\Response =>
        \App\Core\Response::redirect(url(\App\Core\Auth::check() ? 'dashboard' : 'login')));

    $router->get('/login', 'AuthController@showLogin', ['guest'], 'login');
    $router->post('/login', 'AuthController@login', ['guest', 'csrf', 'throttle:login']);

    $router->get('/login/2fa', 'AuthController@showTwoFactor');
    $router->post('/login/2fa', 'AuthController@verifyTwoFactor', ['csrf', 'throttle:login']);

    $router->get('/forgot-password', 'AuthController@showForgotPassword', ['guest']);
    $router->post('/forgot-password', 'AuthController@sendResetLink', ['guest', 'csrf', 'throttle:reset']);
    $router->get('/reset-password', 'AuthController@showResetPassword', ['guest']);
    $router->post('/reset-password', 'AuthController@resetPassword', ['guest', 'csrf', 'throttle:reset']);

    $router->post('/logout', 'AuthController@logout', ['csrf']);

    // The customer installer, at an address that does not change between
    // releases so a link in an email keeps working. Unauthenticated on
    // purpose — see DownloadController for why that is safe and why the
    // alternative is a customer on the phone.
    $router->get('/download/setup.exe', 'DownloadController@windowsSetup', ['throttle:download']);
    $router->get('/download/windows-pack.zip', 'DownloadController@windowsPack', ['throttle:download']);
});

// ------------------------------------------------------------ dashboard

$router->group('', ['maintenance', 'auth'], static function (Router $router): void {
    $router->get('/dashboard', 'DashboardController@index', [], 'dashboard');
    $router->get('/dashboard/stats', 'DashboardController@stats');

    // ------------------------------------------------------------ networks
    $router->get('/networks', 'NetworkController@index', ['can:network.view'], 'networks');
    $router->get('/networks/new', 'NetworkController@create', ['can:network.create']);
    $router->post('/networks', 'NetworkController@store', ['csrf', 'can:network.create']);
    $router->get('/networks/{id:int}', 'NetworkController@show', ['can:network.view'], 'network.show');
    $router->get('/networks/{id:int}/edit', 'NetworkController@edit', ['can:network.update']);
    $router->post('/networks/{id:int}', 'NetworkController@update', ['csrf', 'can:network.update']);
    $router->post('/networks/{id:int}/delete', 'NetworkController@destroy', ['csrf', 'can:network.delete']);

    $router->post('/networks/{id:int}/join-code', 'NetworkController@issueJoinCode', ['csrf', 'can:device.approve']);
    $router->post('/networks/{id:int}/join-code/revoke', 'NetworkController@revokeJoinCodes', ['csrf', 'can:device.approve']);

    $router->post('/networks/{id:int}/ips/reserve', 'NetworkController@reserveIp', ['csrf', 'can:network.update']);
    $router->post('/networks/{id:int}/ips/release', 'NetworkController@releaseIp', ['csrf', 'can:network.update']);

    $router->post('/networks/{id:int}/acl', 'NetworkController@storeAclRule', ['csrf', 'can:acl.manage']);
    $router->post('/networks/{id:int}/acl/{ruleId:int}', 'NetworkController@updateAclRule', ['csrf', 'can:acl.manage']);
    $router->post('/networks/{id:int}/acl/{ruleId:int}/delete', 'NetworkController@deleteAclRule', ['csrf', 'can:acl.manage']);

    // Advertised LANs, and the machines named inside them (§16–18). Advertising
    // is a network change; approving one is what actually puts a customer's
    // building in front of agents, so it sits behind the same permission that
    // approves a device.
    $router->post('/networks/{id:int}/routes', 'NetworkRouteController@store', ['csrf', 'can:network.update']);
    $router->post('/networks/{id:int}/routes/{routeId:int}/approve', 'NetworkRouteController@approve', ['csrf', 'can:device.approve']);
    $router->post('/networks/{id:int}/routes/{routeId:int}/withdraw', 'NetworkRouteController@withdraw', ['csrf', 'can:network.update']);
    $router->post('/networks/{id:int}/routes/{routeId:int}/hosts', 'NetworkRouteController@storeHost', ['csrf', 'can:network.update']);
    $router->post('/networks/{id:int}/hosts/{hostId:int}/delete', 'NetworkRouteController@deleteHost', ['csrf', 'can:network.update']);

    // ------------------------------------------------------------- devices
    $router->get('/devices', 'DeviceController@index', ['can:device.view'], 'devices');
    $router->get('/devices/{id:int}', 'DeviceController@show', ['can:device.view'], 'device.show');
    $router->post('/devices/{id:int}', 'DeviceController@update', ['csrf', 'can:device.update']);
    $router->post('/devices/{id:int}/approve', 'DeviceController@approve', ['csrf', 'can:device.approve']);
    $router->post('/devices/{id:int}/revoke', 'DeviceController@revoke', ['csrf', 'can:device.revoke']);
    $router->post('/devices/{id:int}/disable', 'DeviceController@disable', ['csrf', 'can:device.update']);
    $router->post('/devices/{id:int}/delete', 'DeviceController@destroy', ['csrf', 'can:device.delete']);
    $router->post('/devices/bulk-approve', 'DeviceController@bulkApprove', ['csrf', 'can:device.approve']);

    // ------------------------------------------------------------- account
    $router->get('/account', 'AccountController@profile', [], 'account');
    $router->post('/account', 'AccountController@updateProfile', ['csrf']);
    $router->post('/account/password', 'AccountController@changePassword', ['csrf']);
    $router->get('/account/2fa', 'AccountController@showTwoFactor');
    $router->post('/account/2fa/enable', 'AccountController@enableTwoFactor', ['csrf']);
    $router->post('/account/2fa/disable', 'AccountController@disableTwoFactor', ['csrf']);
    $router->post('/account/2fa/recovery', 'AccountController@regenerateRecoveryCodes', ['csrf']);

    $router->get('/notifications', 'AccountController@notifications');
    $router->post('/notifications/{id:int}/read', 'AccountController@markNotificationRead', ['csrf']);
    $router->post('/notifications/read-all', 'AccountController@markAllNotificationsRead', ['csrf']);

    // ------------------------------------------------------------ settings
    $router->get('/settings/users', 'UserController@index', ['can:user.view'], 'settings.users');
    $router->post('/settings/users', 'UserController@store', ['csrf', 'can:user.create']);
    $router->post('/settings/users/{id:int}', 'UserController@update', ['csrf', 'can:user.update']);
    $router->post('/settings/users/{id:int}/password', 'UserController@resetPassword', ['csrf', 'can:user.update']);
    $router->post('/settings/users/{id:int}/delete', 'UserController@destroy', ['csrf', 'can:user.delete']);

    $router->get('/settings/api-keys', 'ApiKeyController@index', ['can:apikey.view'], 'settings.apikeys');
    $router->post('/settings/api-keys', 'ApiKeyController@store', ['csrf', 'can:apikey.create']);
    $router->post('/settings/api-keys/{id:int}/revoke', 'ApiKeyController@revoke', ['csrf', 'can:apikey.revoke']);

    $router->get('/settings/audit', 'AuditController@index', ['can:audit.view'], 'settings.audit');
    $router->get('/settings/audit/export', 'AuditController@export', ['can:audit.view']);
});

// ------------------------------------------------------- platform admin

$router->group('/admin', ['maintenance', 'auth'], static function (Router $router): void {
    $router->get('/tenants', 'Admin\TenantController@index', ['can:tenant.manage'], 'admin.tenants');
    $router->get('/tenants/new', 'Admin\TenantController@create', ['can:tenant.manage']);
    $router->post('/tenants', 'Admin\TenantController@store', ['csrf', 'can:tenant.manage']);
    $router->get('/tenants/{id:int}', 'Admin\TenantController@show', ['can:tenant.manage']);
    $router->post('/tenants/{id:int}', 'Admin\TenantController@update', ['csrf', 'can:tenant.manage']);
    $router->post('/tenants/{id:int}/plan', 'Admin\TenantController@changePlan', ['csrf', 'can:tenant.manage']);
    $router->post('/tenants/{id:int}/suspend', 'Admin\TenantController@suspend', ['csrf', 'can:tenant.manage']);
    $router->post('/tenants/{id:int}/impersonate/{userId:int}', 'Admin\TenantController@impersonate', ['csrf', 'can:impersonate']);
    $router->post('/stop-impersonating', 'Admin\TenantController@stopImpersonating', ['csrf']);

    $router->get('/relays', 'Admin\RelayController@index', ['can:relay.manage'], 'admin.relays');
    $router->post('/relays', 'Admin\RelayController@store', ['csrf', 'can:relay.manage']);
    $router->post('/relays/{id:int}', 'Admin\RelayController@update', ['csrf', 'can:relay.manage']);
    $router->post('/relays/{id:int}/delete', 'Admin\RelayController@destroy', ['csrf', 'can:relay.manage']);

    // ----------------------------------------------------- System → Updates
    // Settings → Coordinator. DEPLOY.md Stage 3a sends an operator here.
    $router->get('/coordinator', 'Admin\CoordinatorController@index', ['can:platform.settings'], 'admin.coordinator');
    $router->post('/coordinator', 'Admin\CoordinatorController@update', ['csrf', 'can:platform.settings']);

    $router->get('/updates', 'Admin\UpdateController@index', ['can:update.manage'], 'admin.updates');
    $router->post('/updates/settings', 'Admin\UpdateController@saveSettings', ['csrf', 'can:update.manage']);
    $router->post('/updates/test-connection', 'Admin\UpdateController@testConnection', ['csrf', 'can:update.manage']);
    $router->get('/updates/check', 'Admin\UpdateController@check', ['can:update.manage']);
    $router->post('/updates/start', 'Admin\UpdateController@start', ['csrf', 'can:update.manage']);
    $router->post('/updates/step', 'Admin\UpdateController@step', ['csrf', 'can:update.manage']);
    $router->get('/updates/status', 'Admin\UpdateController@status', ['can:update.manage']);
    $router->post('/updates/rollback', 'Admin\UpdateController@rollback', ['csrf', 'can:update.manage']);
    $router->get('/updates/history', 'Admin\UpdateController@history', ['can:update.manage']);
    $router->get('/updates/{id:int}', 'Admin\UpdateController@detail', ['can:update.manage']);
    $router->get('/updates/{id:int}/log', 'Admin\UpdateController@downloadLog', ['can:update.manage']);

    // ----------------------------------------------------- System → Backups
    $router->get('/backups', 'Admin\BackupController@index', ['can:backup.manage'], 'admin.backups');
    $router->post('/backups', 'Admin\BackupController@create', ['csrf', 'can:backup.manage']);
    $router->post('/backups/prune', 'Admin\BackupController@prune', ['csrf', 'can:backup.manage']);
    $router->post('/backups/{id:int}/verify', 'Admin\BackupController@verify', ['csrf', 'can:backup.manage']);
    $router->post('/backups/{id:int}/restore', 'Admin\BackupController@restore', ['csrf', 'can:backup.manage']);
    $router->get('/backups/{id:int}/download', 'Admin\BackupController@download', ['can:backup.manage']);
});

// ---------------------------------------------------------------- API v1

$router->group('/api/v1', ['maintenance'], static function (Router $router): void {
    // Agent enrolment: no credential exists yet, so this is public but
    // throttled per IP and gated by a short-lived join code.
    $router->post('/enroll', 'Api\AgentController@enroll', ['throttle:enroll']);
    $router->post('/agent/claim', 'Api\AgentController@claim', ['throttle:enroll']);

    // The coordinator service (§7.3). Not a user and not a device: it
    // authenticates with the shared secret both halves were installed with,
    // and it is the only caller allowed to ask about a device it does not own.
    $router->post('/coordinator/verify', 'Api\CoordinatorController@verifyDevice', ['coordinator']);
    $router->post('/coordinator/endpoints', 'Api\CoordinatorController@reportEndpoints', ['coordinator']);
    // Billing. The relay measured it; the coordinator relays it; nothing a
    // customer controls is on this path.
    $router->post('/coordinator/relay-usage', 'Api\CoordinatorController@reportRelayUsage', ['coordinator']);

    // The edge servers' upgrade path. Same secret, same reasoning, and the
    // direction is deliberate: the edge asks and tells, the panel never
    // reaches in. See EdgeRelease for why Update Now does not do this itself.
    $router->get('/edge/release', 'Api\EdgeController@release', ['coordinator']);
    $router->post('/edge/report', 'Api\EdgeController@report', ['coordinator']);
    $router->post('/edge/artifact', 'Api\EdgeController@artifact', ['coordinator']);

    // Everything else an agent calls needs its device token.
    $router->get('/agent/config', 'Api\AgentController@config', ['device']);
    $router->post('/agent/heartbeat', 'Api\AgentController@heartbeat', ['device']);
    $router->post('/agent/endpoint', 'Api\AgentController@endpoint', ['device']);
    $router->get('/agent/version', 'Api\AgentController@version', ['device']);

    // Tenant API: bearer API key, or a dashboard session.
    $router->group('', ['api'], static function (Router $router): void {
        $router->get('/networks', 'Api\V1Controller@listNetworks');
        $router->post('/networks', 'Api\V1Controller@createNetwork');
        $router->get('/networks/{id:int}', 'Api\V1Controller@getNetwork');
        $router->patch('/networks/{id:int}', 'Api\V1Controller@updateNetwork');
        $router->delete('/networks/{id:int}', 'Api\V1Controller@deleteNetwork');
        $router->get('/networks/{id:int}/members', 'Api\V1Controller@networkMembers');
        $router->get('/networks/{id:int}/routes', 'Api\V1Controller@networkRoutes');
        $router->get('/networks/{id:int}/acl', 'Api\V1Controller@listAcl');
        $router->post('/networks/{id:int}/acl', 'Api\V1Controller@createAclRule');
        $router->put('/networks/{id:int}/acl/{ruleId:int}', 'Api\V1Controller@updateAclRule');
        $router->delete('/networks/{id:int}/acl/{ruleId:int}', 'Api\V1Controller@deleteAclRule');

        $router->get('/devices', 'Api\V1Controller@listDevices');
        $router->get('/devices/{id:int}', 'Api\V1Controller@getDevice');
        $router->patch('/devices/{id:int}', 'Api\V1Controller@updateDevice');
        $router->delete('/devices/{id:int}', 'Api\V1Controller@deleteDevice');
        $router->post('/devices/{id:int}/approve', 'Api\V1Controller@approveDevice');
        $router->post('/devices/{id:int}/revoke', 'Api\V1Controller@revokeDevice');
        $router->post('/devices/{id:int}/disable', 'Api\V1Controller@disableDevice');

        $router->get('/relays', 'Api\V1Controller@listRelays');
        $router->get('/audit-logs', 'Api\V1Controller@auditLogs');
        $router->get('/usage', 'Api\V1Controller@usage');
    });

    // SSE needs a dashboard session; an API key would keep a worker busy for
    // a minute at a time to no purpose.
    $router->get('/stream', 'Api\StreamController@stream', ['auth']);
});
