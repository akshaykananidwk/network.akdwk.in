<?php
/** @var array{rows:list<array<string,mixed>>} $result */
declare(strict_types=1);
\App\Core\View::layout('layouts.app');
?>
<section class="card">
    <header class="card-header">
        <h2>Users &amp; roles</h2>
        <p class="text-muted"><?= e(number_format((int) $result['total'])) ?> user(s)</p>
    </header>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Role</th>
                <th scope="col">2FA</th>
                <th scope="col">Last sign-in</th>
                <th scope="col">Status</th>
                <th scope="col" class="col-actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $user): ?>
                <tr>
                    <td><strong><?= e($user['name']) ?></strong></td>
                    <td><?= e($user['email']) ?></td>
                    <td><span class="chip"><?= e(\App\Core\Rbac::label((string) $user['role'])) ?></span></td>
                    <td>
                        <?= (int) $user['twofa_enabled'] === 1
                            ? '<span class="chip chip-online">on</span>'
                            : '<span class="chip chip-warning">off</span>' ?>
                    </td>
                    <td class="text-muted">
                        <?= e(time_ago($user['last_login_at'])) ?>
                        <?php if (!empty($user['last_login_ip'])): ?>
                            <div class="text-sm"><?= e($user['last_login_ip']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="status status-<?= e($user['status']) ?>"><?= e($user['status']) ?></span></td>
                    <td class="col-actions">
                        <?php if (can('user.update')): ?>
                            <button type="button" class="btn btn-sm"
                                    data-action="edit-user"
                                    data-user="<?= e_attr([
                                        'id' => (int) $user['id'],
                                        'name' => $user['name'],
                                        'role' => $user['role'],
                                        'status' => $user['status'],
                                    ]) ?>">Edit</button>
                        <?php endif; ?>
                        <?php if (can('user.delete') && (int) $user['id'] !== (int) ($auth_user['id'] ?? 0)): ?>
                            <form method="post" action="<?= e(url('settings/users/' . $user['id'] . '/delete')) ?>"
                                  class="inline" data-confirm="Remove <?= e($user['email']) ?>?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-ghost">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= \App\Core\View::partial('partials.pagination', ['result' => $result]) ?>
</section>

<?php if (can('user.create')): ?>
<section class="card">
    <header class="card-header"><h2>Invite a user</h2></header>

    <form method="post" action="<?= e(url('settings/users')) ?>" class="form" data-password-meter>
        <?= csrf_field() ?>

        <div class="grid-2">
            <div class="field">
                <label for="new_name">Name</label>
                <input type="text" id="new_name" name="name" required maxlength="120" value="<?= e(old('name')) ?>">
            </div>
            <div class="field">
                <label for="new_email">Email address</label>
                <input type="email" id="new_email" name="email" required value="<?= e(old('email')) ?>">
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="new_password">Temporary password</label>
                <input type="password" id="new_password" name="password" required
                       autocomplete="new-password" data-password-input>
                <div class="strength-meter" data-strength-meter hidden>
                    <div class="strength-bar"><span data-strength-fill></span></div>
                    <p class="strength-label" data-strength-label></p>
                </div>
            </div>
            <div class="field">
                <label for="new_role">Role</label>
                <select id="new_role" name="role" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>"><?= e(\App\Core\Rbac::label($role)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Add user</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <header class="card-header">
        <h2>What each role can do</h2>
        <p class="text-muted">Permissions are fixed in code, not editable — a tenant cannot grant itself more.</p>
    </header>

    <div class="table-wrap">
        <table class="table table-matrix">
            <thead>
            <tr>
                <th scope="col">Permission</th>
                <?php foreach ($roles as $role): ?>
                    <th scope="col"><?= e(\App\Core\Rbac::label($role)) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($catalogue as $group => $permissions): ?>
                <?php if ($group === 'Platform') { continue; } ?>
                <tr class="row-group"><th scope="rowgroup" colspan="<?= count($roles) + 1 ?>"><?= e($group) ?></th></tr>
                <?php foreach ($permissions as $permission): ?>
                    <tr>
                        <td><code><?= e($permission) ?></code></td>
                        <?php foreach ($roles as $role): ?>
                            <td class="cell-center">
                                <?= \App\Core\Rbac::roleHas($role, $permission)
                                    ? '<span class="tick" title="allowed">✓</span>'
                                    : '<span class="text-muted" title="not allowed">—</span>' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog id="user-dialog" class="dialog">
    <form method="post" id="user-form" class="form">
        <?= csrf_field() ?>
        <h2>Edit user</h2>

        <div class="field">
            <label for="edit_name">Name</label>
            <input type="text" id="edit_name" name="name" required maxlength="120">
        </div>

        <div class="field">
            <label for="edit_role">Role</label>
            <select id="edit_role" name="role" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role) ?>"><?= e(\App\Core\Rbac::label($role)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="edit_status">Status</label>
            <select id="edit_status" name="status">
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn btn-ghost" data-action="close-dialog">Cancel</button>
        </div>
    </form>
</dialog>
