<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\TenantScope;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BillingService;

/**
 * Platform-side customer management. Every action here requires
 * `tenant.manage`, which only an untenanted super admin can hold.
 */
final class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $params = $this->listParams($request, ['company_name', 'status', 'created_at', 'subscription_expires_at'], 'created_at');

        $result = TenantScope::acrossAllTenants(
            'platform tenant list',
            static fn (): array => Tenant::paginate(
                [],
                $params['page'],
                $params['per_page'],
                $params['sort'],
                $params['direction'],
                $params['q'],
                ['company_name', 'slug', 'email', 'contact_person']
            )
        );

        // Usage per row, so the list shows who is near a limit.
        foreach ($result['rows'] as $index => $tenant) {
            $result['rows'][$index]['usage'] = Tenant::usage((int) $tenant['id']);
        }

        return $this->view('admin.tenants', [
            'title'  => 'Customers',
            'result' => $result,
            'params' => $params,
            'plans'  => Plan::publicPlans(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin.tenant_form', [
            'title'  => 'New customer',
            'tenant' => null,
            'plans'  => Plan::publicPlans(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'company_name'   => 'required|string|max:160',
            'email'          => 'required|email',
            'contact_person' => 'nullable|string|max:120',
            'mobile'         => 'nullable|string|max:32',
            'plan_id'        => 'required|int',
            'timezone'       => 'nullable|timezone',
            'admin_name'     => 'required|string|max:120',
            'admin_email'    => 'required|email',
            'admin_password' => 'required|password',
        ], [
            'company_name'   => 'Company name',
            'admin_email'    => 'Administrator email',
            'admin_password' => 'Administrator password',
        ]);

        if (User::emailExists((string) $data['admin_email'])) {
            return $this->back($request, '', 'That administrator email address is already in use.');
        }

        $plan = Plan::findOrFail((int) $data['plan_id']);
        $trialDays = (int) (\App\Models\Setting::getInt('platform.trial_days', null, 14));

        $result = TenantScope::acrossAllTenants('create tenant', static function () use ($data, $plan, $trialDays): array {
            return DB::transaction(static function () use ($data, $plan, $trialDays): array {
                $tenantId = Tenant::create([
                    'company_name'   => $data['company_name'],
                    'slug'           => Tenant::uniqueSlug((string) $data['company_name']),
                    'contact_person' => $data['contact_person'] ?? null,
                    'mobile'         => $data['mobile'] ?? null,
                    'email'          => $data['email'],
                    'plan_id'        => (int) $plan['id'],
                    'device_limit'   => (int) $plan['device_limit'],
                    'network_limit'  => (int) $plan['network_limit'],
                    'user_limit'     => (int) $plan['user_limit'],
                    'relay_gb_month' => (int) $plan['relay_gb_month'],
                    'status'         => 'trial',
                    'timezone'       => $data['timezone'] ?? 'Asia/Kolkata',
                    'trial_ends_at'  => gmdate('Y-m-d H:i:s', time() + ($trialDays * 86400)),
                ]);

                $userId = User::create([
                    'tenant_id'     => $tenantId,
                    'name'          => $data['admin_name'],
                    'email'         => $data['admin_email'],
                    'password_hash' => Crypto::hashPassword((string) $data['admin_password']),
                    'role'          => Rbac::COMPANY_ADMIN,
                    'status'        => 'active',
                    'timezone'      => $data['timezone'] ?? 'Asia/Kolkata',
                ]);

                return ['tenant_id' => $tenantId, 'user_id' => $userId];
            });
        });

        AuditService::log('tenant.create', 'tenant', $result['tenant_id'], null, [
            'company_name' => $data['company_name'],
            'plan'         => $plan['slug'],
        ]);

        return $this->redirect(
            'admin/tenants/' . $result['tenant_id'],
            sprintf('%s created with a %d-day trial on the %s plan.', $data['company_name'], $trialDays, $plan['name'])
        );
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $tenantId = (int) $params['id'];

        $data = TenantScope::acrossAllTenants('platform tenant detail', static function () use ($tenantId): array {
            $tenant = Tenant::findOrFail($tenantId);

            return [
                'tenant' => $tenant,
                'users'  => User::where(['tenant_id' => $tenantId], 'name', 'ASC', 100),
                'usage'  => Tenant::usage($tenantId),
                'plan'   => $tenant['plan_id'] !== null ? Plan::find((int) $tenant['plan_id']) : null,
            ];
        });

        $data['networks'] = TenantScope::asTenant($tenantId, static fn (): array =>
            \App\Models\Network::where([], 'name', 'ASC', 100));
        $data['devices'] = TenantScope::asTenant($tenantId, static fn (): array =>
            \App\Models\Device::statusSummary($tenantId));

        $data['title'] = $data['tenant']['company_name'];
        $data['plans'] = Plan::publicPlans();

        return $this->view('admin.tenant_detail', $data);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $tenantId = (int) $params['id'];

        $data = Validator::validate($request->all(), [
            'company_name'            => 'required|string|max:160',
            'email'                   => 'required|email',
            'contact_person'          => 'nullable|string|max:120',
            'mobile'                  => 'nullable|string|max:32',
            'status'                  => 'required|in:trial,active,suspended,cancelled',
            'device_limit'            => 'nullable|int|min:0',
            'network_limit'           => 'nullable|int|min:0',
            'user_limit'              => 'nullable|int|min:0',
            'subscription_expires_at' => 'nullable|date',
            'timezone'                => 'nullable|timezone',
        ], ['company_name' => 'Company name']);

        TenantScope::acrossAllTenants('platform tenant update', static function () use ($tenantId, $data): void {
            $before = Tenant::findOrFail($tenantId);

            Tenant::update($tenantId, array_filter([
                'company_name'            => $data['company_name'],
                'email'                   => $data['email'],
                'contact_person'          => $data['contact_person'],
                'mobile'                  => $data['mobile'],
                'status'                  => $data['status'],
                'device_limit'            => $data['device_limit'],
                'network_limit'           => $data['network_limit'],
                'user_limit'              => $data['user_limit'],
                'subscription_expires_at' => $data['subscription_expires_at'],
                'timezone'                => $data['timezone'],
            ], static fn ($v): bool => $v !== null));

            AuditService::logChange('tenant.update', 'tenant', $tenantId, $before, Tenant::findOrFail($tenantId));
        });

        return $this->redirect('admin/tenants/' . $tenantId, 'Customer updated.');
    }

    /** @param array<string,string> $params */
    public function changePlan(Request $request, array $params): Response
    {
        $tenantId = (int) $params['id'];
        $planId = (int) $request->input('plan_id', '0');

        TenantScope::acrossAllTenants('platform plan change', static function () use ($tenantId, $planId): void {
            BillingService::applyPlan($tenantId, $planId);
        });

        return $this->redirect('admin/tenants/' . $tenantId, 'Plan applied and limits updated.');
    }

    /**
     * Sign in as a tenant user, for support.
     *
     * Audited on both ends, and platform permissions are dropped for the
     * duration — an impersonating operator cannot use the tenant session to
     * run an update or reach another customer.
     *
     * @param array<string,string> $params
     */
    public function impersonate(Request $request, array $params): Response
    {
        Auth::authorize('impersonate');

        $userId = (int) $params['userId'];

        $target = TenantScope::acrossAllTenants(
            'impersonation target lookup',
            static fn (): ?array => User::findActiveById($userId)
        );

        if ($target === null || $target['tenant_id'] === null) {
            return $this->redirect('admin/tenants', '', 'That user cannot be impersonated.');
        }

        Auth::startImpersonation($target);

        return $this->redirect('dashboard', sprintf(
            'You are now acting as %s. Use "Stop impersonating" to return to your own account.',
            $target['email']
        ));
    }

    public function stopImpersonating(Request $request): Response
    {
        Auth::stopImpersonation();

        return $this->redirect('admin/tenants', 'Returned to your own account.');
    }

    /** @param array<string,string> $params */
    public function suspend(Request $request, array $params): Response
    {
        $tenantId = (int) $params['id'];

        TenantScope::acrossAllTenants('tenant suspend', static function () use ($tenantId): void {
            $before = Tenant::findOrFail($tenantId);
            Tenant::update($tenantId, ['status' => 'suspended']);
            AuditService::logChange('tenant.suspend', 'tenant', $tenantId, $before, ['status' => 'suspended']);
        });

        // Existing tunnels are data plane and keep working (R6); what stops is
        // the ability to add capacity.
        return $this->redirect(
            'admin/tenants/' . $tenantId,
            'Customer suspended. Existing tunnels keep working; no new devices can be added.'
        );
    }
}
