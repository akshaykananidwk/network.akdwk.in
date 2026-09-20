<?php
/** @var list<array<string,mixed>> $backups */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Backups</h2>
            <p class="text-muted">
                <?= e(count($backups)) ?> stored, <?= e(format_bytes($total_size)) ?> total ·
                keeping the newest <?= e($retention) ?> ·
                <?= e(format_bytes((int) $free_disk)) ?> free on disk
            </p>
        </div>
        <div class="card-header-actions">
            <form method="post" action="<?= e(url('admin/backups')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Back up now</button>
            </form>
            <form method="post" action="<?= e(url('admin/backups/prune')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost">Prune old</button>
            </form>
        </div>
    </header>

    <?php if ($backups === []): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⬓',
            'title' => 'No backups yet',
            'message' => 'One is taken automatically before every update. You can also take one now.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Created</th>
                    <th scope="col">Type</th>
                    <th scope="col">Version</th>
                    <th scope="col">Size</th>
                    <th scope="col">Contents</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><?= e(local_time($backup['created_at'])) ?></td>
                        <td><span class="chip"><?= e(str_replace('_', ' ', (string) $backup['type'])) ?></span></td>
                        <td><?= e($backup['app_version'] ?? '—') ?></td>
                        <td><?= e(format_bytes((int) $backup['size_bytes'])) ?></td>
                        <td>
                            <span class="chip chip-online">files + database</span>
                            <?php if ((int) $backup['uploads_included'] !== 1): ?>
                                <!-- Stated explicitly rather than implied: an operator who
                                     believes they have a full backup and does not is worse
                                     off than one who knows it is partial. -->
                                <span class="chip chip-warning"
                                      title="<?= e($backup['uploads_skipped_reason'] ?? '') ?>">uploads excluded</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status status-<?= e($backup['status']) ?>"><?= e($backup['status']) ?></span></td>
                        <td class="col-actions">
                            <form method="post" action="<?= e(url('admin/backups/' . $backup['id'] . '/verify')) ?>" class="inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Verify</button>
                            </form>
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e(url('admin/backups/' . $backup['id'] . '/download?part=files')) ?>">Files</a>
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e(url('admin/backups/' . $backup['id'] . '/download?part=db')) ?>">DB</a>
                            <button type="button" class="btn btn-sm btn-danger"
                                    data-action="open-restore" data-backup-id="<?= e($backup['id']) ?>">Restore</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Restore is typed-confirmation only: it overwrites the live install. -->
<dialog id="restore-dialog" class="dialog">
    <form method="post" id="restore-form" class="form">
        <?= csrf_field() ?>
        <h2>Restore a backup</h2>
        <p class="text-muted">
            This overwrites the application files and replaces the database with the contents of
            backup <strong id="restore-backup-label"></strong>. Protected paths
            (<code>config/config.php</code>, <code>.env</code>, <code>uploads/</code>) are left alone.
            There is no undo.
        </p>

        <div class="field">
            <label for="confirm">Type the backup number to confirm</label>
            <input type="text" id="confirm" name="confirm" required autocomplete="off" inputmode="numeric">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-danger">Restore</button>
            <button type="button" class="btn btn-ghost" data-action="close-dialog">Cancel</button>
        </div>
    </form>
</dialog>
