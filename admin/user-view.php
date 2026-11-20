<?php
require __DIR__ . '/includes/auth.php';
require_perm('users.view');
close_stale_user_sessions();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$user = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if (!$user) {
    flash('error', 'User not found.');
    redirect('users.php');
}
$self = 'user-view.php?id=' . $id;

// ---------------- Actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf($self);
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['block', 'unblock', 'force_logout'], true) && !can('users.edit')) { deny_page(); }
    if ($action === 'delete' && !can('users.delete')) { deny_page(); }

    switch ($action) {
        case 'block':
            q("UPDATE users SET status = 'blocked' WHERE id = ?", [$id]);
            q("UPDATE user_sessions SET logout_at = NOW(), logout_type = 'forced' WHERE user_id = ? AND logout_at IS NULL", [$id]);
            audit('user.block', 'user', $id, $user['email']);
            flash('success', 'User blocked and all active sessions signed out.');
            break;
        case 'unblock':
            q("UPDATE users SET status = 'active' WHERE id = ?", [$id]);
            audit('user.unblock', 'user', $id, $user['email']);
            flash('success', 'User unblocked.');
            break;
        case 'force_logout':
            $n = q("UPDATE user_sessions SET logout_at = NOW(), logout_type = 'forced' WHERE user_id = ? AND logout_at IS NULL", [$id])->rowCount();
            audit('user.force_logout', 'user', $id, "$n session(s) ended");
            flash('success', "$n active session(s) signed out.");
            break;
        case 'delete':
            q('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('user.delete', 'user', $id, $user['name'] . ' <' . $user['email'] . '>');
            flash('success', 'User permanently deleted.');
            redirect('users.php');
    }
    redirect($self);
}

