<?php
/**
 * Main application shell.
 *
 * @var string $content
 * @var array<string,mixed>|null $auth_user
 * @var string $csp_nonce
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="<?= e(\App\Core\Lang::locale()) ?>" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title><?= e(($title ?? 'Dashboard') . ' · ' . brand('name')) ?></title>
    <meta name="theme-color" content="<?= e(brand('primary_color', '#2563eb')) ?>">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style nonce="<?= e($csp_nonce ?? '') ?>">
        :root {
            --brand-primary: <?= e(brand('primary_color', '#2563eb')) ?>;
            --brand-accent: <?= e(brand('accent_color', '#0ea5e9')) ?>;
        }
    </style>
</head>
<body class="h-full bg-base text-body antialiased" data-theme-pref="auto">

<a href="#main" class="skip-link">Skip to content</a>

<?php if (!empty($is_impersonating)): ?>
    <div class="impersonation-bar" role="status">
        <span>
            You are acting as <strong><?= e($auth_user['email'] ?? '') ?></strong>
            <?= $tenant !== null ? 'at ' . e($tenant['company_name']) : '' ?>.
        </span>
        <form method="post" action="<?= e(url('admin/stop-impersonating')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-ghost">Stop impersonating</button>
        </form>
    </div>
<?php endif; ?>

<div class="app-shell">
    <?= \App\Core\View::partial('partials.sidebar') ?>

    <div class="app-main">
        <?= \App\Core\View::partial('partials.topbar', ['title' => $title ?? '']) ?>

        <main id="main" class="app-content" tabindex="-1">
            <?= \App\Core\View::partial('partials.flash') ?>
            <?= $content ?>
        </main>

        <footer class="app-footer">
            <span>
                <?php if (brand('powered_by', true)): ?>
                    Powered by <?= e(brand('name')) ?>
                <?php else: ?>
                    <?= e(brand('org', '')) ?>
                <?php endif; ?>
            </span>
            <span class="text-muted">v<?= e($app_version ?? '') ?></span>
        </footer>
    </div>
</div>

<div id="toasts" class="toast-stack" aria-live="polite" aria-atomic="true"></div>

<script nonce="<?= e($csp_nonce ?? '') ?>">
    window.APP = <?= e_js([
        'baseUrl'   => rtrim((string) config('app.url', ''), '/'),
        'csrfToken' => csrf_token(),
        'csrfHeader' => \App\Core\Csrf::headerName(),
        'streamUrl' => url('api/v1/stream'),
        'statsUrl'  => url('dashboard/stats'),
        'pollMs'    => 5000,
    ]) ?>;
</script>
<!-- qr.js is loaded first: app.js calls into it to draw the 2FA QR code.
     It is vendored rather than pulled from a CDN because the CSP forbids
     external scripts, and a TOTP secret must never be sent to a third party. -->
<script nonce="<?= e($csp_nonce ?? '') ?>" src="<?= e(asset('js/qr.js')) ?>" defer></script>
<script nonce="<?= e($csp_nonce ?? '') ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
