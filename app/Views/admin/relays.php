<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Relays</h2>
            <p class="text-muted">
                Fallback path for peers that cannot reach each other directly.
                A relay forwards encrypted packets only — it cannot read what passes through it.
            </p>
        </div>
    </header>

    <?php if ($relays === []): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⇄', 'title' => 'No relays registered',
            'message' => 'Devices behind CGNAT or a strict firewall need at least one.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Region</th>
                    <th scope="col">Endpoint</th>
                    <th scope="col">Sessions</th>
                    <th scope="col">Status</th>
                    <th scope="col">Heartbeat</th>
                    <th scope="col" class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($relays as $relay): ?>
                    <tr>
                        <td><strong><?= e($relay['name']) ?></strong></td>
                        <td><span class="chip"><?= e(strtoupper((string) $relay['region'])) ?></span></td>
                        <td><code><?= e($relay['host']) ?>:<?= e($relay['port']) ?></code></td>
                        <td><?= e($relay['current_sessions']) ?> <span class="text-muted">/ <?= e($relay['capacity_mbps']) ?> Mbps</span></td>
                        <td><span class="status status-<?= e($relay['status']) ?>"><?= e($relay['status']) ?></span></td>
                        <td class="text-muted"><?= e(time_ago($relay['last_heartbeat_at'])) ?></td>
                        <td class="col-actions">
                            <form method="post" action="<?= e(url('admin/relays/' . $relay['id'] . '/delete')) ?>"
                                  class="inline" data-confirm="Remove relay <?= e($relay['name']) ?>?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <header class="card-header"><h2>Register a relay</h2></header>

    <form method="post" action="<?= e(url('admin/relays')) ?>" class="form">
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required maxlength="120" placeholder="in-bom-1">
            </div>
            <div class="field">
                <label for="region">Region</label>
                <input type="text" id="region" name="region" required maxlength="32" placeholder="in">
            </div>
        </div>

        <div class="grid-3">
            <div class="field">
                <label for="host">Hostname</label>
                <input type="text" id="host" name="host" required placeholder="relay1.example.com">
            </div>
            <div class="field">
                <label for="port">Control port (UDP)</label>
                <input type="number" id="port" name="port" min="1" max="65535" value="9000" required>
                <p class="field-hint">
                    What <code>akconnect-relay --control</code> listens on. 9000 unless you changed
                    it. Its data sockets use a separate range and are not configured here.
                </p>
                <?php if (field_error('port') !== ''): ?><p class="field-error"><?= e(field_error('port')) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label for="capacity_mbps">Capacity (Mbps)</label>
            <input type="number" id="capacity_mbps" name="capacity_mbps" min="1" value="100">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Register relay</button>
        </div>
    </form>
</section>
