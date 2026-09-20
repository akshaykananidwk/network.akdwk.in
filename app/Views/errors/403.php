<?php
/** @var int $status @var string $message @var string $requestId */
declare(strict_types=1);
\App\Core\View::layout('layouts.auth');
?>
<div class="error-page">
    <p class="error-code">403</p>
    <h2>Forbidden</h2>
    <p class="text-muted"><?= e($message ?? '') ?></p>

    <?php if (!empty($exception) && config('app.debug')): ?>
        <pre class="code-block"><?= e($exception->getMessage()) ?>
<?= e($exception->getFile()) ?>:<?= e($exception->getLine()) ?></pre>
    <?php endif; ?>

    <div class="form-actions">
        <a class="btn btn-primary" href="<?= e(url('dashboard')) ?>">Back to the dashboard</a>
    </div>

    <p class="text-muted text-sm">Reference <code><?= e($requestId ?? '') ?></code></p>
</div>
