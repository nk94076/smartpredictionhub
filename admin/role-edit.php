<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/perm_matrix.php';
require_super();

$id = (int)($_GET['id'] ?? 0);
$role = $id ? q('SELECT * FROM admin_roles WHERE id = ?', [$id])->fetch() : null;
if ($id && !$role) {
    flash('error', 'Role not found.');
    redirect('roles.php');
}
$name = $role['name'] ?? '';
$desc = $role['description'] ?? '';
$checked = $id ? q('SELECT perm FROM admin_role_permissions WHERE role_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Form expired. Please submit again.'; }
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $checked = sanitize_perms($_POST['perms'] ?? []);

    if ($name === '' || mb_strlen($name) > 60) { $errors[] = 'Please enter a role name (max 60 characters).'; }
    elseif (q('SELECT COUNT(*) FROM admin_roles WHERE name = ? AND id <> ?', [$name, $id])->fetchColumn()) { $errors[] = 'A role with this name already exists.'; }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        if ($id) {
            q('UPDATE admin_roles SET name = ?, description = ? WHERE id = ?', [$name, mb_substr($desc, 0, 255) ?: null, $id]);
        } else {
            q('INSERT INTO admin_roles (name, description) VALUES (?, ?)', [$name, mb_substr($desc, 0, 255) ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
        q('DELETE FROM admin_role_permissions WHERE role_id = ?', [$id]);
        foreach ($checked as $p) { q('INSERT INTO admin_role_permissions (role_id, perm) VALUES (?, ?)', [$id, $p]); }
        $pdo->commit();
        audit($role ? 'role.update' : 'role.create', 'role', $id, $name . ': ' . implode(', ', $checked));
        flash('success', "Role '$name' saved. Changes apply immediately to every admin with this role.");
        redirect('roles.php');
    }
}

layout_start($role ? 'Edit role' : 'Create role', 'roles', '<a href="roles.php">Roles</a> / ' . e($role['name'] ?? 'New'));
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($er) ?></span></div><?php endforeach; ?>
<form method="post" class="form">
    <?= csrf_field() ?>
    <div class="grid grid-main">
        <div class="card">
            <div class="card-head"><h2>Permissions</h2><span class="muted small">What admins with this role can do</span></div>
            <div class="card-body"><?php render_perm_matrix($checked); ?></div>
        </div>
        <div>
            <div class="card mb">
                <div class="card-head"><h2>Role details</h2></div>
                <div class="card-body">
                    <div class="field"><label class="lbl">Role name</label><input type="text" name="name" maxlength="60" required value="<?= e($name) ?>" placeholder="e.g. Support Team"></div>
                    <div class="field"><label class="lbl">Description</label><textarea name="description" rows="3" maxlength="255"><?= e($desc) ?></textarea></div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= icon('check', 16) ?>Save role</button>
            <a href="roles.php" class="btn btn-light btn-block" style="margin-top:10px">Cancel</a>
        </div>
    </div>
</form>
<?php layout_end();
