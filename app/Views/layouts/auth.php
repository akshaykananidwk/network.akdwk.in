<?php
/**
 * Minimal shell for sign-in, 2FA and password reset — no navigation, so a
 * half-authenticated visitor has nowhere to wander.
 *
 * @var string $content
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="<?= e(\App\Core\Lang::locale()) ?>" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e(($title ?? 'Sign in') . ' · ' . brand('name')) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style nonce="<?= e($csp_nonce ?? '') ?>">
        :root {
            --brand-primary: <?= e(brand('primary_color', '#2563eb')) ?>;
            --brand-accent: <?= e(brand('accent_color', '#0ea5e9')) ?>;
        }
    </style>
</head>
<body class="h-full bg-base text-body antialiased auth-body">

<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="auth-logo" aria-hidden="true"><?= e(mb_substr((string) brand('name'), 0, 1)) ?></div>
            <h1><?= e(brand('name')) ?></h1>
            <p class="text-muted"><?= e(brand('org', '')) ?></p>
        </div>

        <?= \App\Core\View::partial('partials.flash') ?>
        <?= $content ?>
    </div>

    <p class="auth-footnote text-muted">
        Need help? <a href="mailto:<?= e(brand('support_email', '')) ?>"><?= e(brand('support_email', '')) ?></a>
    </p>
</div>

<script nonce="<?= e($csp_nonce ?? '') ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
