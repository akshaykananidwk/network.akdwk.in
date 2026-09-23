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

    <?php
        // What the device says it could not do. Reported by its agent on every
        // heartbeat, so this clears itself once the cause is fixed rather than
        // needing somebody to dismiss it.
        $problems = json_decode((string) ($device['problems_json'] ?? ''), true);
        $problems = is_array($problems) ? $problems : [];
    ?>
    <?php if ($problems !== []): ?>
        <div class="alert alert-warning">
            <h3>This device reported <?= count($problems) === 1 ? 'a problem' : count($problems) . ' problems' ?></h3>
            <p class="text-muted">
                The agent is running and could not do these things by itself. Most need a change
                on this panel; one of them &mdash; nothing answering its announcements &mdash;
                needs a change on the network that device is on.
            </p>
            <ul>
                <?php foreach ($problems as $problem): ?>
                    <li>
                        <code><?= e((string) ($problem['code'] ?? '')) ?></code>
                        <?= e((string) ($problem['detail'] ?? '')) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="text-muted small">
                Last reported <?= e((string) ($device['problems_at'] ?? 'unknown')) ?> UTC. This
                clears on its own once the agent stops reporting it.
            </p>
        </div>
    <?php endif; ?>

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
        <div><dt>Agent version</dt><dd>
            <?= e($device['agent_version'] ?: '—') ?>
            <?php
            // What this device did about the last release it was offered.
            //
            // An all-in-one stayed on 1.9.3 for days after 1.9.4 was
            // published and nobody found out, because everything the agent
            // does about an update it does silently: from here a machine that
            // never checked and one that refused a bad signature both looked
            // like a version number that had not moved.
            $updateState = (string) ($device['update_state'] ?? 'idle');
            $updateVersion = (string) ($device['update_version'] ?? '');
            $updateError = (string) ($device['update_error'] ?? '');
            $updateChecked = $device['update_checked_at'] ?? null;
            ?>
            <?php if ($updateChecked === null): ?>
                <p class="field-hint">This device has not checked for an update yet. A running
                    agent checks five minutes after it starts and every six hours after that.</p>
            <?php elseif ($updateState === 'failed'): ?>
                <p class="field-hint text-danger">
                    <?= e($updateVersion !== '' ? 'Update to ' . $updateVersion . ' failed' : 'The update check failed') ?>
                    <?= $updateError !== '' ? ': ' . e($updateError) : '' ?>
                    <span class="text-muted">(<?= e(time_ago($updateChecked)) ?>)</span>
                </p>
            <?php elseif ($updateState === 'installed'): ?>
                <p class="field-hint">Installed <?= e($updateVersion) ?> and restarted
                    <span class="text-muted">(<?= e(time_ago($updateChecked)) ?>)</span>.</p>
            <?php elseif ($updateState === 'downloading' || $updateState === 'offered'): ?>
                <p class="field-hint">Updating to <?= e($updateVersion) ?>
                    <span class="text-muted">(<?= e(time_ago($updateChecked)) ?>)</span>.</p>
            <?php else: ?>
                <p class="field-hint">Up to date as of <?= e(time_ago($updateChecked)) ?>.</p>
            <?php endif; ?>
        </dd></div>
        <div><dt>Public endpoint</dt><dd>
            <code><?= e($device['last_endpoint'] ?: '—') ?></code>
            <?php if ((string) ($device['connection_type'] ?? '') === 'relay_https'): ?>
                <p class="field-hint">This device is on the HTTPS path, so what the coordinator
                    sees is the relay passing its messages on — not the customer's own address.
                    The address they are seen as is in the agent's status file.</p>
            <?php endif; ?>
        </dd></div>
        <div><dt>LAN endpoint</dt><dd><code><?= e($device['last_lan_endpoint'] ?: '—') ?></code></dd></div>
        <div><dt>Latency</dt><dd><?= $device['latency_ms'] !== null ? e($device['latency_ms']) . ' ms' : '—' ?></dd></div>
        <div><dt>Traffic</dt><dd>↓ <?= e(format_bytes((int) $device['rx_bytes'])) ?> · ↑ <?= e(format_bytes((int) $device['tx_bytes'])) ?></dd></div>
        <div><dt>Last seen</dt><dd><?= e(local_time($device['last_seen_at'])) ?> <span class="text-muted">(<?= e(time_ago($device['last_seen_at'])) ?>)</span></dd></div>
        <div><dt>Agent running since</dt><dd>
            <?php if (($device['agent_started_at'] ?? null) !== null): ?>
                <?= e(local_time($device['agent_started_at'])) ?>
                <span class="text-muted">(<?= e(time_ago($device['agent_started_at'])) ?>)</span>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
            <p class="field-hint">Resets when the computer restarts or the service does.</p>
        </dd></div>
        <div><dt>Last problem</dt><dd>
            <?php if (($device['last_error'] ?? null) !== null && $device['last_error'] !== ''): ?>
                <?= e((string) $device['last_error']) ?>
                <span class="text-muted">(<?= e(time_ago($device['last_error_at'])) ?>)</span>
                <p class="field-hint">
                    Kept after it cleared. If nothing is listed above this, it is not happening now.
                </p>
            <?php else: ?>
                <span class="text-muted">none reported</span>
            <?php endif; ?>
        </dd></div>
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

