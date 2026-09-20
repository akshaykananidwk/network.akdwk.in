<?php
/** @var array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} $result */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <div>
            <h2>Networks</h2>
            <p class="text-muted"><?= e(number_format((int) $result['total'])) ?> total</p>
        </div>
        <div class="card-header-actions">
            <form method="get" class="search-form" role="search">
                <input type="search" name="q" value="<?= e($params['q']) ?>" placeholder="Search networks" aria-label="Search networks">
                <button type="submit" class="btn btn-sm">Search</button>
            </form>
            <?php if (can('network.create')): ?>
                <a class="btn btn-primary" href="<?= e(url('networks/new')) ?>">New network</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($result['rows'] === []): ?>
        <?= \App\Core\View::partial('partials.empty', [
            'icon' => '⬡',
            'title' => $params['q'] !== '' ? 'No networks match that search' : 'No networks yet',
            'message' => 'A network is a private address range shared by your devices.',
            'actionLabel' => can('network.create') ? 'Create a network' : '',
            'actionUrl' => can('network.create') ? url('networks/new') : '',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Range</th>
                    <th scope="col">Devices</th>
                    <th scope="col">Status</th>
                    <th scope="col">Created</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $network): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('networks/' . $network['id'])) ?>"><strong><?= e($network['name']) ?></strong></a>
                            <?php if (!empty($network['description'])): ?>
                                <div class="text-muted text-sm"><?= e($network['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($network['cidr']) ?></code></td>
                        <td>
                            <span class="chip chip-online"><?= e($network['device_counts']['online']) ?> online</span>
                            <span class="text-muted"><?= e($network['device_counts']['total']) ?> total</span>
                            <?php if ($network['device_counts']['pending'] > 0): ?>
                                <span class="chip chip-warning"><?= e($network['device_counts']['pending']) ?> pending</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status status-<?= e($network['status']) ?>"><?= e($network['status']) ?></span></td>
                        <td class="text-muted"><?= e(local_time($network['created_at'], 'd M Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= \App\Core\View::partial('partials.pagination', ['result' => $result]) ?>
    <?php endif; ?>
</section>
