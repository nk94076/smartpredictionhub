<?php
require __DIR__ . '/includes/auth.php';
require_super();
$me = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('admins.php');
    $id = (int)($_POST['id'] ?? 0);
    $target = q('SELECT * FROM admin_users WHERE id = ?', [$id])->fetch();
    $action = $_POST['action'] ?? '';
    $activeSupers = (int)q('SELECT COUNT(*) FROM admin_users WHERE is_super = 1 AND is_active = 1')->fetchColumn();

    if (!$target) {
        flash('error', 'Admin not found.');
    } elseif ($id === (int)$me['id']) {
        flash('error', 'You cannot perform this action on your own account.');
    } elseif ($target['is_super'] && $target['is_active'] && $activeSupers <= 1 && in_array($action, ['delete', 'toggle'], true)) {
        flash('error', 'At least one active Super Admin is required.');
    } elseif ($action === 'toggle') {
        $new = $target['is_active'] ? 0 : 1;
        q('UPDATE admin_users SET is_active = ?, session_version = session_version + 1 WHERE id = ?', [$new, $id]);
        audit($new ? 'admin.activate' : 'admin.deactivate', 'admin', $id, $target['username']);
        flash('success', $target['username'] . ($new ? ' has been enabled.' : ' has been disabled and signed out.'));
    } elseif ($action === 'logout_all') {
        q('UPDATE admin_users SET session_version = session_version + 1 WHERE id = ?', [$id]);
        audit('admin.force_logout', 'admin', $id, $target['username']);
        flash('success', $target['username'] . ' — all sessions signed out.');
    } elseif ($action === 'delete') {
        q('DELETE FROM admin_user_permissions WHERE admin_id = ?', [$id]);
        q('DELETE FROM admin_users WHERE id = ?', [$id]);
        audit('admin.delete', 'admin', $id, $target['username']);
        flash('success', $target['username'] . ' has been deleted.');
    }
    redirect('admins.php');
}

$admins = q('SELECT a.*, r.name AS role_name, c.username AS creator,
                    (SELECT COUNT(*) FROM admin_user_permissions p WHERE p.admin_id = a.id) AS custom_count,
                    (SELECT COUNT(*) FROM admin_role_permissions rp WHERE rp.role_id = a.role_id) AS role_count
             FROM admin_users a LEFT JOIN admin_roles r ON r.id = a.role_id LEFT JOIN admin_users c ON c.id = a.created_by
             ORDER BY a.is_super DESC, a.id ASC')->fetchAll();
$totalPerms = count(all_perm_keys());

layout_start('Admins', 'admins', 'Create admin accounts, assign roles and control permissions',
    '<a class="btn btn-primary" href="admin-edit.php">' . icon('plus', 16) . 'Create admin</a>');
?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Admin</th><th>Role</th><th>Permissions</th><th>Status</th><th>Last login</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($admins as $a):
                $isMe = (int)$a['id'] === (int)$me['id'];
                $permCount = $a['is_super'] ? $totalPerms : ($a['perm_mode'] === 'custom' ? (int)$a['custom_count'] : (int)$a['role_count']); ?>
                <tr>
                    <td><div class="u-cell"><span class="avatar"><?= e(initials($a['name'] ?: $a['username'])) ?></span>
                        <span><strong><?= e($a['name'] ?: $a['username']) ?><?= $isMe ? ' <span class="badge b-gray">You</span>' : '' ?></strong><small>@<?= e($a['username']) ?><?= $a['email'] ? ' · ' . e($a['email']) : '' ?></small></span></div></td>
                    <td class="nowrap">
                        <?php if ($a['is_super']): ?><span class="badge b-amber"><?= icon('crown', 12) ?>Super Admin</span>
                        <?php else: ?><span class="badge b-purple"><?= e($a['role_name'] ?: 'No role') ?></span><?php endif; ?>
                    </td>
                    <td class="nowrap"><?= $a['is_super'] ? 'All' : $permCount . ' / ' . $totalPerms ?>
                        <?php if (!$a['is_super'] && $a['perm_mode'] === 'custom'): ?><span class="sub">Custom permissions</span><?php elseif (!$a['is_super']): ?><span class="sub">From role</span><?php endif; ?></td>
                    <td><?= $a['is_active'] ? '<span class="badge b-green b-dot">Active</span>' : '<span class="badge b-red b-dot">Disabled</span>' ?></td>
                    <td class="nowrap"><?= e(time_ago($a['last_login_at'])) ?><?php if ($a['last_login_ip']): ?><span class="sub mono"><?= e($a['last_login_ip']) ?></span><?php endif; ?></td>
                    <td class="nowrap"><?= e(fmt_dt($a['created_at'], 'd M Y')) ?><span class="sub"><?= $a['creator'] ? 'by @' . e($a['creator']) : '' ?></span></td>
                    <td>
                        <div class="actions">
                            <a class="btn btn-light btn-sm" href="admin-edit.php?id=<?= (int)$a['id'] ?>"><?= icon('edit', 14) ?>Edit</a>
                            <?php if (!$isMe): ?>
                                <form method="post" class="inline" data-confirm="<?= e($a['username']) ?> — sign out all sessions?"><?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="logout_all">
                                    <button class="btn btn-light btn-icon" title="Force logout"><?= icon('power', 15) ?></button></form>
                                <form method="post" class="inline" data-confirm="<?= e($a['username']) ?> — <?= $a['is_active'] ? 'disable' : 'enable' ?> this admin?"><?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="toggle">
                                    <button class="btn btn-light btn-icon" title="<?= $a['is_active'] ? 'Disable' : 'Enable' ?>"><?= icon($a['is_active'] ? 'lock' : 'unlock', 15) ?></button></form>
                                <form method="post" class="inline" data-confirm="<?= e($a['username']) ?> — permanently delete this admin?"><?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="delete">
                                    <button class="btn btn-danger btn-icon" title="Delete"><?= icon('trash', 15) ?></button></form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_end();
