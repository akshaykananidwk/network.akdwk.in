<?php
/**
 * @var string $title
 * @var string $message
 * @var string $actionLabel
 * @var string $actionUrl
 */
declare(strict_types=1);
?>
<div class="empty-state">
    <div class="empty-icon" aria-hidden="true"><?= e($icon ?? '◯') ?></div>
    <h3><?= e($title ?? 'Nothing here yet') ?></h3>
    <p class="text-muted"><?= e($message ?? '') ?></p>
    <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
        <a class="btn btn-primary" href="<?= e($actionUrl) ?>"><?= e($actionLabel) ?></a>
    <?php endif; ?>
</div>
