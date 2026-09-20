<?php
declare(strict_types=1);

// Auto-detect the panel URL so the operator rarely has to type it.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$detected = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('/install', '', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/');
$appUrl = $answers['app_url'] ?? $detected;
$host = (string) parse_url($appUrl, PHP_URL_HOST);
?>
<div class="card">
    <h2>Configuration</h2>
    <form method="post" action="?step=configuration">
        <input type="hidden" name="_token" value="<?= h($csrf) ?>">
        <div class="card-body">
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><span class="alert-icon">✕</span><span><?= h($error) ?></span></div>
            <?php endforeach; ?>

            <fieldset>
                <legend>Branding</legend>

                <div class="grid">
                    <div class="field">
                        <label for="site_name">Product name</label>
                        <input type="text" id="site_name" name="site_name"
                               value="<?= h($answers['site_name'] ?? 'AK Connect') ?>" required>
                        <p class="hint">Shown throughout the panel. Changeable later in settings.</p>
                    </div>
                    <div class="field">
                        <label for="org_name">Company name</label>
                        <input type="text" id="org_name" name="org_name" value="<?= h($answers['org_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="app_url">Panel URL</label>
                    <input type="url" id="app_url" name="app_url" value="<?= h($appUrl) ?>" required>
                    <p class="hint">Detected automatically. Correct it if you are behind a proxy or will
                       use a different hostname.</p>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="support_email">Support email</label>
                        <input type="email" id="support_email" name="support_email"
                               value="<?= h($answers['support_email'] ?? ($host !== '' ? 'support@' . $host : '')) ?>">
                    </div>
                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone">
                            <?php $tz = $answers['timezone'] ?? 'Asia/Kolkata'; ?>
                            <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?>
                                <option value="<?= h($zone) ?>" <?= $zone === $tz ? 'selected' : '' ?>><?= h($zone) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint">Timestamps are stored in UTC and displayed in this zone.</p>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Outgoing email (optional)</legend>
                <p class="hint" style="margin-top:0">
                    Needed for password resets and alerts. Leave blank to configure later —
                    you can still sign in without it.
                </p>

                <div class="grid">
                    <div class="field">
                        <label for="mail_host">SMTP host</label>
                        <input type="text" id="mail_host" name="mail_host" value="<?= h($answers['mail_host'] ?? '') ?>"
                               placeholder="smtp.example.com">
                    </div>
                    <div class="field">
                        <label for="mail_port">Port</label>
                        <input type="number" id="mail_port" name="mail_port" value="<?= h($answers['mail_port'] ?? 587) ?>">
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="mail_user">Username</label>
                        <input type="text" id="mail_user" name="mail_user" value="<?= h($answers['mail_user'] ?? '') ?>"
                               autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="mail_pass">Password</label>
                        <input type="password" id="mail_pass" name="mail_pass" value="<?= h($answers['mail_pass'] ?? '') ?>"
                               autocomplete="off">
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="mail_security">Encryption</label>
                        <select id="mail_security" name="mail_security">
                            <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'] as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= ($answers['mail_security'] ?? 'tls') === $value ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="mail_from">From address</label>
                        <input type="email" id="mail_from" name="mail_from"
                               value="<?= h($answers['mail_from'] ?? ($host !== '' ? 'no-reply@' . $host : '')) ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="test_to">Send a test email to</label>
                    <input type="email" id="test_to" value="<?= h($answers['admin_email'] ?? '') ?>">
                </div>
                <button type="button" class="btn" data-test="mail">Send test email</button>
                <div id="mail-result" class="result"></div>
            </fieldset>

            <fieldset>
                <legend>Coordinator (optional)</legend>
                <p class="hint" style="margin-top:0">
                    The Go coordinator handles NAT traversal and peer discovery. The panel works
                    without it; devices will simply not find each other until it is running.
                </p>
                <div class="grid">
                    <div class="field">
                        <label for="coordinator_host">Host</label>
                        <input type="text" id="coordinator_host" name="coordinator_host"
                               value="<?= h($answers['coordinator_host'] ?? '127.0.0.1') ?>">
                    </div>
                    <div class="field">
                        <label for="coordinator_port">Port</label>
                        <input type="number" id="coordinator_port" name="coordinator_port"
                               value="<?= h($answers['coordinator_port'] ?? 8443) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>GitHub updates (optional)</legend>
                <p class="hint" style="margin-top:0">
                    Set this up and you will never upload files by hand again — updates arrive
                    from your repository with a backup, migrations, a health check and automatic
                    rollback. You can also configure it later under System → Updates.
                </p>

                <div class="grid">
                    <div class="field">
                        <label for="gh_owner">Owner</label>
                        <input type="text" id="gh_owner" name="gh_owner" value="<?= h($answers['gh_owner'] ?? '') ?>"
                               placeholder="your-org">
                    </div>
                    <div class="field">
                        <label for="gh_repo">Repository</label>
                        <input type="text" id="gh_repo" name="gh_repo" value="<?= h($answers['gh_repo'] ?? '') ?>"
                               placeholder="panel">
                    </div>
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="gh_branch">Branch</label>
                        <input type="text" id="gh_branch" name="gh_branch" value="<?= h($answers['gh_branch'] ?? 'main') ?>">
                    </div>
                    <div class="field">
                        <label for="gh_token">Access token</label>
                        <input type="password" id="gh_token" name="gh_token" autocomplete="off"
                               placeholder="ghp_… or github_pat_…">
                        <p class="hint">Stored encrypted. Never displayed or logged again.</p>
                    </div>
                </div>

                <button type="button" class="btn" data-test="github">Test connection</button>
                <div id="gh-result" class="result"></div>
            </fieldset>
        </div>

        <div class="card-foot">
            <a class="btn" href="?step=admin">← Back</a>
            <button type="submit" class="btn btn-primary">Review and install →</button>
        </div>
    </form>
</div>
