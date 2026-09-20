<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <h2>New customer</h2>
        <p class="text-muted">Creates the company and its first administrator in one step.</p>
    </header>

    <form method="post" action="<?= e(url('admin/tenants')) ?>" class="form" data-password-meter>
        <?= csrf_field() ?>

        <h3>Company</h3>
        <div class="grid-2">
            <div class="field">
                <label for="company_name">Company name</label>
                <input type="text" id="company_name" name="company_name" required maxlength="160"
                       value="<?= e(old('company_name')) ?>">
            </div>
            <div class="field">
                <label for="email">Billing email</label>
                <input type="email" id="email" name="email" required value="<?= e(old('email')) ?>">
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="contact_person">Contact person</label>
                <input type="text" id="contact_person" name="contact_person" maxlength="120" value="<?= e(old('contact_person')) ?>">
            </div>
            <div class="field">
                <label for="mobile">Mobile</label>
                <input type="tel" id="mobile" name="mobile" maxlength="32" value="<?= e(old('mobile')) ?>">
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="plan_id">Plan</label>
                <select id="plan_id" name="plan_id" required>
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?= e($plan['id']) ?>">
                            <?= e($plan['name']) ?> — <?= e($plan['device_limit']) ?> devices,
                            <?= e($plan['network_limit']) ?> networks
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?>
                        <option value="<?= e($zone) ?>" <?= $zone === 'Asia/Kolkata' ? 'selected' : '' ?>><?= e($zone) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h3>First administrator</h3>
        <div class="grid-2">
            <div class="field">
                <label for="admin_name">Name</label>
                <input type="text" id="admin_name" name="admin_name" required maxlength="120" value="<?= e(old('admin_name')) ?>">
            </div>
            <div class="field">
                <label for="admin_email">Email address</label>
                <input type="email" id="admin_email" name="admin_email" required value="<?= e(old('admin_email')) ?>">
            </div>
        </div>

        <div class="field">
            <label for="admin_password">Temporary password</label>
            <input type="password" id="admin_password" name="admin_password" required
                   autocomplete="new-password" data-password-input>
            <div class="strength-meter" data-strength-meter hidden>
                <div class="strength-bar"><span data-strength-fill></span></div>
                <p class="strength-label" data-strength-label></p>
            </div>
            <p class="field-hint">Send this to the customer over a channel other than email if you can.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create customer</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/tenants')) ?>">Cancel</a>
        </div>
    </form>
</section>
