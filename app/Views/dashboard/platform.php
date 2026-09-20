<?php
/** @var array<string,mixed> $stats */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');

$devices = $stats['devices'] ?? [];
$tenants = $stats['tenants'] ?? [];
?>
<section class="stat-grid" aria-label="Platform summary">
    <div class="stat-card">
        <span class="stat-label">Customers</span>
        <span class="stat-value"><?= e($tenants['total'] ?? 0) ?></span>
        <span class="stat-meta text-muted">
            <?= e($tenants['active'] ?? 0) ?> active · <?= e($tenants['trial'] ?? 0) ?> trial
            <?php if (($tenants['suspended'] ?? 0) > 0): ?>
                · <span class="text-warning"><?= e($tenants['suspended']) ?> suspended</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Networks</span>
        <span class="stat-value"><?= e($stats['networks'] ?? 0) ?></span>
        <span class="stat-meta text-muted">across all customers</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Devices online</span>
        <span class="stat-value" data-stat="online"><?= e($devices['online'] ?? 0) ?></span>
        <span class="stat-meta text-muted">
            <?= e($devices['direct'] ?? 0) ?> direct · <?= e($devices['relay'] ?? 0) ?> relayed
        </span>
    </div>
    <div class="stat-card<?= ($stats['relays']['up'] ?? 0) < ($stats['relays']['total'] ?? 0) ? ' stat-card-attention' : '' ?>">
        <span class="stat-label">Relays up</span>
        <span class="stat-value"><?= e($stats['relays']['up'] ?? 0) ?>/<?= e($stats['relays']['total'] ?? 0) ?></span>
        <span class="stat-meta text-muted">
            <?= ($stats['relays']['up'] ?? 0) === ($stats['relays']['total'] ?? 0) ? 'all healthy' : 'check the relay list' ?>
        </span>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <header class="card-header">
            <h2>Relay fleet</h2>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/relays')) ?>">Manage</a>
        </header>

        <?php if (empty($relays)): ?>
            <?= \App\Core\View::partial('partials.empty', [
                'icon' => '⇄',
                'title' => 'No relays registered',
                'message' => 'Devices that cannot reach each other directly need at least one relay.',
                'actionLabel' => 'Add a relay',
                'actionUrl' => url('admin/relays'),
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col">Relay</th>
                        <th scope="col">Region</th>
                        <th scope="col">Sessions</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last heartbeat</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($relays as $relay): ?>
                        <tr>
                            <td><?= e($relay['name']) ?></td>
                            <td><span class="chip"><?= e(strtoupper((string) $relay['region'])) ?></span></td>
                            <td><?= e($relay['current_sessions']) ?></td>
                            <td>
                                <span class="status status-<?= e($relay['status']) ?>"><?= e($relay['status']) ?></span>
                            </td>
                            <td class="text-muted"><?= e(time_ago($relay['last_heartbeat_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card-header">
            <h2>System</h2>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/updates')) ?>">Updates</a>
        </header>

        <dl class="detail-list">
            <div>
                <dt>Panel version</dt>
                <dd><?= e($app_version ?? 'unknown') ?></dd>
            </div>
            <div>
                <dt>Last update</dt>
                <dd>
                    <?php if ($last_update === null): ?>
                        <span class="text-muted">never run</span>
                    <?php else: ?>
                        <span class="status status-<?= e($last_update['status']) ?>"><?= e($last_update['status']) ?></span>
                        <span class="text-muted"><?= e(time_ago($last_update['finished_at'] ?? $last_update['created_at'])) ?></span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Background jobs</dt>
                <dd>
                    <?= e($stats['jobs']['pending'] ?? 0) ?> pending
                    <?php if (($stats['jobs']['failed'] ?? 0) > 0): ?>
                        · <span class="text-danger"><?= e($stats['jobs']['failed']) ?> failed</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Agent versions</dt>
                <dd>
                    <?php if (empty($agent_versions)): ?>
                        <span class="text-muted">no devices reporting</span>
                    <?php else: ?>
                        <?php foreach ($agent_versions as $version): ?>
                            <span class="chip"><?= e($version['version']) ?> × <?= e($version['devices']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </section>
</div>

<section class="card">
    <header class="card-header">
        <h2>Newest customers</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/tenants')) ?>">All customers</a>
    </header>

    <?php if (empty($recent_tenants)): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⌂',
            'title' => 'No customers yet',
            'message' => 'Create your first customer account to get started.',
            'actionLabel' => 'Add a customer',
            'actionUrl' => url('admin/tenants/new'),
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Company</th>
                    <th scope="col">Status</th>
                    <th scope="col">Joined</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_tenants as $row): ?>
                    <tr>
                        <td><a href="<?= e(url('admin/tenants/' . $row['id'])) ?>"><?= e($row['company_name']) ?></a></td>
                        <td><span class="status status-<?= e($row['status']) ?>"><?= e($row['status']) ?></span></td>
                        <td class="text-muted"><?= e(local_time($row['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
