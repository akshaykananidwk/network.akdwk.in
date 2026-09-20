<?php
/** @var array<string,mixed>|null $auth_user */
declare(strict_types=1);

$path = \App\Core\Request::current()?->path() ?? '';
$isActive = static fn (string $prefix): bool => $prefix === '/'
    ? $path === '/'
    : str_starts_with($path, $prefix);

$tenantNav = [
    ['/dashboard',        'Dashboard',    'grid',    ''],
    ['/networks',         'Networks',     'network', 'network.view'],
    ['/devices',          'Devices',      'device',  'device.view'],
    ['/settings/users',   'Users & roles','users',   'user.view'],
    ['/settings/api-keys','API keys',     'key',     'apikey.view'],
    ['/settings/audit',   'Audit log',    'list',    'audit.view'],
];

$platformNav = [
    ['/admin/tenants', 'Customers', 'building', 'tenant.manage'],
    ['/admin/relays',  'Relays',    'relay',    'relay.manage'],
    ['/admin/updates', 'Updates',   'download', 'update.manage'],
    ['/admin/backups', 'Backups',   'archive',  'backup.manage'],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
        <div class="sidebar-logo" aria-hidden="true"><?= e(mb_substr((string) brand('name'), 0, 1)) ?></div>
        <div class="sidebar-brand-text">
            <strong><?= e(brand('name')) ?></strong>
            <?php if ($tenant !== null): ?>
                <span class="text-muted"><?= e($tenant['company_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($tenantNav as [$href, $label, $icon, $permission]): ?>
            <?php if ($permission === '' || can($permission)): ?>
                <a href="<?= e(url(ltrim($href, '/'))) ?>"
                   class="nav-item<?= $isActive($href) ? ' is-active' : '' ?>"
                   <?= $isActive($href) ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
                    <span><?= e($label) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php
        $showPlatform = false;
        foreach ($platformNav as [, , , $permission]) {
            if (can($permission)) {
                $showPlatform = true;
                break;
            }
        }
        ?>
        <?php if ($showPlatform): ?>
            <div class="nav-section">Platform</div>
            <?php foreach ($platformNav as [$href, $label, $icon, $permission]): ?>
                <?php if (can($permission)): ?>
                    <a href="<?= e(url(ltrim($href, '/'))) ?>"
                       class="nav-item<?= $isActive($href) ? ' is-active' : '' ?>"
                       <?= $isActive($href) ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-foot">
        <a href="<?= e(url('account')) ?>" class="nav-item<?= $isActive('/account') ? ' is-active' : '' ?>">
            <span class="nav-avatar" aria-hidden="true"><?= e(mb_substr((string) ($auth_user['name'] ?? '?'), 0, 1)) ?></span>
            <span class="nav-user">
                <strong><?= e($auth_user['name'] ?? '') ?></strong>
                <span class="text-muted"><?= e(\App\Core\Rbac::label($auth_role ?? '')) ?></span>
            </span>
        </a>
        <form method="post" action="<?= e(url('logout')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-block">Sign out</button>
        </form>
    </div>
</aside>
