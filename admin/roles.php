<?php
require __DIR__ . '/includes/auth.php';
require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_post_csrf('roles.php');
    $rid = (int)($_POST['id'] ?? 0);
    $role = q('SELECT * FROM admin_roles WHERE id = ?', [$rid])->fetch();
    $inUse = (int)q('SELECT COUNT(*) FROM admin_users WHERE role_id = ? AND is_super = 0', [$rid])->fetchColumn();
    if (!$role) {
        flash('error', 'Role not found.');
    } elseif ($inUse) {
        flash('error', "This role is assigned to $inUse admin(s). Change their role first.");
    } else {
        q('DELETE FROM admin_role_permissions WHERE role_id = ?', [$rid]);
        q('DELETE FROM admin_roles WHERE id = ?', [$rid]);
        audit('role.delete', 'role', $rid, $role['name']);
        flash('success', 'Role deleted: ' . $role['name']);
    }
    redirect('roles.php');
}

$roles = q('SELECT r.*, (SELECT COUNT(*) FROM admin_users a WHERE a.role_id = r.id AND a.is_super = 0 AND a.perm_mode = \'role\') AS admins FROM admin_roles r ORDER BY r.name')->fetchAll();
$permsByRole = [];
foreach (q('SELECT role_id, perm FROM admin_role_permissions')->fetchAll() as $r) { $permsByRole[$r['role_id']][] = $r['perm']; }
$totalPerms = count(all_perm_keys());

layout_start('Roles & Permissions', 'roles', 'Create roles and decide what admins with each role can do',
    '<a class="btn btn-primary" href="role-edit.php">' . icon('plus', 16) . 'Create role</a>');
?>
<div class="alert alert-info"><?= icon('key', 16) ?><span><strong>How it works:</strong> Assign a role to each admin — that role's permissions apply to them.
    To give one admin different access, edit them under <a href="admins.php">Admins</a> and choose "Custom for this admin".</span></div>

<div class="grid grid-3">
    <div class="card">
        <div class="card-head"><h2><?= icon('crown', 16) ?> Super Admin</h2><span class="badge b-amber">System</span></div>
        <div class="card-body">
            <p class="muted small mb">All permissions plus managing admins and roles. This role cannot be edited.</p>
            <div class="chips"><span class="badge b-amber">All <?= $totalPerms ?> permissions</span><span class="badge b-amber">Manage admins</span><span class="badge b-amber">Manage roles</span></div>
        </div>
    </div>
    <?php foreach ($roles as $r): $p = $permsByRole[$r['id']] ?? []; ?>
        <div class="card">
            <div class="card-head"><h2><?= e($r['name']) ?></h2><span class="badge b-gray"><?= (int)$r['admins'] ?> admin<?= $r['admins'] == 1 ? '' : 's' ?></span></div>
            <div class="card-body">
                <p class="muted small mb"><?= e($r['description'] ?: '—') ?></p>
                <div class="chips mb">
                    <?php if (!$p): ?><span class="badge b-gray">No permissions</span><?php endif; ?>
                    <?php foreach ($p as $perm): ?><span class="badge b-purple"><?= e(perm_label($perm)) ?></span><?php endforeach; ?>
                </div>
                <div class="actions" style="justify-content:flex-start">
                    <a class="btn btn-light btn-sm" href="role-edit.php?id=<?= (int)$r['id'] ?>"><?= icon('edit', 14) ?>Edit permissions</a>
                    <form method="post" class="inline" data-confirm="Role '<?= e($r['name']) ?>' — delete this role?"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-danger btn-sm btn-icon" title="Delete role"><?= icon('trash', 14) ?></button></form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php layout_end();
