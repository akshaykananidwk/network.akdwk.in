<?php
declare(strict_types=1);
/** @var Install\Installer $installer */
$check = $installer->checkRequirements();
?>
<div class="card">
    <h2>Server requirements</h2>
    <div class="card-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><span class="alert-icon">✕</span><span><?= h($error) ?></span></div>
        <?php endforeach; ?>

        <?php if ($check['ok']): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✓</span>
                <span>Everything required is in place. Optional items below are recommendations, not blockers.</span>
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                <span class="alert-icon">✕</span>
                <span>Some required items are missing. Each row below says exactly what to do.</span>
            </div>
        <?php endif; ?>

        <?php foreach ($check['groups'] as $group => $rows): ?>
            <h3 style="font-size:.9rem;margin:1.2rem 0 .4rem"><?= h($group) ?></h3>
            <table class="checks">
                <thead>
                <tr><th>Item</th><th>Found</th><th>Needed</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= h($row['label']) ?></code></td>
                        <td><?= h($row['found']) ?></td>
                        <td class="hint"><?= h($row['expected']) ?></td>
                        <td>
                            <?php if ($row['ok']): ?>
                                <span class="pill pill-ok">OK</span>
                            <?php elseif ($row['required']): ?>
                                <span class="pill pill-fail">Required</span>
                            <?php else: ?>
                                <span class="pill pill-warn">Optional</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($row['fix'] !== ''): ?>
                        <tr><td colspan="4" class="fix">↳ <?= h($row['fix']) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>

    <form method="post" action="?step=requirements">
        <input type="hidden" name="_token" value="<?= h($csrf) ?>">
        <div class="card-foot">
            <a class="btn" href="?step=requirements">Re-check</a>
            <button type="submit" class="btn btn-primary" <?= $check['ok'] ? '' : 'disabled' ?>>
                Continue to database →
            </button>
        </div>
    </form>
</div>
