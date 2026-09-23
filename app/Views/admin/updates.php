<?php
/**
 * System → Updates (§9.1).
 *
 * @var array<string,mixed> $settings  masked projection — never the real token
 * @var array<string,mixed>|null $running
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>

<section class="card" id="update-status-card">
    <header class="card-header">
        <div>
            <h2>Current version</h2>
            <p class="text-muted">
                Running <strong><?= e($version !== '' ? $version : 'unknown') ?></strong>
                <?php if (!empty($settings['current_commit'])): ?>
                    · commit <code><?= e(substr((string) $settings['current_commit'], 0, 7)) ?></code>
                <?php endif; ?>
            </p>
        </div>

        <div class="card-header-actions">
            <button type="button" class="btn" data-action="check-update">Check for update</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/updates/history')) ?>">History</a>
        </div>
    </header>

    <?php if ($running !== null): ?>
        <div class="alert alert-warning" role="status">
            Update #<?= e($running['id']) ?> is in progress (step <?= e($running['step']) ?>).
            <button type="button" class="btn btn-sm" data-action="resume-update"
                    data-update-id="<?= e($running['id']) ?>">Resume</button>
        </div>
    <?php endif; ?>

    <div id="update-check-result" class="update-result" hidden></div>

    <!-- Progress panel, revealed once an update starts. -->
    <div id="update-progress" class="update-progress" hidden>
        <div class="progress-head">
            <strong id="update-step-label">Starting…</strong>
            <span id="update-progress-pct" class="text-muted">0%</span>
        </div>
        <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"
             aria-valuenow="0" aria-labelledby="update-step-label">
            <span class="progress-fill" id="update-progress-fill"></span>
        </div>
        <pre class="log-tail" id="update-log" aria-live="polite"></pre>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2>GitHub repository</h2>
        <p class="text-muted">Set this once. After that, updating is a single click.</p>
    </header>

    <form method="post" action="<?= e(url('admin/updates/settings')) ?>" class="form" id="update-settings-form">
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field">
                <label for="repo_owner">Owner</label>
                <input type="text" id="repo_owner" name="repo_owner"
                       value="<?= e($settings['repo_owner'] ?? '') ?>" placeholder="your-org" required>
            </div>
            <div class="field">
                <label for="repo_name">Repository</label>
                <input type="text" id="repo_name" name="repo_name"
                       value="<?= e($settings['repo_name'] ?? '') ?>" placeholder="panel" required>
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="branch">Branch</label>
                <input type="text" id="branch" name="branch" value="<?= e($settings['branch'] ?? 'main') ?>" required>
                <p class="hint">Only used by the Edge channel. Stable and Beta follow tags.</p>
            </div>
            <div class="field">
                <label for="channel">Channel</label>
                <select id="channel" name="channel">
                    <option value="stable" <?= ($settings['channel'] ?? '') === 'stable' ? 'selected' : '' ?>>Stable — released versions only</option>
                    <option value="beta" <?= ($settings['channel'] ?? '') === 'beta' ? 'selected' : '' ?>>Beta — released versions and release candidates</option>
                    <option value="edge" <?= ($settings['channel'] ?? '') === 'edge' ? 'selected' : '' ?>>Edge — whatever is on the branch right now</option>
                </select>
                <p class="hint">
                    Stable installs tagged releases (<code>v1.9.3</code>). Edge installs the
                    latest commit, finished or not — useful while a fix is being written,
                    and not what a panel with customers on it should be set to.
                </p>
            </div>
        </div>

        <div class="field">
            <label for="token">Personal access token</label>
            <input type="password" id="token" name="token" autocomplete="off"
                   placeholder="<?= $settings['token_set']
                       ? 'Stored — leave blank to keep ' . e($settings['token_masked'])
                       : 'ghp_… or github_pat_…' ?>">
            <p class="field-hint">
                Needs <code>repo</code> scope for a private repository, or <code>public_repo</code> for a public one.
                The token is encrypted at rest and is never displayed, logged or sent back to this page.
                Leave blank to keep the stored one.
            </p>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="check_interval_hours">Check every (hours)</label>
                <input type="number" id="check_interval_hours" name="check_interval_hours" min="1" max="168"
                       value="<?= e($settings['check_interval_hours'] ?? 6) ?>">
            </div>
            <div class="field">
                <label for="backup_retention">Backups to keep</label>
                <input type="number" id="backup_retention" name="backup_retention" min="1" max="50"
                       value="<?= e($settings['backup_retention'] ?? 5) ?>">
            </div>
        </div>

        <fieldset class="field">
            <legend>Behaviour</legend>

            <label class="checkbox">
                <input type="checkbox" name="auto_check" value="1" <?= !empty($settings['auto_check']) ? 'checked' : '' ?>>
                <span>Check automatically and notify me when an update is available</span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="auto_apply" value="1" <?= !empty($settings['auto_apply']) ? 'checked' : '' ?>>
                <span>
                    Apply updates automatically
                    <em class="text-muted">— off by default. An unattended update still takes a backup and
                    rolls back on failure, but nobody is watching if it does.</em>
                </span>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="verify_signature" value="1" <?= !empty($settings['verify_signature']) ? 'checked' : '' ?>>
                <span>
                    Require a signed manifest
                    <em class="text-muted">— refuse any release whose update.json signature does not verify
                    against the configured public key.</em>
                </span>
            </label>
        </fieldset>

        <div class="field">
            <label for="protected">Protected paths</label>
            <textarea id="protected" name="protected" rows="6" spellcheck="false"><?= e(implode("\n", (array) ($settings['protected'] ?? []))) ?></textarea>
            <p class="field-hint">
                One per line. These are never written, deleted or restored over by an update — even if a
                release's manifest asks. The defaults cannot be removed.
            </p>
        </div>

        <div class="field">
            <label for="maintenance_allowlist">Maintenance-mode allowlist</label>
            <input type="text" id="maintenance_allowlist" name="maintenance_allowlist"
                   value="<?= e(implode(', ', (array) ($settings['maintenance_allowlist'] ?? []))) ?>"
                   placeholder="203.0.113.10, 198.51.100.4">
            <p class="field-hint">IPs that can still reach the panel while an update runs. Your current IP is added automatically.</p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save settings</button>
            <button type="button" class="btn" data-action="test-connection">Test connection</button>
        </div>

        <div id="connection-result" class="update-result" hidden></div>
    </form>
</section>

<section class="card">
    <header class="card-header">
        <h2>Recent updates</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/updates/history')) ?>">See all</a>
    </header>

    <?php if (empty($history['rows'])): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⇩',
            'title' => 'No updates yet',
            'message' => 'Once a repository is configured, every update is recorded here with its log and backup.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Version</th>
                    <th scope="col">Status</th>
                    <th scope="col">By</th>
                    <th scope="col" class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history['rows'] as $row): ?>
                    <tr>
                        <td><?= e(local_time($row['created_at'])) ?></td>
                        <td>
                            <?= e($row['from_version'] ?? '?') ?> → <strong><?= e($row['to_version'] ?? '?') ?></strong>
                        </td>
                        <td><span class="status status-<?= e($row['status']) ?>"><?= e(str_replace('_', ' ', (string) $row['status'])) ?></span></td>
                        <td class="text-muted"><?= e($row['started_by_email'] ?? 'system') ?></td>
                        <td class="col-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/updates/' . $row['id'])) ?>">Log</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <header class="card-header">
        <h2>Applied migrations</h2>
        <p class="text-muted">Each runs once. Re-running the updater is a no-op.</p>
    </header>

    <?php if (empty($migrations)): ?>
        <p class="text-muted p-4">No migrations have been applied beyond the base schema.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Migration</th>
                    <th scope="col">Batch</th>
                    <th scope="col">Duration</th>
                    <th scope="col">Applied</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($migrations, 0, 25) as $migration): ?>
                    <tr>
                        <td><code><?= e($migration['filename']) ?></code></td>
                        <td><?= e($migration['batch']) ?></td>
                        <td class="text-muted"><?= e($migration['execution_ms']) ?> ms</td>
                        <td class="text-muted"><?= e(local_time($migration['executed_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