<?php if (can('network.update') && $device['status'] === 'authorized'): ?>
<section class="card">
    <header class="card-header">
        <h2>Share this computer's network</h2>
        <p class="text-muted">
            So the other computers can reach things that cannot run the agent themselves —
            a camera recorder, a printer, a billing machine.
        </p>
    </header>

    <?php if (($shared_lans ?? []) !== []): ?>
        <table class="table">
            <thead><tr><th>Shared range</th><th>Others reach it at</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($shared_lans as $route): ?>
                <tr>
                    <td><code><?= e((string) $route['destination_cidr']) ?></code></td>
                    <td><code><?= e((string) $route['mapped_cidr']) ?></code></td>
                    <td>
                        <?= (int) $route['approved'] === 1
                            ? '<span class="badge badge-ok">live</span>'
                            : '<span class="badge">waiting for approval</span>' ?>
                    </td>
                    <td class="text-right">
                        <?php if (can('network.update')): ?>
                            <?php
                            // Removing it here rather than only on the network
                            // page, because this is where it was shared from —
                            // a range typed wrongly was unremovable from the
                            // one screen that offered to create it.
                            ?>
                            <form method="post" class="inline"
                                  action="<?= e(url('networks/' . (int) $route['network_id'] . '/routes/' . (int) $route['id'] . '/withdraw')) ?>"
                                  onsubmit="return confirm('Stop sharing <?= e((string) $route['destination_cidr']) ?>?\n\nThe other computers lose their route to it, and <?= e((string) $route['mapped_cidr']) ?> stops answering. Nothing on this network is changed.');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="field-hint">
            The address is translated on purpose: two customers can both use 192.168.1.0/24
            and neither has to renumber. The last number is kept, so 192.168.10.1 is
            reachable at the same .1 in the range above.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('devices/' . $device['id'] . '/share-lan')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="destination_cidr">Range to share</label>
            <input type="text" id="destination_cidr" name="destination_cidr"
                   value="<?= e((string) ($suggested_lan ?? '')) ?>"
                   placeholder="192.168.10.0/24">
            <p class="field-hint">
                <?php if (($suggested_lan ?? '') !== ''): ?>
                    Filled in from the address this computer reports
                    (<code><?= e((string) ($device['last_lan_endpoint'] ?? '')) ?></code>).
                    Check it against the router before sharing — a site on a larger range
                    needs the larger range typed here.
                <?php else: ?>
                    This computer has not reported a local address yet. Type the range its
                    own network uses, as the router shows it.
                <?php endif; ?>
            </p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Share this network</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if (can('device.update')): ?>
<section class="card">
    <header class="card-header"><h2>Agent</h2></header>

    <div class="danger-row">
        <div>
            <strong>Update now</strong>
            <p class="text-muted">
                This device checks for a new agent every six hours by itself. Use this when
                you are waiting on one: it picks the request up within a minute, downloads
                what this panel offers, and installs it only if the signature verifies.
                Running <?= e($device['agent_version'] ?: 'an unknown version') ?>.
            </p>
        </div>
        <form method="post" action="<?= e(url('devices/' . $device['id'] . '/update-now')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Update now</button>
        </form>
    </div>
</section>
<?php endif; ?>

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
        <!-- A dialog, not window.confirm.
             A native confirm puts its OK button under the finger that just
             tapped Delete, so on a phone a double tap deletes a device without
             anyone reading anything — which is how one of ours went, at 14:08.
             This puts Cancel under that finger instead, and makes the
             destructive button the one you have to reach for. -->
        <button type="button" class="btn btn-danger"
                data-action="confirm-delete-device"
                data-device-name="<?= e($device['name']) ?>">Delete</button>
        <form method="post" id="delete-device-form"
              action="<?= e(url('devices/' . $device['id'] . '/delete')) ?>" hidden>
            <?= csrf_field() ?>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<dialog id="delete-device-dialog" class="dialog">
    <h2>Delete this device?</h2>
    <p>
        <strong id="delete-device-name"></strong> will be revoked and removed from the list.
        It loses its address and its access immediately, and it cannot be undone —
        the machine has to be enrolled again with a join code.
    </p>
    <div class="form-actions">
        <button type="button" class="btn" autofocus data-action="close-dialog">Keep it</button>
        <button type="submit" form="delete-device-form" class="btn btn-danger">Yes, delete it</button>
    </div>
</dialog>
