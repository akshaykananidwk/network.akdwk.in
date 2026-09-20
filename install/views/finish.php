<?php
declare(strict_types=1);

$complete = $_SESSION['install_complete'] ?? null;
$done = isset($_GET['done']) && $complete !== null;
?>

<?php if (!$done): ?>
    <div class="card">
        <h2>Ready to install</h2>
        <form method="post" action="?step=finish">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <div class="card-body">
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><span class="alert-icon">✕</span><span><?= h($error) ?></span></div>
                <?php endforeach; ?>

                <p>Finishing will:</p>
                <ul class="steps">
                    <li>Create your administrator account
                        (<code><?= h($answers['admin_email'] ?? '') ?></code>).</li>
                    <li>Generate the application key, backup key, coordinator secret and
                        controller signing keypair.</li>
                    <li>Write <code>config/config.php</code> and <code>.env</code>.</li>
                    <li>Create <code>install/install.lock</code>, which disables this wizard.</li>
                </ul>

                <table class="checks">
                    <tbody>
                    <tr><td>Panel URL</td><td><code><?= h($answers['app_url'] ?? '') ?></code></td></tr>
                    <tr><td>Product name</td><td><?= h($answers['site_name'] ?? '') ?></td></tr>
                    <tr><td>Database</td><td><code><?= h($answers['db_name'] ?? '') ?></code> on
                        <code><?= h($answers['db_host'] ?? '') ?>:<?= h($answers['db_port'] ?? '') ?></code></td></tr>
                    <tr><td>Tables</td><td><?= h($answers['_tables'] ?? 0) ?> created</td></tr>
                    <tr><td>Administrator</td><td><?= h($answers['admin_name'] ?? '') ?>
                        &lt;<?= h($answers['admin_email'] ?? '') ?>&gt;</td></tr>
                    <tr><td>Timezone</td><td><?= h($answers['timezone'] ?? '') ?></td></tr>
                    <tr><td>Email</td><td><?= ($answers['mail_host'] ?? '') !== ''
                        ? h($answers['mail_host']) : '<span class="hint">not configured</span>' ?></td></tr>
                    <tr><td>GitHub updates</td><td><?= ($answers['gh_owner'] ?? '') !== ''
                        ? h($answers['gh_owner'] . '/' . $answers['gh_repo']) : '<span class="hint">not configured</span>' ?></td></tr>
                    <tr><td>Two-factor</td><td><?= !empty($answers['enable_2fa'])
                        ? 'will be set up now' : '<span class="hint">prompted at first sign-in</span>' ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card-foot">
                <a class="btn" href="?step=configuration">← Back</a>
                <button type="submit" class="btn btn-primary">Install now</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <div class="card">
        <h2>Installation complete</h2>
        <div class="card-body">
            <div class="alert alert-success">
                <span class="alert-icon">✓</span>
                <span>The panel is installed and ready. Sign in as
                      <strong><?= h($complete['admin_email']) ?></strong>.</span>
            </div>

            <?php if (!empty($complete['twofa_uri'])): ?>
                <h3 style="font-size:.95rem">1. Set up your authenticator</h3>
                <p class="hint">Scan this with Google Authenticator, Authy, 1Password or any TOTP app.
                   You will need a code from it to sign in.</p>
                <div class="qr" id="install-qr" data-uri="<?= h($complete['twofa_uri']) ?>"></div>
                <p class="hint">Or enter this key by hand:
                   <code><?= h(chunk_split((string) $complete['twofa_secret'], 4, ' ')) ?></code></p>
            <?php endif; ?>

            <?php if (!empty($complete['recovery'])): ?>
                <h3 style="font-size:.95rem">2. Save your recovery codes</h3>
                <div class="alert alert-warning">
                    <span class="alert-icon">!</span>
                    <span><strong>These are shown once.</strong> Each works a single time if you lose
                          your authenticator. Print them or store them in a password manager —
                          somewhere other than the device running the app.</span>
                </div>
                <div class="recovery">
                    <?php foreach ($complete['recovery'] as $code): ?>
                        <code><?= h($code) ?></code>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 style="font-size:.95rem">Set up the scheduler</h3>
            <p class="hint">
                The worker checks for updates, sweeps offline devices, takes scheduled backups and
                prunes old logs. Add this line to your crontab
                (<code>crontab -e</code>), or create a scheduled task in your control panel:
            </p>
            <pre><?= h($complete['crontab']) ?></pre>

            <h3 style="font-size:.95rem">Remove the installer</h3>
            <p class="hint">
                <code>install/install.lock</code> already blocks this wizard, but deleting the
                directory removes the code entirely:
            </p>
            <pre>rm -rf <?= h(APP_ROOT) ?>/install</pre>

            <h3 style="font-size:.95rem">What next</h3>
            <ul class="steps">
                <li>Sign in and create your first customer under <strong>Customers</strong>.</li>
                <li>Register at least one relay under <strong>Relays</strong> so devices behind
                    CGNAT can still connect.</li>
                <li>Check <strong>System → Updates</strong> — with a repository configured, updating
                    is one click with a backup and automatic rollback.</li>
                <?php if (!$complete['signing_ready']): ?>
                    <li class="hint">ext-sodium was not available, so no controller signing keypair was
                        generated. Install it and run <code>php cli/keygen.php</code> before deploying
                        agents, or their cached configuration cannot be authenticated.</li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card-foot">
            <span></span>
            <a class="btn btn-primary" href="<?= h($complete['app_url']) ?>/login">Sign in →</a>
        </div>
    </div>
<?php endif; ?>
