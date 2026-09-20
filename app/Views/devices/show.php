<?php
/**
 * @var array<string,mixed> $device
 * @var array<string,mixed>|null $network
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<?php if (!empty($issued_token)): ?>
    <div class="alert alert-warning" role="alert">
        <span class="alert-icon" aria-hidden="true">!</span>
        <div>
            <strong>Device token — shown once.</strong>
            The agent normally collects this itself on its next poll; copy it only if you are
            configuring the device by hand.
            <div class="code-row mt-2">
                <code id="device-token"><?= e($issued_token) ?></code>
                <button type="button" class="btn btn-sm" data-action="copy" data-copy-target="device-token">Copy</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <header class="card-header">
        <div>
            <h2><?= e($device['name']) ?></h2>
            <p class="text-muted">
                <?= e($device['hostname']) ?> ·
                uid <code><?= e($device['device_uid']) ?></code>
            </p>
        </div>
        <div class="card-header-actions">
            <?= \App\Core\View::partial('partials.connection', ['device' => $device]) ?>
            <span class="status status-<?= e($device['status']) ?>"><?= e($device['status']) ?></span>
        </div>
    </header>

    <?php if ($device['status'] === 'pending' && can('device.approve')): ?>
        <div class="approve-panel">
            <h3>This device is waiting for approval</h3>
            <p class="text-muted">
                Nothing has been issued to it yet — no address, no token, no peer list.
                Approving assigns a private IP and lets it join.
            </p>

            <form method="post" action="<?= e(url('devices/' . $device['id'] . '/approve')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="text" name="virtual_ip" placeholder="Leave blank to assign automatically"
                       aria-label="Specific IP address">
                <button type="submit" class="btn btn-primary">Approve</button>
            </form>

            <form method="post" action="<?= e(url('devices/' . $device['id'] . '/revoke')) ?>" class="inline mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="reason" value="Rejected at approval">
                <button type="submit" class="btn btn-ghost">Reject</button>
            </form>
        </div>
    <?php endif; ?>

    <dl class="detail-list">
        <div><dt>Network</dt><dd>
            <?php if ($network !== null): ?>
                <a href="<?= e(url('networks/' . $network['id'])) ?>"><?= e($network['name']) ?></a>
                <code class="text-muted"><?= e($network['cidr']) ?></code>
            <?php else: ?><span class="text-muted">detached</span><?php endif; ?>
        </dd></div>
        <div><dt>Private IP</dt><dd><code><?= e($device['virtual_ip'] ?: '—') ?></code></dd></div>
        <div><dt>Operating system</dt><dd><?= e($device['os']) ?> <?= e($device['os_version']) ?> <span class="text-muted"><?= e($device['arch']) ?></span></dd></div>
        <div><dt>Agent version</dt><dd><?= e($device['agent_version'] ?: '—') ?></dd></div>
        <div><dt>Public endpoint</dt><dd><code><?= e($device['last_endpoint'] ?: '—') ?></code></dd></div>
        <div><dt>LAN endpoint</dt><dd><code><?= e($device['last_lan_endpoint'] ?: '—') ?></code></dd></div>
        <div><dt>Latency</dt><dd><?= $device['latency_ms'] !== null ? e($device['latency_ms']) . ' ms' : '—' ?></dd></div>
        <div><dt>Traffic</dt><dd>↓ <?= e(format_bytes((int) $device['rx_bytes'])) ?> · ↑ <?= e(format_bytes((int) $device['tx_bytes'])) ?></dd></div>
        <div><dt>Last seen</dt><dd><?= e(local_time($device['last_seen_at'])) ?> <span class="text-muted">(<?= e(time_ago($device['last_seen_at'])) ?>)</span></dd></div>
        <div><dt>Enrolled</dt><dd><?= e(local_time($device['created_at'])) ?></dd></div>
        <div><dt>Approved</dt><dd><?= $device['approved_at'] !== null ? e(local_time($device['approved_at'])) : '—' ?></dd></div>
        <div><dt>Public key</dt><dd>
            <code class="break"><?= e($device['public_key']) ?></code>
            <p class="field-hint">The private half never left this machine and was never sent to us.</p>
        </dd></div>
    </dl>
</section>

<?php if (can('device.update')): ?>
<section class="card">
    <header class="card-header"><h2>Settings</h2></header>

    <form method="post" action="<?= e(url('devices/' . $device['id'])) ?>" class="form">
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field">
                <label for="name">Display name</label>
                <input type="text" id="name" name="name" maxlength="120" value="<?= e($device['name']) ?>">
            </div>
            <div class="field">
                <label for="tags">Tags</label>
                <input type="text" id="tags" name="tags"
                       value="<?= e(implode(', ', (array) ($device['tags_json'] ?? []))) ?>"
                       placeholder="office, nvr, server">
                <p class="field-hint">Comma separated. Access rules can target a tag instead of a single device.</p>
            </div>
        </div>

        <label class="checkbox">
            <input type="checkbox" name="is_gateway" value="1" <?= (int) $device['is_gateway'] === 1 ? 'checked' : '' ?>>
            <span>
                This device is a gateway
                <em class="text-muted">— it can advertise its LAN subnets to the other peers.</em>
            </span>
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</section>

<section class="card card-danger">
    <header class="card-header">
        <h2>Danger zone</h2>
    </header>

    <div class="danger-row">
        <div>
            <strong>Disable</strong>
            <p class="text-muted">Stops the device connecting but keeps its address reserved.</p>
        </div>
        <form method="post" action="<?= e(url('devices/' . $device['id'] . '/disable')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Disable</button>
        </form>
    </div>

    <?php if (can('device.revoke')): ?>
    <div class="danger-row">
        <div>
            <strong>Revoke</strong>
            <p class="text-muted">
                Destroys its token, releases its address, and removes it from every peer
                within about ten seconds.
            </p>
        </div>
        <form method="post" action="<?= e(url('devices/' . $device['id'] . '/revoke')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger">Revoke</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (can('device.delete')): ?>
    <div class="danger-row">
        <div>
            <strong>Delete</strong>
            <p class="text-muted">Revokes, then removes the device from the list entirely.</p>
        </div>
        <form method="post" action="<?= e(url('devices/' . $device['id'] . '/delete')) ?>"
              data-confirm="Delete <?= e($device['name']) ?>? This cannot be undone.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
