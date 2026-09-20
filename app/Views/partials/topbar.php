<?php
/** @var string $title */
declare(strict_types=1);
?>
<header class="topbar">
    <button type="button" class="btn btn-icon sidebar-toggle" data-action="toggle-sidebar"
            aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
        <span aria-hidden="true">☰</span>
    </button>

    <h1 class="topbar-title"><?= e($title) ?></h1>

    <div class="topbar-actions">
        <span class="live-indicator" id="live-indicator" title="Live connection status">
            <span class="live-dot" aria-hidden="true"></span>
            <span class="live-label">live</span>
        </span>

        <a href="<?= e(url('notifications')) ?>" class="btn btn-icon" aria-label="Notifications">
            <span aria-hidden="true">🔔</span>
            <?php if (($unread_notifications ?? 0) > 0): ?>
                <span class="badge badge-dot"><?= e(min(99, (int) $unread_notifications)) ?></span>
            <?php endif; ?>
        </a>

        <button type="button" class="btn btn-icon" data-action="toggle-theme"
                aria-label="Switch between light and dark">
            <span aria-hidden="true">◐</span>
        </button>
    </div>
</header>
