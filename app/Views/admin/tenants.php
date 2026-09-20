<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Customers</h2>
            <p class="text-muted"><?= e(number_format((int) $result['total'])) ?> total</p>
        </div>
        <div class="card-header-actions">
            <form method="get" class="search-form" role="search">
                <input type="search" name="q" value="<?= e($params['q']) ?>" placeholder="Company or email" aria-label="Search customers">
                <button type="submit" class="btn btn-sm">Search</button>
            </form>
            <a class="btn btn-primary" href="<?= e(url('admin/tenants/new')) ?>">New customer</a>
        </div>
    </header>

    <?php if ($result['rows'] === []): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⌂', 'title' => 'No customers', 'message' => 'Create the first customer account.',
            'actionLabel' => 'New customer', 'actionUrl' => url('admin/tenants/new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Company</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Usage</th>
                    <th scope="col">Status</th>
                    <th scope="col">Expires</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $tenant): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('admin/tenants/' . $tenant['id'])) ?>"><strong><?= e($tenant['company_name']) ?></strong></a>
                            <div class="text-muted text-sm"><?= e($tenant['slug']) ?></div>
                        </td>
                        <td>
                            <?= e($tenant['contact_person'] ?? '—') ?>
                            <div class="text-muted text-sm"><?= e($tenant['email']) ?></div>
                        </td>
                        <td class="text-sm">
                            <?= e($tenant['usage']['devices']) ?>/<?= e($tenant['device_limit']) ?> devices ·
                            <?= e($tenant['usage']['networks']) ?>/<?= e($tenant['network_limit']) ?> networks
                        </td>
                        <td><span class="status status-<?= e($tenant['status']) ?>"><?= e($tenant['status']) ?></span></td>
                        <td class="text-muted">
                            <?= $tenant['subscription_expires_at'] !== null
                                ? e(local_time($tenant['subscription_expires_at'], 'd M Y'))
                                : ($tenant['trial_ends_at'] !== null ? 'trial ends ' . e(local_time($tenant['trial_ends_at'], 'd M Y')) : '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::partial('partials.pagination', ['result' => $result]) ?>
    <?php endif; ?>
</section>
