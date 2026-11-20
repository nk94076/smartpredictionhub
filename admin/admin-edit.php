<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/perm_matrix.php';
require_super();
$me = current_admin();

$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;
$admin = $editing ? q('SELECT * FROM admin_users WHERE id = ?', [$id])->fetch() : null;
if ($editing && !$admin) {
    flash('error', 'Admin not found.');
    redirect('admins.php');
}
$isMe = $editing && (int)$admin['id'] === (int)$me['id'];

$roles = q('SELECT id, name, description FROM admin_roles ORDER BY name')->fetchAll();
$rolePerms = [];
foreach (q('SELECT role_id, perm FROM admin_role_permissions')->fetchAll() as $r) {
    $rolePerms[$r['role_id']][] = $r['perm'];
}

// Form defaults
$v = [
    'name' => $admin['name'] ?? '', 'username' => $admin['username'] ?? '', 'email' => $admin['email'] ?? '',
    'admin_type' => !empty($admin['is_super']) ? 'super' : 'admin',
    'role_id' => (int)($admin['role_id'] ?? ($roles[0]['id'] ?? 0)),
    'perm_mode' => $admin['perm_mode'] ?? 'role',
    'is_active' => $editing ? (int)$admin['is_active'] : 1,
];
$checked = $editing && $admin['perm_mode'] === 'custom'
    ? q('SELECT perm FROM admin_user_permissions WHERE admin_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN)
    : ($rolePerms[$v['role_id']] ?? []);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Form expired. Please submit again.'; }

    $v['name'] = trim((string)($_POST['name'] ?? ''));
    $v['username'] = trim((string)($_POST['username'] ?? ''));
    $v['email'] = trim((string)($_POST['email'] ?? ''));
    $v['admin_type'] = ($_POST['admin_type'] ?? '') === 'super' ? 'super' : 'admin';
    $v['role_id'] = (int)($_POST['role_id'] ?? 0);
    $v['perm_mode'] = ($_POST['perm_mode'] ?? '') === 'custom' ? 'custom' : 'role';
    $v['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $checked = sanitize_perms($_POST['perms'] ?? []);
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    // You cannot change your own role / status (prevents lock-out)
    if ($isMe) { $v['admin_type'] = 'super'; $v['is_active'] = 1; }

    if ($v['name'] === '' || mb_strlen($v['name']) > 100) { $errors[] = 'Please enter a name (max 100 characters).'; }
    if (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $v['username'])) { $errors[] = 'Username must be 4–50 characters: letters, numbers, _ . -'; }
    elseif (q('SELECT COUNT(*) FROM admin_users WHERE username = ? AND id <> ?', [$v['username'], $id])->fetchColumn()) { $errors[] = 'This username is already taken.'; }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
    if ($v['admin_type'] === 'admin' && $v['perm_mode'] === 'role' && !isset($rolePerms[$v['role_id']]) && !in_array($v['role_id'], array_column($roles, 'id'))) {
        $errors[] = 'Please select a role.';
    }
    if (!$editing || $pass !== '') {
        if (strlen($pass) < 10 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
            $errors[] = 'Password must be at least 10 characters with a letter, a number and a symbol.';
        } elseif ($pass !== $pass2) {
            $errors[] = 'Passwords do not match.';
        }
    }
    // Never allow the last Super Admin to be demoted or disabled
    if ($editing && $admin['is_super'] && ($v['admin_type'] !== 'super' || !$v['is_active'])) {
        $others = (int)q('SELECT COUNT(*) FROM admin_users WHERE is_super = 1 AND is_active = 1 AND id <> ?', [$id])->fetchColumn();
        if ($others === 0) { $errors[] = 'This is the only active Super Admin — it cannot be demoted or disabled.'; }
    }

    if (!$errors) {
        $isSuper = $v['admin_type'] === 'super' ? 1 : 0;
        $roleId = $isSuper ? null : ($v['role_id'] ?: null);
        $pdo = db();
        $pdo->beginTransaction();
        if ($editing) {
            q('UPDATE admin_users SET name = ?, username = ?, email = ?, is_super = ?, role_id = ?, perm_mode = ?, is_active = ? WHERE id = ?',
                [$v['name'], $v['username'], $v['email'] ?: null, $isSuper, $roleId, $v['perm_mode'], $v['is_active'], $id]);
            if ($pass !== '') {
                q('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
            }
            if (!$v['is_active'] && $admin['is_active']) {
                q('UPDATE admin_users SET session_version = session_version + 1 WHERE id = ?', [$id]);
            }
        } else {
            q('INSERT INTO admin_users (name, username, email, password_hash, is_super, role_id, perm_mode, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$v['name'], $v['username'], $v['email'] ?: null, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $isSuper, $roleId, $v['perm_mode'], $v['is_active'], $me['id']]);
            $id = (int)$pdo->lastInsertId();
        }
        q('DELETE FROM admin_user_permissions WHERE admin_id = ?', [$id]);
        if (!$isSuper && $v['perm_mode'] === 'custom') {
            foreach ($checked as $p) { q('INSERT INTO admin_user_permissions (admin_id, perm) VALUES (?, ?)', [$id, $p]); }
        }
        $pdo->commit();

        // Keep your own session valid after changing your own password
        if ($isMe && $pass !== '') {
            $_SESSION['sv'] = (int)q('SELECT session_version FROM admin_users WHERE id = ?', [$id])->fetchColumn();
        }

        $desc = $v['username'] . ' · ' . ($isSuper ? 'Super Admin' : ($v['perm_mode'] === 'custom' ? 'custom: ' . implode(', ', $checked) : 'role #' . $roleId));
        audit($editing ? 'admin.update' : 'admin.create', 'admin', $id, $desc);
        flash('success', $editing ? 'Admin updated.' : 'New admin created: ' . $v['username']);
        redirect('admins.php');
    }
}

layout_start($editing ? 'Edit admin' : 'Create admin', 'admins', '<a href="admins.php">Admins</a> / ' . ($editing ? e($admin['username']) : 'New'));
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($er) ?></span></div><?php endforeach; ?>

<form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?>
    <script type="application/json" id="rolePermsData"><?= json_encode((object)$rolePerms, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <div class="grid grid-main">
        <div>
            <div class="card mb">
                <div class="card-head"><h2>Account details</h2></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="field"><label class="lbl">Full name</label><input type="text" name="name" maxlength="100" required value="<?= e($v['name']) ?>"></div>
                        <div class="field"><label class="lbl">Username</label><input type="text" name="username" maxlength="50" required value="<?= e($v['username']) ?>"><p class="hint">Used to sign in</p></div>
                    </div>
                    <div class="field"><label class="lbl">Email</label><input type="email" name="email" maxlength="190" value="<?= e($v['email']) ?>"></div>
                    <div class="form-row">
                        <div class="field"><label class="lbl">Password <?= $editing ? '<span class="muted">(leave blank to keep current)</span>' : '' ?></label><input type="password" name="password" autocomplete="new-password" <?= $editing ? '' : 'required' ?>><p class="hint">10+ characters with a letter, number and symbol</p></div>
                        <div class="field"><label class="lbl">Confirm password</label><input type="password" name="password2" autocomplete="new-password" <?= $editing ? '' : 'required' ?>></div>
                    </div>
                    <?php if (!$isMe): ?>
                        <label class="switch"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> Account active</label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Permissions</h2><span class="muted small">Editable in custom mode only</span></div>
                <div class="card-body">
                    <div class="radio-cards mb">
                        <label class="radio-card"><input type="radio" name="perm_mode" value="role" <?= $v['perm_mode'] === 'role' ? 'checked' : '' ?>>
                            <strong>Use role permissions</strong><small>Gets exactly the role's permissions. Changes when the role changes.</small></label>
                        <label class="radio-card"><input type="radio" name="perm_mode" value="custom" <?= $v['perm_mode'] === 'custom' ? 'checked' : '' ?>>
                            <strong>Custom for this admin</strong><small>Choose specific permissions for this admin only.</small></label>
                    </div>
                    <div id="permBox"><?php render_perm_matrix($checked); ?></div>
                </div>
            </div>
        </div>

        <div>
            <div class="card mb">
                <div class="card-head"><h2>Admin type</h2></div>
                <div class="card-body">
                    <?php if ($isMe): ?>
                        <input type="hidden" name="admin_type" value="super">
                        <div class="alert alert-info"><?= icon('crown', 16) ?><span>You are a Super Admin. You cannot change your own role.</span></div>
                    <?php else: ?>
                        <label class="radio-card mb"><input type="radio" name="admin_type" value="admin" <?= $v['admin_type'] === 'admin' ? 'checked' : '' ?>>
                            <strong>Admin</strong><small>Access limited to granted permissions</small></label>
                        <label class="radio-card"><input type="radio" name="admin_type" value="super" <?= $v['admin_type'] === 'super' ? 'checked' : '' ?>>
                            <strong><?= icon('crown', 13) ?> Super Admin</strong><small>Full access, including managing admins and roles</small></label>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mb" id="roleBox">
                <div class="card-head"><h2>Role</h2><a class="small" href="roles.php">Manage roles</a></div>
                <div class="card-body">
                    <select name="role_id" id="roleSelect" class="input">
                        <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $v['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                    </select>
                    <p class="hint">In "Use role permissions" mode, this role's permissions apply.</p>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= icon('check', 16) ?><?= $editing ? 'Save changes' : 'Create admin' ?></button>
            <a href="admins.php" class="btn btn-light btn-block" style="margin-top:10px">Cancel</a>
        </div>
    </div>
</form>
<?php layout_end();
