<?php
/** @var array<string,mixed> $settings */
/** @var bool $has_shared_secret */
/** @var bool $has_signing_key */
/** @var list<string> $problems */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <h2>Coordinator</h2>
    </header>

    <div class="card-body">
        <p class="text-muted">
            The coordinator is how two devices behind NAT learn each other's public address.
            The panel hands these values to every agent, so an address that is wrong here is a
            network where peers only connect when they could have found each other anyway.
        </p>

        <?php if ($problems !== []): ?>
            <div class="alert alert-warning">
                <strong>This configuration will not work yet:</strong>
                <ul>
                    <?php foreach ($problems as $problem): ?>
                        <li><?= e($problem) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                Address, port, public key and shared secret are all set.
            </div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= e(url('admin/coordinator')) ?>" class="form">
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field">
                <label for="host">Address</label>
                <input type="text" id="host" name="host" required maxlength="253"
                       value="<?= e(old('host', (string) $settings['host'])) ?>"
                       placeholder="coordinator.example.com">
                <p class="field-hint">
                    Where this panel reaches the coordinator. <code>127.0.0.1</code> is only right
                    when it runs on this same server.
                </p>
                <?php if (field_error('host') !== ''): ?><p class="field-error"><?= e(field_error('host')) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="port">Port</label>
                <input type="number" id="port" name="port" min="1" max="65535" required
                       value="<?= e(old('port', (string) $settings['port'])) ?>">
                <p class="field-hint">UDP, 8443 unless you changed <code>--listen</code>.</p>
                <?php if (field_error('port') !== ''): ?><p class="field-error"><?= e(field_error('port')) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label for="public_host">Address for agents (optional)</label>
            <input type="text" id="public_host" name="public_host" maxlength="253"
                   value="<?= e(old('public_host', (string) $settings['public_host'])) ?>"
                   placeholder="Leave blank to use the address above">
            <p class="field-hint">
                Set this only when agents reach the coordinator at a different name from the one
                this panel uses — a split-horizon DNS or a private link.
            </p>
            <?php if (field_error('public_host') !== ''): ?><p class="field-error"><?= e(field_error('public_host')) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="public_key">Public key</label>
            <input type="text" id="public_key" name="public_key" required maxlength="64"
                   value="<?= e(old('public_key', (string) $settings['public_key'])) ?>"
                   placeholder="44 characters of base64, ending in =">
            <p class="field-hint">
                Printed by <code>akconnect-coordinator keygen</code> and by
                <code>deploy/install-edge.sh</code>. Agents seal their announcements to it; the
                private half never leaves the coordinator.
            </p>
            <?php if (field_error('public_key') !== ''): ?><p class="field-error"><?= e(field_error('public_key')) ?></p><?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="shared_secret">Shared secret</label>
                <input type="password" id="shared_secret" name="shared_secret" autocomplete="new-password"
                       placeholder="<?= $has_shared_secret ? 'Set — leave blank to keep it' : 'Not set' ?>">
                <p class="field-hint">
                    What the coordinator sends this panel as <code>AKCONNECT_COORDINATOR_SECRET</code>.
                    Stored encrypted and never shown again.
                </p>
                <?php if (field_error('shared_secret') !== ''): ?><p class="field-error"><?= e(field_error('shared_secret')) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="signing_key">Agent token signing key</label>
                <input type="password" id="signing_key" name="signing_key" autocomplete="new-password"
                       placeholder="<?= $has_signing_key ? 'Set — leave blank to keep it' : 'Not set' ?>">
                <p class="field-hint">
                    Signs the short-lived tickets agents present to the coordinator. Leave blank
                    unless you are rotating it on both sides at once.
                </p>
                <?php if (field_error('signing_key') !== ''): ?><p class="field-error"><?= e(field_error('signing_key')) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</section>
