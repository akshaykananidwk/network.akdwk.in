<?php declare(strict_types=1); ?>
<div class="card">
    <h2>Administrator account</h2>
    <form method="post" action="?step=admin">
        <input type="hidden" name="_token" value="<?= h($csrf) ?>">
        <div class="card-body">
            <?php if (!empty($answers['_tables'])): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">✓</span>
                    <span><?= h($answers['_tables']) ?> tables created successfully.</span>
                </div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><span class="alert-icon">✕</span><span><?= h($error) ?></span></div>
            <?php endforeach; ?>

            <p class="hint">This is the platform super administrator — the account that manages
               customers, relays and updates.</p>

            <div class="field">
                <label for="admin_name">Your name</label>
                <input type="text" id="admin_name" name="admin_name" value="<?= h($answers['admin_name'] ?? '') ?>" required>
            </div>

            <div class="field">
                <label for="admin_email">Email address</label>
                <input type="email" id="admin_email" name="admin_email" value="<?= h($answers['admin_email'] ?? '') ?>" required>
                <p class="hint">This is your sign-in name, and where platform alerts are sent.</p>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="admin_password">Password</label>
                    <input type="password" id="admin_password" name="admin_password" required autocomplete="new-password">
                    <p class="hint">At least 10 characters, mixing at least three of: lower case,
                       upper case, numbers, symbols.</p>
                </div>
                <div class="field">
                    <label for="admin_password_confirmation">Confirm password</label>
                    <input type="password" id="admin_password_confirmation" name="admin_password_confirmation"
                           required autocomplete="new-password">
                </div>
            </div>

            <fieldset>
                <legend>Two-factor authentication</legend>
                <label class="checkbox">
                    <input type="checkbox" name="enable_2fa" value="1"
                        <?= !empty($answers['enable_2fa']) ? 'checked' : '' ?>
                        <?= extension_loaded('openssl') ? '' : 'disabled' ?>>
                    <span>
                        Set up two-factor authentication now
                        <span class="hint" style="display:block">
                            Recommended, and required for platform administrators by default.
                            You will scan a QR code on the last screen. If you skip it here you
                            will be prompted at first sign-in.
                        </span>
                    </span>
                </label>
            </fieldset>
        </div>

        <div class="card-foot">
            <a class="btn" href="?step=database">← Back</a>
            <button type="submit" class="btn btn-primary">Continue →</button>
        </div>
    </form>
</div>
