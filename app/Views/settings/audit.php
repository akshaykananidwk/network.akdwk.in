<?php
/** @var array{rows:list<array<string,mixed>>} $result */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Audit log</h2>
            <p class="text-muted"><?= e(number_format((int) $result['total'])) ?> entries</p>
        </div>

        <form method="get" class="filter-bar">
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="User, IP or action" aria-label="Search">

            <select name="action" aria-label="Filter by action">
                <option value="">Any action</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= e($action) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="result" aria-label="Filter by result">
                <option value="">Any result</option>
                <option value="success" <?= $filters['result'] === 'success' ? 'selected' : '' ?>>Success</option>
                <option value="failure" <?= $filters['result'] === 'failure' ? 'selected' : '' ?>>Failure</option>
            </select>

            <input type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="From date">
            <input type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="To date">

            <button type="submit" class="btn btn-sm">Filter</button>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('settings/audit/export?' . http_build_query(array_filter($filters)))) ?>">Export CSV</a>
        </form>
    </header>

    <?php if ($result['rows'] === []): ?>
        <?= \App\Core\View::partial('partials.empty', ['icon' => '◷', 'title' => 'Nothing matches', 'message' => '']) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">When (UTC)</th>
                    <th scope="col">Who</th>
                    <th scope="col">Action</th>
                    <th scope="col">Target</th>
                    <th scope="col">Result</th>
                    <th scope="col">IP</th>
                    <th scope="col">Changes</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $entry): ?>
                    <tr>
                        <td class="text-sm"><?= e($entry['created_at']) ?></td>
                        <td>
                            <?= e($entry['user_email'] ?? 'system') ?>
                            <?php if (!empty($entry['impersonator_email'])): ?>
                                <div class="text-sm text-warning">via <?= e($entry['impersonator_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($entry['action']) ?></code></td>
                        <td class="text-muted">
                            <?= e($entry['target_type'] ?? '—') ?>
                            <?= $entry['target_id'] !== null ? '#' . e($entry['target_id']) : '' ?>
                        </td>
                        <td>
                            <span class="status status-<?= $entry['result'] === 'success' ? 'active' : 'down' ?>">
                                <?= e($entry['result']) ?>
                            </span>
                        </td>
                        <td class="text-muted text-sm"><?= e($entry['ip'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($entry['after_json'])): ?>
                                <details>
                                    <summary class="text-sm">view</summary>
                                    <pre class="code-block text-sm"><?= e(json_encode(
                                        ['before' => json_decode((string) ($entry['before_json'] ?? 'null'), true),
                                         'after'  => json_decode((string) $entry['after_json'], true)],
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                                    )) ?></pre>
                                </details>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::partial('partials.pagination', ['result' => $result]) ?>
    <?php endif; ?>
</section>
