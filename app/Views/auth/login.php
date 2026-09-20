<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.auth');
?>
<form method="post" action="<?= e(url('login')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="username" required autofocus
               <?= field_error('email') !== '' ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
        <?php if (field_error('email') !== ''): ?>
            <p class="field-error" id="email-error"><?= e(field_error('email')) ?></p>
        <?php endif; ?>
    </div>

    <div class="field">
        <div class="field-label-row">
            <label for="password">Password</label>
            <a href="<?= e(url('forgot-password')) ?>" class="field-hint-link">Forgot?</a>
        </div>
        <div class="input-group">
            <input type="password" id="password" name="password" autocomplete="current-password" required
                   <?= field_error('password') !== '' ? 'aria-invalid="true"' : '' ?>>
            <button type="button" class="btn btn-icon" data-action="toggle-password"
                    data-target="password" aria-label="Show password">👁</button>
        </div>
        <?php if (field_error('password') !== ''): ?>
            <p class="field-error"><?= e(field_error('password')) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
</form>
