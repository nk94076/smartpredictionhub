<?php
require __DIR__ . '/includes/auth.php';
require_admin();
$me = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('profile.php');
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            flash('error', 'Please enter your name.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
        } else {
            q('UPDATE admin_users SET name = ?, email = ? WHERE id = ?', [$name, $email ?: null, $me['id']]);
            audit('profile.update', 'admin', $me['id']);
            flash('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $new2 = (string)($_POST['new2'] ?? '');
        if (!password_verify($cur, $me['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)) {
            flash('error', 'New password must be at least 10 characters with a letter, a number and a symbol.');
        } elseif ($new !== $new2) {
            flash('error', 'New passwords do not match.');
        } else {
            // Password change = sign out every other device, keep this one signed in
            q('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $me['id']]);
            $_SESSION['sv'] = (int)q('SELECT session_version FROM admin_users WHERE id = ?', [$me['id']])->fetchColumn();
            session_regenerate_id(true);
            audit('profile.password', 'admin', $me['id'], 'Password changed, other sessions logged out');
            flash('success', 'Password changed. All other devices have been signed out.');
        }
    }
    redirect('profile.php');
}

$recent = q("SELECT * FROM admin_activity_log WHERE admin_id = ? AND action IN ('auth.login','auth.logout') ORDER BY id DESC LIMIT 8", [$me['id']])->fetchAll();
layout_start('My Profile', 'profile', 'Manage your details and password');
?>
<div class="grid grid-2 mb">
    <div class="card">
        <div class="card-head"><h2>Profile details</h2></div>
        <div class="card-body">
            <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="details">
                <div class="field"><label class="lbl">Full name</label><input type="text" name="name" maxlength="100" value="<?= e($me['name']) ?>" required></div>
                <div class="field"><label class="lbl">Email</label><input type="email" name="email" maxlength="190" value="<?= e($me['email']) ?>"></div>
                <div class="field"><label class="lbl">Username</label><input type="text" value="<?= e($me['username']) ?>" disabled><p class="hint">Only a Super Admin can change your username.</p></div>
                <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>Save</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Change password</h2><?= icon('lock', 18, 'muted') ?></div>
        <div class="card-body">
            <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="password">
                <div class="field"><label class="lbl">Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
                <div class="field"><label class="lbl">New password</label><input type="password" name="new" required autocomplete="new-password"><p class="hint">10+ chars, letter + number + symbol</p></div>
                <div class="field"><label class="lbl">Confirm new password</label><input type="password" name="new2" required autocomplete="new-password"></div>
                <button class="btn btn-primary" type="submit"><?= icon('key', 16) ?>Update password</button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>My access</h2><?= $me['is_super'] ? '<span class="badge b-amber">' . icon('crown', 12) . 'Super Admin</span>' : '<span class="badge b-purple">' . e($me['perm_mode'] === 'custom' ? 'Custom' : ($me['role_name'] ?: 'No role')) . '</span>' ?></div>
        <div class="card-body">
            <div class="chips">
                <?php if ($me['is_super']): ?><span class="badge b-amber">Full access + manage admins & roles</span><?php endif; ?>
                <?php if (!$me['is_super'] && !$me['perms']): ?><span class="muted">No permissions yet — dashboard only.</span><?php endif; ?>
                <?php if (!$me['is_super']): foreach ($me['perms'] as $p): ?><span class="badge b-purple"><?= e(perm_label($p)) ?></span><?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>My recent logins</h2></div>
        <div class="table-wrap"><table><tbody>
            <?php foreach ($recent as $r): ?>
                <tr><td><span class="badge <?= $r['action'] === 'auth.login' ? 'b-green' : 'b-gray' ?>"><?= $r['action'] === 'auth.login' ? 'Login' : 'Logout' ?></span></td>
                    <td class="mono"><?= e($r['ip']) ?></td><td class="text-right muted small"><?= e(fmt_dt($r['created_at'])) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
<?php layout_end();
