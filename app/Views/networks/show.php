<?php
/**
 * @var array<string,mixed> $network
 * @var list<array<string,mixed>> $devices
 * @var array{total:int,used:int,reserved:int,free:int} $pool
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');

$tabs = [
    'members' => 'Members',
    'ips'     => 'IP management',
    'routes'  => 'Routes',
    'acl'     => 'Access rules',
    'dns'     => 'DNS',
];
$tab = array_key_exists($active_tab, $tabs) ? $active_tab : 'members';
$base = url('networks/' . $network['id']);
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2><?= e($network['name']) ?></h2>
            <p class="text-muted">
                <code><?= e($network['cidr']) ?></code> ·
                <?= e($capacity) ?> ·
                uid <code><?= e($network['network_uid']) ?></code>
            </p>
        </div>
        <div class="card-header-actions">
            <?php if (can('network.update')): ?>
                <a class="btn btn-sm" href="<?= e($base . '/edit') ?>">Edit</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="pool-summary">
        <div><strong><?= e(number_format($pool['used'])) ?></strong><span class="text-muted">in use</span></div>
        <div><strong><?= e(number_format($pool['reserved'])) ?></strong><span class="text-muted">reserved</span></div>
        <div><strong><?= e(number_format($pool['free'])) ?></strong><span class="text-muted">free</span></div>
    </div>
</section>

<?php if (can('device.approve')): ?>
<section class="card">
    <header class="card-header">
        <h2>Add a device</h2>
        <p class="text-muted">Sign up → create network → install → approve → connected.</p>
    </header>

    <?php if ($join_code === null): ?>
        <div class="p-4">
            <p class="text-muted">No active join code. Issue one to start adding devices.</p>
            <form method="post" action="<?= e($base . '/join-code') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Issue a join code</button>
            </form>
        </div>
    <?php else: ?>
        <div class="join-panel">
            <div class="join-code-box">
                <span class="text-muted text-sm">Join code</span>
                <code class="join-code" id="join-code"><?= e($join_code['code']) ?></code>
                <button type="button" class="btn btn-sm" data-action="copy" data-copy-target="join-code">Copy</button>
                <p class="text-muted text-sm">
                    Expires <?= e(local_time($join_code['expires_at'])) ?>
                    <?= (int) $join_code['max_uses'] > 0
                        ? '· ' . e((int) $join_code['max_uses'] - (int) $join_code['uses']) . ' use(s) left'
                        : '· unlimited uses until it expires' ?>
                </p>
                <?php if ((int) ($join_code['pre_approved'] ?? 0) === 1): ?>
                    <p class="text-sm">
                        <strong>Pre-approved.</strong> A device using this code joins immediately,
                        with no approval click. That is your decision, recorded in the audit log —
                        revoke the code below if you did not mean it.
                    </p>
                <?php endif; ?>
            </div>

            <div class="install-commands">
                <div class="install-block">
                    <span class="text-muted text-sm">Windows</span>
                    <p class="text-sm">
                        Send the customer <code>akconnect-setup.exe</code> and the code above.
                        They double-click it, type the code, and click OK — nothing else.
                        No PowerShell, no restart.
                    </p>
                </div>
                <div class="install-block">
                    <span class="text-muted text-sm">Linux / macOS</span>
                    <div class="code-row">
                        <code id="cmd-unix"><?= e($install_unix) ?></code>
                        <button type="button" class="btn btn-sm" data-action="copy" data-copy-target="cmd-unix">Copy</button>
                    </div>
                </div>
            </div>

            <div class="join-actions">
                <form method="post" action="<?= e($base . '/join-code') ?>" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">New code</button>
                </form>
                <form method="post" action="<?= e($base . '/join-code') ?>" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="pre_approved" value="1">
                    <input type="hidden" name="max_uses" value="1">
                    <input type="hidden" name="ttl_minutes" value="30">
                    <button type="submit" class="btn btn-sm">
                        New pre-approved code (1 device, 30 min)
                    </button>
                </form>
                <form method="post" action="<?= e($base . '/join-code/revoke') ?>" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-ghost">Revoke all codes</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <nav class="tabs" role="tablist">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="tab<?= $tab === $key ? ' is-active' : '' ?>"
               href="<?= e($base . '?tab=' . $key) ?>"
               role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="tab-panel" role="tabpanel">
        <?php if ($tab === 'members'): ?>
            <?php if ($devices === []): ?>
                <?= \App\Core\View::partial('partials.empty', [
                    'icon' => '▢', 'title' => 'No devices yet',
                    'message' => 'Run the install command above on a machine to add the first one.',
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
                            <th scope="col">Last seen</th>
                            <th scope="col">Traffic</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($devices as $device): ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('devices/' . $device['id'])) ?>"><?= e($device['name']) ?></a>
                                    <?php if ((int) $device['is_gateway'] === 1): ?>
                                        <span class="chip">gateway</span>
                                    <?php endif; ?>
                                    <div class="text-muted text-sm"><?= e($device['hostname']) ?></div>
                                </td>
                                <td><?= e($device['os']) ?></td>
                                <td class="text-muted"><?= e($device['agent_version'] ?: '—') ?></td>
                                <td><code><?= e($device['virtual_ip'] ?: '—') ?></code></td>
                                <td>
                                    <?= \App\Core\View::partial('partials.connection', ['device' => $device]) ?>
                                </td>
                                <td class="text-muted"><?= e(time_ago($device['last_seen_at'])) ?></td>
                                <td class="text-muted text-sm">
                                    ↓ <?= e(format_bytes((int) $device['rx_bytes'])) ?>
                                    ↑ <?= e(format_bytes((int) $device['tx_bytes'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'ips'): ?>
            <?php if (can('network.update')): ?>
                <form method="post" action="<?= e($base . '/ips/reserve') ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="text" name="ip" placeholder="10.50.0.10" required aria-label="IP address to reserve">
                    <input type="text" name="label" placeholder="Reason (e.g. NVR)" aria-label="Label">
                    <button type="submit" class="btn btn-sm">Reserve</button>
                </form>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col">IP</th>
                        <th scope="col">Assigned to</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="col-actions"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ip_map['rows'] as $row): ?>
                        <tr>
                            <td><code><?= e($row['ip']) ?></code></td>
                            <td>
                                <?php if ($row['device_id'] !== null): ?>
                                    <a href="<?= e(url('devices/' . $row['device_id'])) ?>"><?= e($row['device_name']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted"><?= e($row['label'] ?: 'reserved') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $row['reserved'] === 1): ?>
                                    <span class="chip chip-warning">reserved</span>
                                <?php else: ?>
                                    <span class="chip chip-online">assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-actions">
                                <?php if ((int) $row['reserved'] === 1 && can('network.update')): ?>
                                    <form method="post" action="<?= e($base . '/ips/release') ?>" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="ip" value="<?= e($row['ip']) ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost">Release</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($tab === 'routes'): ?>
            <p class="text-muted p-4">
                Routes let a gateway device advertise a LAN subnet into this network — a
                subnet-router. <strong>0.0.0.0/0 is rejected:</strong> the tunnel never carries a
                default route, so normal internet traffic always leaves via the customer's own ISP.
            </p>
            <p class="text-muted px-4 pb-4">
                <strong>Two addresses per LAN, on purpose.</strong> Almost every router hands out
                192.168.1.0/24, so if the overlay carried the customer's real range your own laptop
                could not tell theirs from its own. Each site gets a range of its own here instead —
                an NVR at <code>192.168.1.50</code> on the site is reached at the overlay address
                shown beside it. Write access rules about the real address, the one on the label on
                the device; the gateway translates.
            </p>

            <form method="post" action="<?= e(url('networks/' . $network['id'] . '/routes')) ?>" class="px-4 pb-4 form-inline">
                <?= csrf_field() ?>
                <label for="destination_cidr">Advertise a LAN</label>
                <input type="text" id="destination_cidr" name="destination_cidr" placeholder="192.168.1.0/24" required>
                <label for="via_device_id">through</label>
                <select id="via_device_id" name="via_device_id" required>
                    <option value="">choose a device at that site…</option>
                    <?php foreach ($gateways as $gateway): ?>
                        <option value="<?= e($gateway['id']) ?>"><?= e($gateway['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Advertise</button>
            </form>

            <?php if ($routes === []): ?>
                <?= \App\Core\View::partial('partials.empty', [
                    'icon' => '↳', 'title' => 'No routes', 'message' => 'Advertise a LAN above to reach machines that cannot run the agent.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col">On the site</th>
                            <th scope="col">On the overlay</th>
                            <th scope="col">Via</th>
                            <th scope="col">Metric</th>
                            <th scope="col">State</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($routes as $route): ?>
                            <tr>
                                <td>
                                    <code><?= e($route['destination_cidr']) ?></code>
                                    <span class="text-muted d-block small">the customer&rsquo;s own range</span>
                                </td>
                                <td>
                                    <?php if (!empty($route['mapped_cidr'])): ?>
                                        <code><?= e($route['mapped_cidr']) ?></code>
                                        <span class="text-muted d-block small">what you connect to</span>
                                    <?php else: ?>
                                        <code class="text-muted"><?= e($route['destination_cidr']) ?></code>
                                        <span class="text-muted d-block small">unmapped &mdash; re-advertise to fix</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($route['via_device_name'] ?? '—') ?> <code class="text-muted"><?= e($route['via_device_ip'] ?? '') ?></code></td>
                                <td><?= e($route['metric']) ?></td>
                                <td>
                                    <?php if ((int) $route['approved'] !== 1): ?>
                                        <span class="chip chip-warning">awaiting approval</span>
                                        <form method="post" action="<?= e(url('networks/' . $network['id'] . '/routes/' . $route['id'] . '/approve')) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                                        </form>
                                    <?php elseif ((int) $route['enabled'] === 1): ?>
                                        <span class="chip chip-online">active</span>
                                    <?php else: ?>
                                        <span class="chip">disabled</span>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url('networks/' . $network['id'] . '/routes/' . $route['id'] . '/withdraw')) ?>" class="inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-danger">Withdraw</button>
                                    </form>
                                </td>
                            </tr>
                            <tr class="row-detail">
                                <td colspan="5">
                                    <p class="text-muted small mb-2">
                                        Machines at this site. Give each one the address printed on it — the
                                        overlay address and the name are worked out from that.
                                    </p>
                                    <?php foreach ($hosts[(int) $route['id']] ?? [] as $host): ?>
                                        <?php
                                            $mapped = \App\Services\SubnetMapper::mapAddress(
                                                (string) $host['address'],
                                                (string) $route['destination_cidr'],
                                                (string) ($route['mapped_cidr'] ?: $route['destination_cidr'])
                                            );
                                            $site = \App\Services\DnsZone::slug((string) ($route['via_device_name'] ?? ''));
                                        ?>
                                        <div class="host-row">
                                            <code><?= e($host['address']) ?></code>
                                            <span class="text-muted">→</span>
                                            <code><?= e($mapped === null ? '—' : explode('/', $mapped)[0]) ?></code>
                                            <?php if ($site !== ''): ?>
                                                <code class="chip"><?= e(\App\Services\DnsZone::slug((string) $host['label']) . '.' . $site . '.' . $zone) ?></code>
                                            <?php endif; ?>
                                            <form method="post" action="<?= e(url('networks/' . $network['id'] . '/hosts/' . $host['id'] . '/delete')) ?>" class="inline">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm">Remove</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                    <form method="post" action="<?= e(url('networks/' . $network['id'] . '/routes/' . $route['id'] . '/hosts')) ?>" class="form-inline">
                                        <?= csrf_field() ?>
                                        <input type="text" name="label" placeholder="nvr" maxlength="63" required>
                                        <input type="text" name="address" placeholder="192.168.1.50" required>
                                        <button type="submit" class="btn btn-sm">Name it</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'acl'): ?>
            <?= \App\Core\View::partial('networks.acl_tab', [
                'network' => $network, 'acl' => $acl, 'devices' => $devices, 'base' => $base,
            ]) ?>

        <?php else: ?>
            <dl class="detail-list p-4">
                <div>
                    <dt>DNS servers</dt>
                    <dd>
                        <?php foreach ((array) ($network['dns_json'] ?? []) as $server): ?>
                            <code><?= e($server) ?></code>
                        <?php endforeach; ?>
                    </dd>
                </div>
                <div>
                    <dt>Search domain</dt>
                    <dd><?= e($network['search_domain'] ?: '—') ?></dd>
                </div>
                <div>
                    <dt>Split DNS</dt>
                    <dd>
                        Enabled. Only names under the search domain resolve through the tunnel;
                        every other lookup uses the device's normal resolver.
                    </dd>
                </div>
            </dl>
        <?php endif; ?>
    </div>
</section>
