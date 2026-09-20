<?php
/** @var bool $enabled */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');

$recoveryCodes = \App\Core\Session::flash('recovery_codes');
?>
<?php if (is_array($recoveryCodes) && $recoveryCodes !== []): ?>
    <section class="card card-highlight">
        <header class="card-header">
            <h2>Save your recovery codes</h2>
            <p class="text-muted">
                Shown once. Each works a single time if you lose your authenticator.
                Store them somewhere other than the device running the app.
            </p>
        </header>
        <div class="recovery-grid" id="recovery-codes">
            <?php foreach ($recoveryCodes as $code): ?>
                <code><?= e($code) ?></code>
            <?php endforeach; ?>
        </div>
        <div class="card-footer">
            <button type="button" class="btn btn-sm" data-action="copy" data-copy-target="recovery-codes">Copy all</button>
            <button type="button" class="btn btn-sm btn-ghost" data-action="print">Print</button>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <header class="card-header">
        <h2>Two-factor authentication</h2>
        <span class="status status-<?= $enabled ? 'active' : 'down' ?>"><?= $enabled ? 'enabled' : 'disabled' ?></span>
    </header>

    <?php if ($enabled): ?>
        <div class="p-4">
            <p class="text-muted">
                A code from your authenticator app is required at every sign-in.
                You have <strong><?= e($remaining_codes) ?></strong> recovery code(s) left.
            </p>

            <form method="post" action="<?= e(url('account/2fa/recovery')) ?>" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn">Generate new recovery codes</button>
            </form>

            <?php if (!$is_super_admin): ?>
                <form method="post" action="<?= e(url('account/2fa/disable')) ?>" class="inline-form mt-4">
                    <?= csrf_field() ?>
                    <input type="password" name="password" placeholder="Your password" required
                           autocomplete="current-password" aria-label="Your password">
                    <button type="submit" class="btn btn-danger">Turn off</button>
                </form>
            <?php else: ?>
                <p class="text-muted mt-4">
                    Two-factor authentication cannot be turned off for platform administrators.
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="twofa-setup">
            <ol class="setup-steps">
                <li>
                    <strong>Scan this in your authenticator app</strong>
                    <p class="text-muted">Google Authenticator, Authy, 1Password, Bitwarden — any TOTP app works.</p>
                    <div class="qr-holder" id="totp-qr" data-otpauth="<?= e_attr($uri) ?>"
                         aria-label="QR code for two-factor setup"></div>
                </li>
                <li>
                    <strong>Or enter this key by hand</strong>
                    <div class="code-row">
                        <code id="totp-secret"><?= e(chunk_split($secret, 4, ' ')) ?></code>
                        <button type="button" class="btn btn-sm" data-action="copy" data-copy-target="totp-secret">Copy</button>
                    </div>
                </li>
                <li>
                    <strong>Enter the code it shows</strong>
                    <form method="post" action="<?= e(url('account/2fa/enable')) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               required placeholder="000000" class="input-code" aria-label="Six-digit code">
                        <button type="submit" class="btn btn-primary">Turn on</button>
                    </form>
                </li>
            </ol>
        </div>
    <?php endif; ?>
</section>
