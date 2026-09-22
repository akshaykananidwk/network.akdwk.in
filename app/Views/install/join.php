<?php
/**
 * The page at the end of the link a supplier sends.
 *
 * Written for a shopkeeper on a phone, not for a technician. One button, one
 * code, three sentences, and nothing that needs explaining first. No
 * navigation: there is nowhere else on this panel this visitor should go.
 *
 * @var string $code
 * @var bool   $available
 */
declare(strict_types=1);
\App\Core\View::layout('layouts.auth');
?>
<div class="install-page">
    <h1>Install <?= e(brand('name')) ?></h1>

    <?php if (!$available): ?>
        <p class="alert alert-error">
            The installer has not been published on this panel yet. Tell whoever sent you
            this link — there is nothing you can do from here.
        </p>
    <?php else: ?>
        <ol class="install-steps">
            <li>
                <a class="btn btn-primary btn-lg" href="<?= e(url('download/setup.exe')) ?>" download>
                    Download for Windows
                </a>
            </li>
            <li>Double-click the file you just downloaded, and say yes to Windows.</li>
            <?php if ($code !== ''): ?>
                <li>
                    Type this code when it asks, and click OK:
                    <div class="code-row">
                        <code class="join-code" id="join-code"><?= e($code) ?></code>
                        <button type="button" class="btn btn-sm"
                                data-action="copy" data-copy-target="join-code">Copy</button>
                    </div>
                </li>
            <?php else: ?>
                <li>Type the code you were sent when it asks, and click OK.</li>
            <?php endif; ?>
        </ol>

        <p class="text-muted">
            That is everything. The computer joins by itself and stays joined — through
            restarts, through Wi-Fi changes, without anybody touching it again.
        </p>
    <?php endif; ?>
</div>
