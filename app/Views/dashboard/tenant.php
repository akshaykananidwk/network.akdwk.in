<?php
/**
 * @var array<string,mixed> $stats
 * @var list<array<string,mixed>> $networks
 * @var list<array<string,mixed>> $pending_devices
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');

$devices = $stats['devices'] ?? ['total' => 0, 'online' => 0, 'direct' => 0, 'relay' => 0, 'pending' => 0];
?>

<?php if ($networks === []): ?>
    <section class="onboarding card">
        <h2>Get your first network running</h2>
        <p class="text-muted">Five steps, about ten minutes.</p>
        <ol class="onboarding-steps">
            <li><strong>Create a network</strong> — choose a private range such as 10.50.0.0/16.</li>
            <li><strong>Copy the install command</strong> shown on the network page.</li>
            <li><strong>Run it</strong> on each PC, server, NVR or NAS you want to connect.</li>
            <li><strong>Approve</strong> each device as it appears here.</li>
            <li><strong>Done</strong> — the devices can reach each other on their private IPs.</li>
        </ol>
        <?php if (can('network.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('networks/new')) ?>">Create your first network</a>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="stat-grid" aria-label="Summary">
    <div class="stat-card">
        <span class="stat-label">Devices online</span>
        <span class="stat-value" data-stat="online"><?= e($devices['online']) ?></span>
        <span class="stat-meta text-muted">of <?= e($devices['total']) ?> total</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Direct connections</span>
        <span class="stat-value" data-stat="direct"><?= e($devices['direct']) ?></span>
        <span class="stat-meta text-muted">peer to peer, no relay</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Via relay</span>
        <span class="stat-value" data-stat="relay"><?= e($devices['relay']) ?></span>
        <span class="stat-meta text-muted">fallback path</span>
    </div>
    <div class="stat-card<?= ($devices['pending'] ?? 0) > 0 ? ' stat-card-attention' : '' ?>">
        <span class="stat-label">Awaiting approval</span>
        <span class="stat-value" data-stat="pending"><?= e($devices['pending']) ?></span>
        <span class="stat-meta text-muted">
            <?= ($devices['pending'] ?? 0) > 0 ? 'needs your attention' : 'nothing waiting' ?>
        </span>
    </div>
</section>

<?php if (!empty($pending_devices)): ?>
    <section class="card">
        <header class="card-header">
            <h2>Devices waiting for approval</h2>
            <p class="text-muted">No device joins a network until you approve it.</p>
        </header>

        <form method="post" action="<?= e(url('devices/bulk-approve')) ?>">
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col" class="col-check">
                            <input type="checkbox" data-action="select-all" aria-label="Select all">
                        </th>
                        <th scope="col">Device</th>
                        <th scope="col">Operating system</th>
                        <th scope="col">Requested</th>
                        <th scope="col" class="col-actions">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending_devices as $device): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= e($device['id']) ?>"
                                       aria-label="Select <?= e($device['name']) ?>"></td>
                            <td>
                                <a href="<?= e(url('devices/' . $device['id'])) ?>"><?= e($device['name']) ?></a>
                                <div class="text-muted text-sm"><?= e($device['hostname']) ?></div>
                            </td>
                            <td><?= e($device['os']) ?> <span class="text-muted"><?= e($device['os_version']) ?></span></td>
                            <td><?= e(time_ago($device['created_at'])) ?></td>
                            <td class="col-actions">
                                <?php if (can('device.approve')): ?>
                                    <a class="btn btn-sm btn-primary"
                                       href="<?= e(url('devices/' . $device['id'])) ?>">Review</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (can('device.approve')): ?>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">Approve selected</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <header class="card-header">
            <h2>Networks</h2>
            <?php if (can('network.create')): ?>
                <a class="btn btn-sm" href="<?= e(url('networks/new')) ?>">New network</a>
            <?php endif; ?>
        </header>

        <?php if ($networks === []): ?>
            <?= \App\Core\View::partial('partials.empty', [
                'icon' => '⬡',
                'title' => 'No networks yet',
                'message' => 'A network is a private address range that your devices share.',
            ]) ?>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($networks as $network): ?>
                    <li class="list-row">
                        <a href="<?= e(url('networks/' . $network['id'])) ?>" class="list-main">
                            <strong><?= e($network['name']) ?></strong>
                            <span class="text-muted"><?= e($network['cidr']) ?></span>
                        </a>
                        <span class="list-meta">
                            <span class="chip chip-online"><?= e($network['device_counts']['online']) ?> online</span>
                            <span class="text-muted"><?= e($network['device_counts']['total']) ?> devices</span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card-header">
            <h2>Plan usage</h2>
            <span class="text-muted text-sm">Limits apply when you add capacity</span>
        </header>

        <div class="meters">
            <?php foreach (($stats['meters'] ?? []) as $label => $meter): ?>
                <div class="meter">
                    <div class="meter-head">
                        <span><?= e(ucfirst((string) $label)) ?></span>
                        <span class="text-muted">
                            <?= e($meter['used']) ?> / <?= e($meter['limit'] > 0 ? $meter['limit'] : 'unlimited') ?>
                        </span>
                    </div>
                    <div class="meter-track">
                        <span class="meter-fill<?= $meter['percent'] >= 90 ? ' is-critical' : ($meter['percent'] >= 75 ? ' is-warning' : '') ?>"
                              style="width: <?= e(min(100, (int) $meter['percent'])) ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="card">
    <header class="card-header">
        <h2>Recent activity</h2>
        <?php if (can('audit.view')): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('settings/audit')) ?>">Full audit log</a>
        <?php endif; ?>
    </header>

    <?php if (empty($recent_audit)): ?>
        <?= \App\Core\View::partial('partials.empty', ['icon' => '◷', 'title' => 'No activity yet', 'message' => '']) ?>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($recent_audit as $entry): ?>
                <li class="timeline-row">
                    <span class="timeline-dot<?= $entry['result'] === 'failure' ? ' is-failure' : '' ?>" aria-hidden="true"></span>
                    <span class="timeline-body">
                        <strong><?= e($entry['action']) ?></strong>
                        <span class="text-muted"><?= e($entry['user_email'] ?? 'system') ?></span>
                    </span>
                    <time class="text-muted" datetime="<?= e($entry['created_at']) ?>"><?= e(time_ago($entry['created_at'])) ?></time>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
