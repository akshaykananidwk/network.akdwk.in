<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<?php if (!empty($new_key)): ?>
    <div class="alert alert-warning" role="alert">
        <span class="alert-icon" aria-hidden="true">!</span>
        <div>
            <strong>Copy this key now — it is never shown again.</strong>
            <div class="code-row mt-2">
                <code id="new-api-key"><?= e($new_key) ?></code>
                <button type="button" class="btn btn-sm" data-action="copy" data-copy-target="new-api-key">Copy</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <header class="card-header">
        <h2>API keys</h2>
        <p class="text-muted">Authenticate with <code>Authorization: Bearer &lt;key&gt;</code></p>
    </header>

    <?php if ($result['rows'] === []): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⚿', 'title' => 'No API keys', 'message' => 'Create one to automate against the REST API.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Key</th>
                    <th scope="col">Permissions</th>
                    <th scope="col">Last used</th>
                    <th scope="col">Expires</th>
                    <th scope="col" class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $key): ?>
                    <tr class="<?= $key['revoked_at'] !== null ? 'is-muted' : '' ?>">
                        <td><strong><?= e($key['name']) ?></strong></td>
                        <td><code><?= e($key['key_prefix']) ?>…</code></td>
                        <td>
                            <?php foreach (array_slice((array) ($key['scopes_json'] ?? []), 0, 4) as $scope): ?>
                                <span class="chip chip-sm"><?= e($scope) ?></span>
                            <?php endforeach; ?>
                            <?php if (count((array) ($key['scopes_json'] ?? [])) > 4): ?>
                                <span class="text-muted">+<?= e(count((array) $key['scopes_json']) - 4) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= e(time_ago($key['last_used_at'])) ?></td>
                        <td class="text-muted"><?= $key['expires_at'] !== null ? e(local_time($key['expires_at'], 'd M Y')) : 'never' ?></td>
                        <td class="col-actions">
                            <?php if ($key['revoked_at'] === null && can('apikey.revoke')): ?>
                                <form method="post" action="<?= e(url('settings/api-keys/' . $key['id'] . '/revoke')) ?>"
                                      class="inline" data-confirm="Revoke &quot;<?= e($key['name']) ?>&quot;? Anything using it stops working immediately.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-ghost">Revoke</button>
                                </form>
                            <?php else: ?>
                                <span class="chip">revoked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (can('apikey.create')): ?>
<section class="card">
    <header class="card-header">
        <h2>Create a key</h2>
        <p class="text-muted">A key can never do more than you can.</p>
    </header>

    <form method="post" action="<?= e(url('settings/api-keys')) ?>" class="form">
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field">
                <label for="key_name">Name</label>
                <input type="text" id="key_name" name="name" required maxlength="120" placeholder="Monitoring script">
            </div>
            <div class="field">
                <label for="expires_at">Expires (optional)</label>
                <input type="date" id="expires_at" name="expires_at">
            </div>
        </div>

        <fieldset class="field">
            <legend>Permissions</legend>
            <div class="scope-grid">
                <?php foreach ($scopes as $scope): ?>
                    <?php if (\App\Core\Rbac::isPlatformPermission($scope) || !can($scope)) { continue; } ?>
                    <label class="checkbox">
                        <input type="checkbox" name="scopes[]" value="<?= e($scope) ?>">
                        <span><code><?= e($scope) ?></code></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create key</button>
        </div>
    </form>
</section>
<?php endif; ?>
