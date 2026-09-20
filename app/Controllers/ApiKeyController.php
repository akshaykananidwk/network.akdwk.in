<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\ApiKey;
use App\Services\AuditService;
use App\Services\BillingService;

/**
 * Tenant API keys.
 *
 * The plaintext key is returned exactly once, in a flash message, and is
 * thereafter unrecoverable — only its SHA-256 hash is stored.
 */
final class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $result = ApiKey::paginate([], max(1, (int) $request->query('page', '1')), 25, 'created_at', 'DESC');

        return $this->view('settings.api_keys', [
            'title'      => 'API keys',
            'result'     => $result,
            'scopes'     => Rbac::allPermissions(),
            'new_key'    => Session::flash('new_api_key'),
        ]);
    }

    public function store(Request $request): Response
    {
        $tenantId = Auth::tenantId();
        if ($tenantId === null) {
            return $this->back($request, '', 'API keys belong to a customer account.');
        }

        BillingService::assertFeature($tenantId, 'api_access', 'API access');

        $data = Validator::validate($request->all(), [
            'name'       => 'required|string|max:120',
            'expires_at' => 'nullable|date',
        ], ['name' => 'Key name']);

        $requested = $request->all()['scopes'] ?? [];
        $scopes = is_array($requested) ? array_values(array_map('strval', $requested)) : [];

        // A key can never exceed its creator's own permissions, and platform
        // permissions are never grantable to a tenant key at all.
        $scopes = array_values(array_filter(
            $scopes,
            static fn (string $scope): bool => !Rbac::isPlatformPermission($scope) && Auth::can($scope)
        ));

        if ($scopes === []) {
            return $this->back($request, '', 'Select at least one permission that you hold yourself.');
        }

        $issued = ApiKey::issue(
            $tenantId,
            Auth::id(),
            (string) $data['name'],
            $scopes,
            $data['expires_at'] !== null ? (string) $data['expires_at'] : null
        );

        AuditService::log('apikey.create', 'api_key', $issued['id'], null, [
            'name'   => $data['name'],
            'scopes' => $scopes,
        ]);

        Session::flash('new_api_key', $issued['plain']);

        return $this->redirect('settings/api-keys', 'Key created. Copy it now — it is not shown again.');
    }

    /** @param array<string,string> $params */
    public function revoke(Request $request, array $params): Response
    {
        $keyId = (int) $params['id'];
        $key = ApiKey::findOrFail($keyId);

        ApiKey::revoke($keyId);
        AuditService::log('apikey.revoke', 'api_key', $keyId, ['name' => $key['name']], null);

        return $this->redirect('settings/api-keys', sprintf('Key "%s" revoked. It stops working immediately.', $key['name']));
    }
}