// ---------------- Data ----------------
$totalSessions = (int)q('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$id])->fetchColumn();
$pg = paginate($totalSessions, 15);
$sessions = q("SELECT s.*, a.username AS admin_username FROM user_sessions s LEFT JOIN admin_users a ON a.id = s.impersonated_by
               WHERE s.user_id = ? ORDER BY s.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", [$id])->fetchAll();
$activeSessions = (int)q('SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND logout_at IS NULL', [$id])->fetchColumn();
$totalTime = (int)q('SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, login_at, COALESCE(logout_at, last_seen_at))), 0) FROM user_sessions WHERE user_id = ? AND impersonated_by IS NULL', [$id])->fetchColumn();
$uniqueIps = (int)q('SELECT COUNT(DISTINCT ip) FROM user_sessions WHERE user_id = ?', [$id])->fetchColumn();
$online = $user['last_seen_at'] && strtotime($user['last_seen_at']) >= time() - ONLINE_WINDOW_MIN * 60;

layout_start($user['name'], 'users', '<a href="users.php">Users</a> / User details');
?>
<div class="card mb">
    <div class="profile-hero">
        <span class="avatar avatar-xl"><?= e(initials($user['name'])) ?></span>
        <div>
            <h2><?= e($user['name']) ?>
                <?= $user['status'] === 'active' ? '<span class="badge b-green b-dot">Active</span>' : '<span class="badge b-red b-dot">Blocked</span>' ?>
                <?php if ($online): ?><span class="badge b-green"><span class="live-dot"></span>Online</span><?php endif; ?>
            </h2>
            <div class="meta">
                <span><?= icon('mail', 14) ?><?= e($user['email']) ?></span>
                <?php if ($user['phone']): ?><span><?= icon('phone', 14) ?><?= e($user['phone']) ?></span><?php endif; ?>
                <span><?= icon('clock', 14) ?>Joined <?= e(fmt_dt($user['created_at'])) ?></span>
                <span>ID #<?= (int)$user['id'] ?></span>
            </div>
        </div>
        <div class="actions">
            <?php if (can('users.impersonate')): ?>
                <form method="post" action="impersonate.php" class="inline" data-confirm="Sign in as <?= e($user['name']) ?>? This action will be logged.">
                    <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $id ?>">
                    <button class="btn btn-primary" type="submit"><?= icon('login', 16) ?>Login as user</button>
                </form>
            <?php endif; ?>
            <?php if (can('users.edit')): ?>
                <?php if ($activeSessions): ?>
                <form method="post" class="inline" data-confirm="Sign out all active sessions for this user?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="force_logout">
                    <button class="btn btn-light" type="submit"><?= icon('power', 16) ?>Force logout</button>
                </form>
                <?php endif; ?>
                <form method="post" class="inline" data-confirm="<?= $user['status'] === 'active' ? 'Block this user? They will not be able to sign in.' : 'Unblock this user?' ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $user['status'] === 'active' ? 'block' : 'unblock' ?>">
                    <?php if ($user['status'] === 'active'): ?>
                        <button class="btn btn-light" type="submit"><?= icon('lock', 16) ?>Block</button>
                    <?php else: ?>
                        <button class="btn btn-success" type="submit"><?= icon('unlock', 16) ?>Unblock</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <?php if (can('users.delete')): ?>
                <form method="post" class="inline" data-confirm="Permanently delete this user? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                    <button class="btn btn-danger btn-icon" type="submit" title="Delete user"><?= icon('trash', 16) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="stats">
    <div class="card stat"><div class="stat-ic si-purple"><?= icon('login', 20) ?></div><div><div class="stat-label">Total logins</div><div class="stat-value"><?= (int)$user['login_count'] ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-green"><?= icon('clock', 20) ?></div><div><div class="stat-label">Total time on site</div><div class="stat-value"><?= e(fmt_duration($totalTime)) ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-blue"><?= icon('radio', 20) ?></div><div><div class="stat-label">Last seen</div><div class="stat-value" style="font-size:20px"><?= e(time_ago($user['last_seen_at'])) ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-amber"><?= icon('globe', 20) ?></div><div><div class="stat-label">Unique IPs used</div><div class="stat-value"><?= $uniqueIps ?></div></div></div>
</div>

<div class="grid grid-3 mb">
    <div class="card">
        <div class="card-head"><h2>Registration</h2><?= icon('user-plus', 18, 'muted') ?></div>
        <div class="card-body"><dl class="dl">
            <dt>Date & time</dt><dd><?= e(fmt_dt($user['created_at'], 'd M Y, h:i:s A')) ?></dd>
            <dt>IP address</dt><dd class="mono"><?= e($user['reg_ip'] ?? '—') ?></dd>
            <dt>Research updates</dt><dd><?= !empty($user['marketing_opt_in']) ? '<span class="badge b-green">Opted in</span>' : '<span class="badge b-gray">Not opted in</span>' ?></dd>
            <dt>Location</dt><dd><?= flag($user['reg_country_code']) ?> <?= e(implode(', ', array_filter([$user['reg_city'], $user['reg_region'], $user['reg_country']])) ?: 'Unknown') ?></dd>
        </dl></div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Traffic source</h2><?= icon('link', 18, 'muted') ?></div>
        <div class="card-body"><dl class="dl">
            <dt>Source</dt><dd><span class="badge b-blue"><?= e(ucfirst($user['reg_source'] ?? 'direct')) ?></span></dd>
            <dt>Medium</dt><dd><?= e($user['reg_medium'] ?? '—') ?></dd>
            <dt>Campaign</dt><dd><?= e($user['reg_campaign'] ?? '—') ?></dd>
            <dt>Referrer</dt><dd class="small"><?= e($user['reg_referrer'] ?? '—') ?></dd>
            <dt>Landing page</dt><dd class="small"><?= e($user['reg_landing_page'] ?? '—') ?></dd>
        </dl></div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Device</h2><?= device_icon($user['reg_device'], 18) ?></div>
        <div class="card-body"><dl class="dl">
            <dt>Device</dt><dd><?= e($user['reg_device'] ?? '—') ?></dd>
            <dt>Browser</dt><dd><?= e($user['reg_browser'] ?? '—') ?></dd>
            <dt>OS</dt><dd><?= e($user['reg_os'] ?? '—') ?></dd>
            <dt>Last login</dt><dd><?= e(fmt_dt($user['last_login_at'])) ?></dd>
            <dt>Last login IP</dt><dd class="mono"><?= e($user['last_login_ip'] ?? '—') ?></dd>
        </dl></div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Login / logout history</h2><span class="muted small"><?= $totalSessions ?> sessions · <?= $activeSessions ?> active</span></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Login time</th><th>Logout time</th><th>Duration</th><th>IP / Location</th><th>Device</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$sessions): ?><tr><td colspan="6" class="empty">No sign-ins yet.</td></tr><?php endif; ?>
            <?php foreach ($sessions as $s):
                $end = $s['logout_at'] ?: $s['last_seen_at'];
                $dur = strtotime($end) - strtotime($s['login_at']); ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_dt($s['login_at'])) ?></td>
                    <td class="nowrap"><?= $s['logout_at'] ? e(fmt_dt($s['logout_at'])) : '<span class="muted">—</span>' ?></td>
                    <td class="nowrap"><?= e(fmt_duration(max(0, $dur))) ?><?= $s['logout_at'] ? '' : '<span class="sub">so far</span>' ?></td>
                    <td class="nowrap"><span class="mono"><?= e($s['ip']) ?></span><span class="sub"><?= flag($s['country_code']) ?> <?= e(location_str($s['city'], $s['country'])) ?></span></td>
                    <td class="nowrap"><span class="dev"><?= device_icon($s['device']) ?><?= e($s['browser']) ?></span><span class="sub"><?= e($s['os']) ?></span></td>
                    <td class="nowrap">
                        <?php if ($s['impersonated_by']): ?><span class="badge b-purple">Admin: <?= e($s['admin_username'] ?? '#' . $s['impersonated_by']) ?></span><?php endif; ?>
                        <?php if (!$s['logout_at']): ?><span class="badge b-green b-dot">Active</span>
                        <?php else: ?><span class="badge <?= $s['logout_type'] === 'forced' ? 'b-red' : 'b-gray' ?>"><?= e(['manual' => 'Logged out', 'expired' => 'Expired', 'forced' => 'Forced logout', 'admin_exit' => 'Admin exited'][$s['logout_type']] ?? 'Ended') ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pg) ?>
</div>
<?php layout_end();
