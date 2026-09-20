<?php
/** @var array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $result */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Devices</h2>
            <p class="text-muted"><?= e(number_format((int) $result['total'])) ?> matching</p>
        </div>

        <form method="get" class="filter-bar" role="search">
            <input type="search" name="q" value="<?= e($params['q']) ?>" placeholder="Name, IP, hostname" aria-label="Search devices">

            <select name="status" aria-label="Filter by status">
                <option value="">Any status</option>
                <?php foreach (['pending' => 'Pending', 'authorized' => 'Authorized', 'disabled' => 'Disabled', 'revoked' => 'Revoked'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filter_status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="network" aria-label="Filter by network">
                <option value="">Any network</option>
                <?php foreach ($networks as $network): ?>
                    <option value="<?= e($network['id']) ?>" <?= $filter_network === (int) $network['id'] ? 'selected' : '' ?>>
                        <?= e($network['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-sm">Filter</button>
        </form>
    </header>

    <?php if ($result['rows'] === []): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '▢',
            'title' => 'No devices match',
            'message' => 'Devices appear here as soon as the agent is installed and runs its first enrolment.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Device</th>
                    <th scope="col">OS</th>
                    <th scope="col">Agent</th>
                    <th scope="col">Private IP</th>
                    <th scope="col">Connection</th>
                    <th scope="col">Endpoint</th>
                    <th scope="col">Traffic</th>
                    <th scope="col">Last seen</th>
                    <th scope="col">Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $device): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('devices/' . $device['id'])) ?>"><strong><?= e($device['name']) ?></strong></a>
                            <div class="text-muted text-sm"><?= e($device['hostname']) ?></div>
                            <?php foreach ((array) ($device['tags_json'] ?? []) as $tag): ?>
                                <span class="chip chip-sm"><?= e($tag) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td><?= e($device['os']) ?><div class="text-muted text-sm"><?= e($device['os_version']) ?></div></td>
                        <td class="text-muted"><?= e($device['agent_version'] ?: '—') ?></td>
                        <td><code><?= e($device['virtual_ip'] ?: '—') ?></code></td>
                        <td><?= \App\Core\View::partial('partials.connection', ['device' => $device]) ?></td>
                        <td class="text-muted text-sm"><?= e($device['last_endpoint'] ?: '—') ?></td>
                        <td class="text-muted text-sm">
                            ↓ <?= e(format_bytes((int) $device['rx_bytes'])) ?><br>
                            ↑ <?= e(format_bytes((int) $device['tx_bytes'])) ?>
                        </td>
                        <td class="text-muted"><?= e(time_ago($device['last_seen_at'])) ?></td>
                        <td><span class="status status-<?= e($device['status']) ?>"><?= e($device['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::partial('partials.pagination', ['result' => $result]) ?>
    <?php endif; ?>
</section>
