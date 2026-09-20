<?php
/**
 * @var array<string,mixed> $update
 * @var list<string> $log
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');

$failed = in_array($update['status'], ['failed', 'rolled_back'], true);
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Update #<?= e($update['id']) ?></h2>
            <p class="text-muted">
                <?= e($update['from_version'] ?? '?') ?> → <?= e($update['to_version'] ?? '?') ?>
                <?php if (!empty($update['to_commit'])): ?>
                    · <code><?= e(substr((string) $update['to_commit'], 0, 7)) ?></code>
                <?php endif; ?>
            </p>
        </div>
        <div class="card-header-actions">
            <a class="btn btn-sm" href="<?= e(url('admin/updates/' . $update['id'] . '/log')) ?>">Download log</a>
            <?php if ($update['status'] === 'success' && !empty($update['journal_path'])): ?>
                <button type="button" class="btn btn-sm btn-danger" data-action="rollback"
                        data-update-id="<?= e($update['id']) ?>">Roll back to previous version</button>
            <?php endif; ?>
        </div>
    </header>

    <dl class="detail-list">
        <div>
            <dt>Status</dt>
            <dd><span class="status status-<?= e($update['status']) ?>"><?= e(str_replace('_', ' ', (string) $update['status'])) ?></span></dd>
        </div>
        <div>
            <dt>Reached step</dt>
            <dd><?= e($update['step']) ?> (<?= e($update['progress_pct']) ?>%)</dd>
        </div>
        <div>
            <dt>Started</dt>
            <dd><?= e(local_time($update['started_at'])) ?></dd>
        </div>
        <div>
            <dt>Finished</dt>
            <dd><?= e($update['finished_at'] !== null ? local_time($update['finished_at']) : '—') ?></dd>
        </div>
        <div>
            <dt>Triggered by</dt>
            <dd><?= e($update['trigger_source']) ?></dd>
        </div>
        <div>
            <dt>Backup</dt>
            <dd>
                <?php if (!empty($update['backup_id'])): ?>
                    <a href="<?= e(url('admin/backups')) ?>">#<?= e($update['backup_id']) ?></a>
                <?php else: ?>
                    <span class="text-muted">none (failed before the backup step)</span>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>Migrations applied</dt>
            <dd>
                <?php $applied = (array) ($update['applied_migrations_json'] ?? []); ?>
                <?php if ($applied === []): ?>
                    <span class="text-muted">none</span>
                <?php else: ?>
                    <?php foreach ($applied as $migration): ?>
                        <code class="block"><?= e($migration) ?></code>
                    <?php endforeach; ?>
                <?php endif; ?>
            </dd>
        </div>
    </dl>

    <?php if ($failed && !empty($update['error_text'])): ?>
        <div class="alert alert-error" role="alert">
            <span class="alert-icon" aria-hidden="true">✕</span>
            <span><?= e($update['error_text']) ?></span>
        </div>

        <?php if (!empty($update['backup_id'])): ?>
            <div class="recovery-box">
                <h3>Manual recovery</h3>
                <p class="text-muted">
                    If the panel is still in maintenance mode, the automatic rollback did not finish.
                    These commands restore backup #<?= e($update['backup_id']) ?> by hand.
                    <code>config/config.php</code>, <code>.env</code> and <code>uploads/</code> were never
                    touched and do not need restoring.
                </p>
                <pre class="code-block"><code>cd <?= e(APP_ROOT) ?>

tar -xzf storage/backups/&lt;timestamp&gt;/files.tar.gz
gunzip -c storage/backups/&lt;timestamp&gt;/db.sql.gz | mysql -u &lt;db_user&gt; -p &lt;db_name&gt;
rm storage/maintenance.flag</code></pre>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <header class="card-header">
        <h2>Step-by-step log</h2>
        <p class="text-muted">Timestamps are UTC. Secrets are redacted before anything is written here.</p>
    </header>
    <pre class="log-tail log-tall"><?php foreach ($log as $line) {
        echo e($line), "\n";
    } ?></pre>
</section>
