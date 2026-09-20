<?php
/** @var string $token @var string $email */
declare(strict_types=1);
\App\Core\View::layout('layouts.auth');
?>
<form method="post" action="<?= e(url('reset-password')) ?>" class="form" novalidate data-password-meter>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <p class="text-muted form-lead">Choose a new password for <strong><?= e($email) ?></strong>.</p>

    <div class="field">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required autofocus
               data-password-input>
        <div class="strength-meter" data-strength-meter hidden>
            <div class="strength-bar"><span data-strength-fill></span></div>
            <p class="strength-label" data-strength-label></p>
        </div>
        <?php if (field_error('password') !== ''): ?>
            <p class="field-error"><?= e(field_error('password')) ?></p>
        <?php endif; ?>
    </div>

    <div class="field">
        <label for="password_confirmation">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation"
               autocomplete="new-password" required>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Change password</button>
</form>
