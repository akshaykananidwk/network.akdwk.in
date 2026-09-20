<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2><?= e($tenant['company_name']) ?></h2>
            <p class="text-muted">
                <?= e($tenant['email']) ?> · <?= e($tenant['slug']) ?>
                <?php if ($plan !== null): ?> · <?= e($plan['name']) ?> plan<?php endif; ?>
            </p>
        </div>
        <div class="card-header-actions">
            <span class="status status-<?= e($tenant['status']) ?>"><?= e($tenant['status']) ?></span>
        </div>
    </header>

    <div class="stat-grid stat-grid-compact">
        <div class="stat-card">
            <span class="stat-label">Devices</span>
            <span class="stat-value"><?= e($usage['devices']) ?></span>
            <span class="stat-meta text-muted">limit <?= e($tenant['device_limit']) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Networks</span>
            <span class="stat-value"><?= e($usage['networks']) ?></span>
            <span class="stat-meta text-muted">limit <?= e($tenant['network_limit']) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Users</span>
            <span class="stat-value"><?= e($usage['users']) ?></span>
            <span class="stat-meta text-muted">limit <?= e($tenant['user_limit']) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Online now</span>
            <span class="stat-value"><?= e($devices['online'] ?? 0) ?></span>
            <span class="stat-meta text-muted"><?= e($devices['direct'] ?? 0) ?> direct</span>
        </div>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <header class="card-header"><h2>Account</h2></header>

        <form method="post" action="<?= e(url('admin/tenants/' . $tenant['id'])) ?>" class="form">
            <?= csrf_field() ?>

            <div class="field">
                <label for="company_name">Company name</label>
                <input type="text" id="company_name" name="company_name" required value="<?= e($tenant['company_name']) ?>">
            </div>

            <div class="field">
                <label for="email">Billing email</label>
                <input type="email" id="email" name="email" required value="<?= e($tenant['email']) ?>">
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="contact_person">Contact</label>
                    <input type="text" id="contact_person" name="contact_person" value="<?= e($tenant['contact_person'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="mobile">Mobile</label>
                    <input type="tel" id="mobile" name="mobile" value="<?= e($tenant['mobile'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['trial', 'active', 'suspended', 'cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $tenant['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid-3">
                <div class="field">
                    <label for="device_limit">Device limit</label>
                    <input type="number" id="device_limit" name="device_limit" min="0" value="<?= e($tenant['device_limit']) ?>">
                </div>
                <div class="field">
                    <label for="network_limit">Network limit</label>
                    <input type="number" id="network_limit" name="network_limit" min="0" value="<?= e($tenant['network_limit']) ?>">
                </div>
                <div class="field">
                    <label for="user_limit">User limit</label>
                    <input type="number" id="user_limit" name="user_limit" min="0" value="<?= e($tenant['user_limit']) ?>">
                </div>
            </div>

            <div class="field">
                <label for="subscription_expires_at">Subscription expires</label>
                <input type="date" id="subscription_expires_at" name="subscription_expires_at"
                       value="<?= e($tenant['subscription_expires_at'] !== null ? substr((string) $tenant['subscription_expires_at'], 0, 10) : '') ?>">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </section>

    <section class="card">
        <header class="card-header"><h2>Users</h2></header>

        <ul class="list">
            <?php foreach ($users as $user): ?>
                <li class="list-row">
                    <div class="list-main">
                        <strong><?= e($user['name']) ?></strong>
                        <span class="text-muted"><?= e($user['email']) ?></span>
                        <span class="chip chip-sm"><?= e(\App\Core\Rbac::label((string) $user['role'])) ?></span>
                    </div>
                    <div class="list-meta">
                        <?php if (can('impersonate') && $user['status'] === 'active'): ?>
                            <form method="post"
                                  action="<?= e(url('admin/tenants/' . $tenant['id'] . '/impersonate/' . $user['id'])) ?>"
                                  data-confirm="Sign in as <?= e($user['email']) ?>? This is recorded in the audit log for both accounts.">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Sign in as</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="card-footer">
            <form method="post" action="<?= e(url('admin/tenants/' . $tenant['id'] . '/plan')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <select name="plan_id" aria-label="Change plan">
                    <?php foreach ($plans as $planOption): ?>
                        <option value="<?= e($planOption['id']) ?>"
                            <?= (int) ($tenant['plan_id'] ?? 0) === (int) $planOption['id'] ? 'selected' : '' ?>>
                            <?= e($planOption['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm">Apply plan</button>
            </form>
        </div>
    </section>
</div>

<section class="card">
    <header class="card-header"><h2>Networks</h2></header>
    <?php if ($networks === []): ?>
        <p class="text-muted p-4">No networks yet.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($networks as $network): ?>
                <li class="list-row">
                    <div class="list-main">
                        <strong><?= e($network['name']) ?></strong>
                        <code class="text-muted"><?= e($network['cidr']) ?></code>
                    </div>
                    <span class="status status-<?= e($network['status']) ?>"><?= e($network['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
