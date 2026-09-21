<?php
/** @var array<string,mixed>|null $network */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');

$isEdit = $network !== null;
$action = $isEdit ? url('networks/' . $network['id']) : url('networks');
$dns = $isEdit && is_array($network['dns_json'] ?? null) ? implode(', ', $network['dns_json']) : '1.1.1.1, 8.8.8.8';
?>
<section class="card">
    <header class="card-header">
        <h2><?= $isEdit ? 'Edit network' : 'New network' ?></h2>
    </header>

    <form method="post" action="<?= e($action) ?>" class="form">
        <?= csrf_field() ?>

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" required maxlength="120"
                   value="<?= e(old('name', (string) ($network['name'] ?? ''))) ?>"
                   placeholder="Head office">
            <?php if (field_error('name') !== ''): ?><p class="field-error"><?= e(field_error('name')) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="description">Description</label>
            <input type="text" id="description" name="description" maxlength="255"
                   value="<?= e(old('description', (string) ($network['description'] ?? ''))) ?>">
        </div>

        <div class="field">
            <label for="cidr">Address range (CIDR)</label>
            <input type="text" id="cidr" name="cidr" required
                   value="<?= e(old('cidr', (string) ($network['cidr'] ?? config('network.default_cidr', '10.50.0.0/16')))) ?>"
                   placeholder="10.50.0.0/16">
            <p class="field-hint">
                Must be private space (10/8, 172.16/12 or 192.168/16), between /16 and /30.
                Pick a range that does not clash with any office LAN you will connect.
                <?= $isEdit ? 'A range can be widened later, never narrowed.' : '' ?>
            </p>
            <?php if (field_error('cidr') !== ''): ?><p class="field-error"><?= e(field_error('cidr')) ?></p><?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="mtu">MTU</label>
                <input type="number" id="mtu" name="mtu" min="576" max="1500"
                       value="<?= e(old('mtu', (string) ($network['mtu'] ?? config('network.default_mtu', 1280)))) ?>">
                <p class="field-hint">1280 is safe over almost any path. Raise it only if you know the path supports it.</p>
            </div>
            <div class="field">
                <label for="keepalive_seconds">Keepalive (seconds)</label>
                <input type="number" id="keepalive_seconds" name="keepalive_seconds" min="0" max="180"
                       value="<?= e(old('keepalive_seconds', (string) ($network['keepalive_seconds'] ?? 25))) ?>">
                <p class="field-hint">Holds NAT mappings open. 25s suits most home and office routers.</p>
            </div>
        </div>

        <div class="field">
            <label for="dns">DNS servers</label>
            <input type="text" id="dns" name="dns" value="<?= e(old('dns', $dns)) ?>" placeholder="1.1.1.1, 8.8.8.8">
            <p class="field-hint">
                Used only for the network's search domain — split DNS. Your normal internet name
                resolution is never routed through the tunnel.
            </p>
        </div>

        <div class="field">
            <label for="search_domain">Search domain</label>
            <input type="text" id="search_domain" name="search_domain" maxlength="190"
                   value="<?= e(old('search_domain', (string) ($network['search_domain'] ?? ''))) ?>"
                   placeholder="office.internal">
            <p class="field-hint">
                Devices answer to <code>&lt;name&gt;.&lt;this domain&gt;</code>. It must end in
                <code>.internal</code>, which is reserved for private use &mdash; so a name here can
                never collide with a real one, and this network's agents can never be made
                authoritative for a domain somebody else owns. Leave it blank and one is made from
                the network's name.
            </p>
            <?php if (field_error('search_domain') !== ''): ?><p class="field-error"><?= e(field_error('search_domain')) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="mapped_pool">Virtual prefix pool</label>
            <input type="text" id="mapped_pool" name="mapped_pool" maxlength="20"
                   value="<?= e(old('mapped_pool', (string) ($network['mapped_pool'] ?? ''))) ?>"
                   placeholder="<?= e(\App\Services\SubnetMapper::DEFAULT_POOL) ?>">
            <p class="field-hint">
                Where the addresses come from that this network uses for advertised LANs &mdash; a
                hotel's <code>192.168.1.0/24</code> becomes a range out of this pool. Leave it blank
                to use <code><?= e(\App\Services\SubnetMapper::DEFAULT_POOL) ?></code>.
                <strong>Change it if a site already uses that range internally</strong>, which many
                offices and most ISP-managed connections do: an agent on such a machine refuses the
                clashing route rather than taking over a network the machine is already on, and says
                so on the device's page. Existing routes keep the prefixes they were given.
            </p>
            <?php if (field_error('mapped_pool') !== ''): ?><p class="field-error"><?= e(field_error('mapped_pool')) ?></p><?php endif; ?>
        </div>

        <fieldset class="field">
            <legend>Behaviour</legend>

            <label class="checkbox">
                <input type="checkbox" name="auto_assign_ip" value="1"
                    <?= ($network['auto_assign_ip'] ?? 1) ? 'checked' : '' ?>>
                <span>Assign a private IP automatically when a device is approved</span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="auto_approve_devices" value="1"
                    <?= !empty($network['auto_approve_devices']) ? 'checked' : '' ?>>
                <span>
                    Approve new devices automatically
                    <em class="text-muted">— off by default. With this on, anyone holding a valid join code
                    joins without review.</em>
                </span>
            </label>
        </fieldset>

        <div class="field">
            <label for="acl_default_action">Default access policy</label>
            <select id="acl_default_action" name="acl_default_action">
                <option value="allow" <?= ($network['acl_default_action'] ?? 'allow') === 'allow' ? 'selected' : '' ?>>
                    Allow — devices can reach each other unless a rule denies it
                </option>
                <option value="deny" <?= ($network['acl_default_action'] ?? '') === 'deny' ? 'selected' : '' ?>>
                    Deny — nothing is reachable unless a rule allows it
                </option>
            </select>
        </div>

        <?php if ($isEdit): ?>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($network['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create network' ?></button>
            <a class="btn btn-ghost" href="<?= e(url($isEdit ? 'networks/' . $network['id'] : 'networks')) ?>">Cancel</a>
        </div>
    </form>
</section>
