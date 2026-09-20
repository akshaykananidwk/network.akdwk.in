<?php
/**
 * Shown to everyone except the operator driving the update.
 * Deliberately dependency-free: no layout, no database, no session.
 *
 * @var string $reason
 * @var int $retry_after
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="<?= (int) $retry_after ?>">
    <title>Back shortly · <?= e(brand('name')) ?></title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0;
               min-height: 100vh; display: grid; place-items: center; background: #0f172a; color: #e2e8f0; }
        .box { max-width: 30rem; padding: 2.5rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { color: #94a3b8; line-height: 1.6; margin: 0 0 .5rem; }
        .spinner { width: 2rem; height: 2rem; margin: 0 auto 1.5rem; border: 3px solid #334155;
                   border-top-color: #38bdf8; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
    </style>
</head>
<body>
    <div class="box">
        <div class="spinner" aria-hidden="true"></div>
        <h1>We will be back in a moment</h1>
        <p><?= e($reason) ?></p>
        <p>Your connected devices are unaffected — tunnels keep running while the panel is updated.</p>
        <p style="margin-top:1.5rem;font-size:.85rem">This page refreshes automatically.</p>
    </div>
</body>
</html>
