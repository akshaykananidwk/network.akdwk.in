<?php
/**
 * @var array<string,mixed> $network
 * @var list<array<string,mixed>> $acl
 * @var list<array<string,mixed>> $devices
 * @var string $base
 */
declare(strict_types=1);
?>
<div class="acl-intro p-4">
    <p class="text-muted">
        Rules are evaluated in priority order, lowest number first; the first match wins.
        With no matching rule the network default
        (<strong><?= e($network['acl_default_action']) ?></strong>) applies.
    </p>
    <p class="text-muted">
        Rules are compiled and enforced <strong>on both devices</strong>, not just shown here —
        a peer you deny is not reachable, whatever it tries. Changes reach connected devices
        within about ten seconds.
    </p>
</div>

<?php if ($acl === []): ?>
    <?= \App\Core\View::partial('partials.empty', [
        'icon' => '⊘',
        'title' => 'No rules',
        'message' => 'Every device can reach every other device on this network.',
    ]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Source</th>
                <th scope="col">Destination</th>
                <th scope="col">Protocol / ports</th>
                <th scope="col">Action</th>
                <th scope="col">State</th>
                <th scope="col" class="col-actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($acl as $rule): ?>
                <tr class="<?= (int) $rule['enabled'] === 1 ? '' : 'is-muted' ?>">
                    <td><?= e($rule['priority']) ?></td>
                    <td>
                        <span class="chip"><?= e($rule['src_type']) ?></span>
                        <?= e($rule['src_value'] ?? 'any') ?>
                    </td>
                    <td>
                        <span class="chip"><?= e($rule['dst_type']) ?></span>
                        <?= e($rule['dst_value'] ?? 'any') ?>
                    </td>
                    <td>
                        <?= e($rule['protocol']) ?>
                        <?php if ($rule['port_from'] !== null): ?>
                            <code><?= e($rule['port_from']) ?><?= $rule['port_to'] !== null && $rule['port_to'] !== $rule['port_from']
                                ? '–' . e($rule['port_to']) : '' ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status status-<?= $rule['action'] === 'allow' ? 'active' : 'down' ?>">
                            <?= e($rule['action']) ?>
                        </span>
                    </td>
                    <td>
                        <?= (int) $rule['enabled'] === 1
                            ? '<span class="chip chip-online">enabled</span>'
                            : '<span class="chip">disabled</span>' ?>
                    </td>
                    <td class="col-actions">
                        <?php if (can('acl.manage')): ?>
                            <form method="post" action="<?= e($base . '/acl/' . $rule['id'] . '/delete') ?>" class="inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (can('acl.manage')): ?>
    <form method="post" action="<?= e($base . '/acl') ?>" class="form acl-form">
        <?= csrf_field() ?>
        <h3>Add a rule</h3>

        <div class="grid-4">
            <div class="field">
                <label for="src_type">Source</label>
                <select id="src_type" name="src_type">
                    <option value="any">Any device</option>
                    <option value="device">A specific device</option>
                    <option value="tag">Devices with a tag</option>
                    <option value="cidr">An address range</option>
                </select>
            </div>
            <div class="field">
                <label for="src_value">Source value</label>
                <input type="text" id="src_value" name="src_value" placeholder="tag, uid or 10.50.1.0/24">
            </div>
            <div class="field">
                <label for="dst_type">Destination</label>
                <select id="dst_type" name="dst_type">
                    <option value="any">Any device</option>
                    <option value="device">A specific device</option>
                    <option value="tag">Devices with a tag</option>
                    <option value="cidr">An address range</option>
                </select>
            </div>
            <div class="field">
                <label for="dst_value">Destination value</label>
                <input type="text" id="dst_value" name="dst_value" placeholder="tag, uid or 10.50.2.0/24">
            </div>
        </div>

        <div class="grid-4">
            <div class="field">
                <label for="protocol">Protocol</label>
                <select id="protocol" name="protocol">
                    <option value="any">Any</option>
                    <option value="tcp">TCP</option>
                    <option value="udp">UDP</option>
                    <option value="icmp">ICMP (ping)</option>
                </select>
            </div>
            <div class="field">
                <label for="port_from">Port from</label>
                <input type="number" id="port_from" name="port_from" min="1" max="65535" placeholder="any">
            </div>
            <div class="field">
                <label for="port_to">Port to</label>
                <input type="number" id="port_to" name="port_to" min="1" max="65535" placeholder="same">
            </div>
            <div class="field">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="allow">Allow</option>
                    <option value="deny">Deny</option>
                </select>
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" maxlength="190"
                       placeholder="Let the office reach the NVR on 554">
            </div>
            <div class="field">
                <label for="priority">Priority</label>
                <input type="number" id="priority" name="priority" min="1" max="10000" placeholder="auto">
                <p class="field-hint">Lower numbers are evaluated first. Leave blank to append.</p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Add rule</button>
        </div>
    </form>
<?php endif; ?>
