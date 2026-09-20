<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.auth');
?>
<form method="post" action="<?= e(url('login/2fa')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <p class="text-muted form-lead">
        Enter the six-digit code from your authenticator app. If you have lost your device,
        enter one of your recovery codes instead.
    </p>

    <div class="field">
        <label for="code">Authentication code</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9A-Za-z\-]{6,12}" maxlength="12" required autofocus
               class="input-code" placeholder="000000">
        <?php if (field_error('code') !== ''): ?>
            <p class="field-error"><?= e(field_error('code')) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Verify</button>
</form>

<form method="post" action="<?= e(url('logout')) ?>" class="mt-3">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-ghost btn-block">Use a different account</button>
</form>
