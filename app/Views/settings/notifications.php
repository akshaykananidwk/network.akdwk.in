<?php
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <h2>Notifications</h2>
        <form method="post" action="<?= e(url('notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-ghost">Mark all as read</button>
        </form>
    </header>

    <?php if ($notifications === []): ?>
        <?= \App\Core\View::partial('partials.empty', ['icon' => '🔔', 'title' => 'Nothing to read', 'message' => '']) ?>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($notifications as $notification): ?>
                <li class="list-row notification notification-<?= e($notification['level']) ?><?= $notification['read_at'] === null ? ' is-unread' : '' ?>">
                    <div class="list-main">
                        <strong><?= e($notification['title']) ?></strong>
                        <?php if (!empty($notification['body'])): ?>
                            <p class="text-muted"><?= nl2br(e($notification['body'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($notification['link'])): ?>
                            <a href="<?= e(url($notification['link'])) ?>">Open</a>
                        <?php endif; ?>
                    </div>
                    <div class="list-meta">
                        <time class="text-muted" datetime="<?= e($notification['created_at']) ?>">
                            <?= e(time_ago($notification['created_at'])) ?>
                        </time>
                        <?php if ($notification['read_at'] === null): ?>
                            <form method="post" action="<?= e(url('notifications/' . $notification['id'] . '/read')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
