<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.auth');
?>
<form method="post" action="<?= e(url('forgot-password')) ?>" class="form" novalidate>
    <?= csrf_field() ?>

    <p class="text-muted form-lead">
        Enter your email address and we will send you a link to choose a new password.
        The link is valid for 60 minutes and can be used once.
    </p>

    <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="username" required autofocus>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
    <a href="<?= e(url('login')) ?>" class="btn btn-ghost btn-block">Back to sign in</a>
</form>
