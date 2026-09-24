<?php
/** @var array<string,mixed> $settings */
/** @var bool $has_shared_secret */
/** @var string $fallback_default */
/** @var bool $has_signing_key */
/** @var list<string> $problems */
/** @var string $release_key fingerprint of the trusted edge release key, or '' */
/** @var array<string,array<string,mixed>> $held uploads waiting for an administrator */
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

    <div class="card-body">
        <h3>Edge servers</h3>
        <?php if ($edge['unknown']): ?>
            <p class="text-muted">
                No coordinator has reported in yet, so this panel does not know what the edge is
                running. It learns that from the coordinator's own calls, so this fills in as soon
                as one connects.
            </p>
        <?php else: ?>
            <table class="table table-compact">
                <tbody>
                    <tr>
                        <th>This panel</th>
                        <td><?= e($edge['panel_version']) ?></td>
                    </tr>
                    <tr>
                        <th>Coordinator</th>
                        <td>
                            <?= e($edge['coordinator_version'] ?: 'not reported') ?>
                            <?php if (isset($edge['behind']['coordinator'])): ?>
                                <span class="badge badge-warning">behind</span>
                                <?php /* The command is beside the badge, not only further down the
                                         page: the operator who reported this read the badge,
                                         scrolled past the explanation, and went to the edge server
                                         to work it out by hand. */ ?>
                                <code class="inline-command"
                                      title="Run this on the edge server, as root"><?= e((string) $edge['command']) ?></code>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Relay</th>
                        <td>
                            <?= e($edge['relay_version'] ?: 'not reported') ?>
                            <?php if (isset($edge['behind']['relay'])): ?>
                                <span class="badge badge-warning">behind</span>
                                <?php /* The command is beside the badge, not only further down the
                                         page: the operator who reported this read the badge,
                                         scrolled past the explanation, and went to the edge server
                                         to work it out by hand. */ ?>
                                <code class="inline-command"
                                      title="Run this on the edge server, as root"><?= e((string) $edge['command']) ?></code>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($edge['reported_at'] !== null): ?>
                        <tr>
                            <th>Last heard</th>
                            <td><?= e(local_time((string) $edge['reported_at'])) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($edge['behind'] !== []): ?>
            <div class="alert alert-warning">
                <strong>The edge is behind this panel.</strong>
                <p>
                    Update Now updates this panel only. The coordinator and the relay are separate
                    services on another machine, and this panel deliberately has no way to reach
                    into it — it holds the coordinator's private key, and a panel that could
                    restart services there could also be used to take them over.
                </p>
                <p>Run this on the edge server, once:</p>
                <pre><code><?= e((string) $edge['command']) ?></code></pre>
                <p class="text-muted text-sm">
                    Add <code>--install-timer</code> and it will keep itself in step with this
                    panel from then on.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <h3>What the edge publishes</h3>
        <p class="text-muted">
            The Windows installer and the agent update this panel hands out are published only when
            the edge server's own release key signed them. The shared secret alone is not enough:
            anything else waits here for you, and no device or customer is offered it.
        </p>
        <table class="table table-compact">
            <tbody>
                <tr>
                    <th>Trusted edge key</th>
                    <td>
                        <?php if ($release_key !== ''): ?>
                            <code><?= e($release_key) ?></code>
                        <?php else: ?>
                            none yet — the edge's first upload waits for you here
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php
        $kindLabels = [
            'windows-setup' => 'The Windows installer',
            'windows-agent' => 'The agent update',
            'windows-pack'  => 'The Windows pack',
        ];
        ?>
        <?php foreach ($held as $kind => $upload): ?>
            <div class="alert <?= $upload['other_key'] ? 'alert-error' : 'alert-warning' ?>">
                <strong>
                    <?= e($kindLabels[$kind] ?? $kind) ?> <?= e((string) $upload['version']) ?> is waiting for you.
                </strong>
                <p>
                    Signed by edge key <code><?= e((string) $upload['fingerprint']) ?></code>,
                    received <?= e(local_time((string) $upload['received_at'])) ?>.
                </p>
                <?php if ($upload['other_key']): ?>
                    <p>
                        <strong>That is not the key this panel trusts.</strong> Unless you have
                        replaced or reinstalled the edge server, someone else signed this: discard it,
                        and run <code>sudo akconnect-rotate-secret</code> on the edge server.
                    </p>
                <?php endif; ?>
                <p>Before you approve it, run this on the edge server and check it prints the same fingerprint:</p>
                <pre><code>sudo akconnect-coordinator release-key public /etc/akconnect/release-signing.key</code></pre>
                <div class="form-actions">
                    <form method="post" action="<?= e(url('admin/coordinator/held/approve')) ?>" style="display:inline"
                          onsubmit="return confirm('Trust edge key <?= e((string) $upload['fingerprint']) ?> and publish this?\n\nEvery upload that key signs will then publish by itself.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="<?= e((string) $kind) ?>">
                        <input type="hidden" name="fingerprint" value="<?= e((string) $upload['fingerprint']) ?>">
                        <button type="submit" class="btn btn-primary">The fingerprint matches — trust this key and publish</button>
                    </form>
                    <form method="post" action="<?= e(url('admin/coordinator/held/discard')) ?>" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="<?= e((string) $kind) ?>">
                        <button type="submit" class="btn btn-danger">Discard</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
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
            <label for="fallback_url">HTTPS fallback address</label>
            <input type="text" id="fallback_url" name="fallback_url" maxlength="253"
                   value="<?= e(old('fallback_url', (string) $settings['fallback_url'])) ?>"
                   placeholder="<?= e($fallback_default !== '' ? $fallback_default : 'wss://panel.example.com/fallback') ?>">
            <p class="field-hint">
                Where a device goes when the network it is on carries no UDP at all — a hotel, a
                guest network, an office that lets UDP out and drops the replies. On those,
                nothing else here can be reached, the coordinator included, so the device cannot
                even ask for help. <code>deploy/install-edge.sh</code> configures Apache to serve
                this and fills it in; change it only for a separate edge host.
                It must be <code>wss://</code>: this is the one port every network inspects.
            </p>
            <?php if (field_error('fallback_url') !== ''): ?><p class="field-error"><?= e(field_error('fallback_url')) ?></p><?php endif; ?>
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
