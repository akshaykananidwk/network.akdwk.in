<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<div class="grid-2">
    <section class="card">
        <header class="card-header"><h2>Profile</h2></header>

        <form method="post" action="<?= e(url('account')) ?>" class="form">
            <?= csrf_field() ?>

            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required maxlength="120"
                       value="<?= e($auth_user['name'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="email_display">Email address</label>
                <input type="email" id="email_display" value="<?= e($auth_user['email'] ?? '') ?>" disabled>
                <p class="field-hint">Ask an administrator to change your sign-in address.</p>
            </div>

            <div class="field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone">
                    <?php $current = (string) ($auth_user['timezone'] ?? $timezone); ?>
                    <?php foreach ($timezones as $zone): ?>
                        <option value="<?= e($zone) ?>" <?= $zone === $current ? 'selected' : '' ?>><?= e($zone) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">Everything is stored in UTC and displayed in your timezone.</p>
            </div>

            <div class="field">
                <label for="locale">Language</label>
                <select id="locale" name="locale">
                    <?php foreach ($locales as $locale): ?>
                        <option value="<?= e($locale) ?>" <?= ($auth_user['locale'] ?? 'en') === $locale ? 'selected' : '' ?>>
                            <?= e(strtoupper($locale)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </section>

    <section class="card">
        <header class="card-header"><h2>Change password</h2></header>

        <form method="post" action="<?= e(url('account/password')) ?>" class="form" data-password-meter>
            <?= csrf_field() ?>

            <div class="field">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input type="password" id="password" name="password" required autocomplete="new-password" data-password-input>
                <div class="strength-meter" data-strength-meter hidden>
                    <div class="strength-bar"><span data-strength-fill></span></div>
                    <p class="strength-label" data-strength-label></p>
                </div>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Change password</button>
            </div>
        </form>
    </section>
</div>

<section class="card">
    <header class="card-header">
        <h2>Two-factor authentication</h2>
    </header>
    <div class="p-4">
        <p class="text-muted">
            <?= (int) ($auth_user['twofa_enabled'] ?? 0) === 1
                ? 'Two-factor authentication is on for this account.'
                : 'Two-factor authentication is off. It is strongly recommended, and mandatory for platform administrators.' ?>
        </p>
        <a class="btn" href="<?= e(url('account/2fa')) ?>">
            <?= (int) ($auth_user['twofa_enabled'] ?? 0) === 1 ? 'Manage' : 'Set up' ?> two-factor authentication
        </a>
    </div>
</section>
