<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DbSessionHandler;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BillingService;

/**
 * Team management within a tenant.
 *
 * The privilege rules are enforced here rather than trusted to the form: a
 * tenant admin can only assign roles from Rbac::assignableRoles() (never
 * super_admin), cannot edit a user outside their tenant (the model scope
 * prevents it), and cannot demote or delete themselves into a lockout.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $params = $this->listParams($request, ['name', 'email', 'role', 'last_login_at', 'created_at'], 'name');

        $result = User::paginate(
            [],
            $params['page'],
            $params['per_page'],
            $params['sort'],
            $params['sort'] === 'name' ? 'ASC' : $params['direction'],
            $params['q'],
            ['name', 'email']
        );

        return $this->view('settings.users', [
            'title'  => 'Users & roles',
            'result' => $result,
            'params' => $params,
            'roles'  => Rbac::assignableRoles(),
            'matrix' => Rbac::matrix(),
            'catalogue' => Rbac::catalogue(),
        ]);
    }

    public function store(Request $request): Response
    {
        $tenantId = Auth::tenantId();
        if ($tenantId === null) {
            return $this->back($request, '', 'Platform users are managed from the customer screen.');
        }

        $data = Validator::validate($request->all(), [
            'name'     => 'required|string|max:120',
            'email'    => 'required|email',
            'password' => 'required|password',
            'role'     => 'required|in:' . implode(',', Rbac::assignableRoles()),
        ], ['email' => 'Email address', 'password' => 'Password']);

        BillingService::assertCanAddUser($tenantId);

        if (User::emailExists((string) $data['email'])) {
            // Same message whether the address belongs to this tenant or
            // another: it must not reveal that an account exists elsewhere.
            return $this->back($request, '', 'That email address cannot be used.');
        }

        $userId = User::create([
            'tenant_id'     => $tenantId,
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password_hash' => Crypto::hashPassword((string) $data['password']),
            'role'          => $data['role'],
            'status'        => 'active',
        ]);

        AuditService::log('user.create', 'user', $userId, null, [
            'email' => $data['email'],
            'role'  => $data['role'],
        ]);

        return $this->redirect('settings/users', sprintf('%s added as %s.', $data['email'], Rbac::label((string) $data['role'])));
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];
        $before = User::findOrFail($userId);

        $data = Validator::validate($request->all(), [
            'name'   => 'required|string|max:120',
            'role'   => 'required|in:' . implode(',', Rbac::assignableRoles()),
            'status' => 'nullable|in:active,disabled',
        ], ['name' => 'Name']);

        // Removing your own last route back in is a support call waiting to
        // happen; refuse it rather than letting it through.
        if ($userId === Auth::id() && $data['role'] !== $before['role']) {
            return $this->back($request, '', 'You cannot change your own role. Ask another administrator.');
        }

        User::update($userId, [
            'name'   => $data['name'],
            'role'   => $data['role'],
            'status' => $data['status'] ?? $before['status'],
        ]);

        // A role change or a disable must not leave an open session with the
        // old privileges.
        if ($data['role'] !== $before['role'] || ($data['status'] ?? '') === 'disabled') {
            DbSessionHandler::destroyForUser($userId);
        }

        AuditService::logChange('user.update', 'user', $userId, $before, User::findOrFail($userId));

        return $this->redirect('settings/users', 'User updated.');
    }

    /** @param array<string,string> $params */
    public function resetPassword(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];
        User::findOrFail($userId);

        $data = Validator::validate($request->all(), ['password' => 'required|password'], ['password' => 'Password']);

        User::setPassword($userId, (string) $data['password']);
        DbSessionHandler::destroyForUser($userId);

        AuditService::log('user.password_reset', 'user', $userId);

        return $this->redirect('settings/users', 'Password changed and that user\'s sessions ended.');
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];

        if ($userId === Auth::id()) {
            return $this->back($request, '', 'You cannot delete your own account.');
        }

        $user = User::findOrFail($userId);

        // The last administrator must not be removable, or the tenant locks
        // itself out of its own account.
        if ($user['role'] === Rbac::COMPANY_ADMIN) {
            $remaining = User::count(['role' => Rbac::COMPANY_ADMIN, 'status' => 'active']);
            if ($remaining <= 1) {
                return $this->back($request, '', 'This is the only administrator. Promote someone else first.');
            }
        }

        User::delete($userId);
        DbSessionHandler::destroyForUser($userId);

        AuditService::log('user.delete', 'user', $userId, ['email' => $user['email'], 'role' => $user['role']], null);

        return $this->redirect('settings/users', sprintf('%s removed.', $user['email']));
    }
}
