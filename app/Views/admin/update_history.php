<?php
/** @var array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $history */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <h2>Update history</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/updates')) ?>">Back to updates</a>
    </header>

    <?php if (empty($history['rows'])): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⇩', 'title' => 'No updates recorded yet', 'message' => '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">From → to</th>
                    <th scope="col">Commit</th>
                    <th scope="col">Status</th>
                    <th scope="col">Duration</th>
                    <th scope="col">Backup</th>
                    <th scope="col">By</th>
                    <th scope="col" class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history['rows'] as $row): ?>
                    <?php
                    $duration = '—';
                    if (!empty($row['started_at']) && !empty($row['finished_at'])) {
                        $seconds = strtotime((string) $row['finished_at']) - strtotime((string) $row['started_at']);
                        $duration = $seconds >= 60 ? intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's' : $seconds . 's';
                    }
                    ?>
                    <tr>
                        <td><?= e(local_time($row['created_at'])) ?></td>
                        <td><?= e($row['from_version'] ?? '?') ?> → <strong><?= e($row['to_version'] ?? '?') ?></strong></td>
                        <td>
                            <?php if (!empty($row['to_commit'])): ?>
                                <code><?= e(substr((string) $row['to_commit'], 0, 7)) ?></code>
                            <?php endif; ?>
                        </td>
                        <td><span class="status status-<?= e($row['status']) ?>"><?= e(str_replace('_', ' ', (string) $row['status'])) ?></span></td>
                        <td class="text-muted"><?= e($duration) ?></td>
                        <td class="text-muted">
                            <?= !empty($row['backup_size']) ? e(format_bytes((int) $row['backup_size'])) : '—' ?>
                        </td>
                        <td class="text-muted"><?= e($row['started_by_email'] ?? 'system') ?></td>
                        <td class="col-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/updates/' . $row['id'])) ?>">View log</a>
                            <?php if (!empty($row['rollback_available'])): ?>
                                <button type="button" class="btn btn-sm btn-danger" data-action="rollback"
                                        data-update-id="<?= e($row['id']) ?>">Roll back</button>
                            <?php elseif ($row['status'] === 'success'): ?>
                                <span class="text-muted" title="The per-file undo list for this update has been pruned, so a rollback could no longer restore its files.">No undo list</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::partial('partials.pagination', ['result' => $history]) ?>
    <?php endif; ?>
</section>
