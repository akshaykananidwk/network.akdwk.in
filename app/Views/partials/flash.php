<?php
/**
 * Flash messages. Values are already read-and-cleared by the controller, so
 * rendering twice in one request cannot duplicate a message.
 */
declare(strict_types=1);

$messages = [
    'success' => $flash_success ?? null,
    'warning' => $flash_warning ?? null,
    'error'   => $flash_error ?? null,
];
?>
<?php foreach ($messages as $type => $message): ?>
    <?php if (is_string($message) && $message !== ''): ?>
        <div class="alert alert-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
            <span class="alert-icon" aria-hidden="true">
                <?= $type === 'success' ? '✓' : ($type === 'warning' ? '!' : '✕') ?>
            </span>
            <span><?= e($message) ?></span>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
