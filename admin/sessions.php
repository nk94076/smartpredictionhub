<?php
require __DIR__ . '/includes/auth.php';
require_perm('sessions.view');
close_stale_user_sessions();

$fq    = trim((string)($_GET['q'] ?? ''));
$fType = in_array($_GET['type'] ?? '', ['active', 'ended', 'admin'], true) ? $_GET['type'] : '';
$fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
$fTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '';

$where = [];
$params = [];
if ($fq !== '') {
    $like = '%' . addcslashes($fq, '%_\\') . '%';
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR s.ip = ?)';
    array_push($params, $like, $like, $fq);
}
if ($fType === 'active') { $where[] = 's.logout_at IS NULL'; }
if ($fType === 'ended') { $where[] = 's.logout_at IS NOT NULL'; }
if ($fType === 'admin') { $where[] = 's.impersonated_by IS NOT NULL'; }
if ($fFrom) { $where[] = 's.login_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo) { $where[] = 's.login_at <= ?'; $params[] = $fTo . ' 23:59:59'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)q("SELECT COUNT(*) FROM user_sessions s JOIN users u ON u.id = s.user_id $w", $params)->fetchColumn();
$pg = paginate($total, 30);
$rows = q("SELECT s.*, u.name, u.email, a.username AS admin_username FROM user_sessions s
           JOIN users u ON u.id = s.user_id LEFT JOIN admin_users a ON a.id = s.impersonated_by
           $w ORDER BY s.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();

$activeNow = (int)q('SELECT COUNT(*) FROM user_sessions WHERE logout_at IS NULL')->fetchColumn();
$today = (int)q('SELECT COUNT(*) FROM user_sessions WHERE login_at >= CURDATE()')->fetchColumn();
$failedToday = (int)q('SELECT COUNT(*) FROM user_login_attempts WHERE success = 0 AND created_at >= CURDATE()')->fetchColumn();
$avg = (int)q('SELECT COALESCE(AVG(TIMESTAMPDIFF(SECOND, login_at, logout_at)), 0) FROM user_sessions WHERE logout_at IS NOT NULL AND impersonated_by IS NULL AND login_at >= NOW() - INTERVAL 30 DAY')->fetchColumn();

layout_start('Login Logs', 'sessions', 'When users signed in and out — with IP, location and device');
?>
<div class="stats">
    <div class="card stat"><div class="stat-ic si-green"><?= icon('radio', 20) ?></div><div><div class="stat-label">Active sessions</div><div class="stat-value"><?= $activeNow ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-purple"><?= icon('login', 20) ?></div><div><div class="stat-label">Logins today</div><div class="stat-value"><?= $today ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-red"><?= icon('x', 20) ?></div><div><div class="stat-label">Failed logins today</div><div class="stat-value"><?= $failedToday ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-blue"><?= icon('clock', 20) ?></div><div><div class="stat-label">Avg session (30d)</div><div class="stat-value" style="font-size:22px"><?= e(fmt_duration($avg)) ?></div></div></div>
</div>

<div class="card">
    <form class="filters" method="get">
        <div class="search"><?= icon('search', 16) ?><input type="text" name="q" value="<?= e($fq) ?>" placeholder="Search by user name, email or IP…"></div>
        <select name="type">
            <option value="">All sessions</option>
            <option value="active" <?= $fType === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="ended" <?= $fType === 'ended' ? 'selected' : '' ?>>Logged out</option>
            <option value="admin" <?= $fType === 'admin' ? 'selected' : '' ?>>Admin "Login as"</option>
        </select>
        <input type="date" name="from" value="<?= e($fFrom) ?>"><input type="date" name="to" value="<?= e($fTo) ?>">
        <button class="btn btn-primary" type="submit"><?= icon('filter', 15) ?>Filter</button>
        <?php if ($fq || $fType || $fFrom || $fTo): ?><a class="btn btn-light" href="sessions.php">Reset</a><?php endif; ?>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>User</th><th>Login</th><th>Logout</th><th>Duration</th><th>IP / Location</th><th>Device</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty">No sessions found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $s):
                $dur = max(0, strtotime($s['logout_at'] ?: $s['last_seen_at']) - strtotime($s['login_at'])); ?>
                <tr>
                    <td><a class="u-cell" href="user-view.php?id=<?= (int)$s['user_id'] ?>"><span class="avatar"><?= e(initials($s['name'])) ?></span><span><strong><?= e($s['name']) ?></strong><small><?= e($s['email']) ?></small></span></a></td>
                    <td class="nowrap"><?= e(fmt_dt($s['login_at'], 'd M Y')) ?><span class="sub"><?= e(fmt_dt($s['login_at'], 'h:i:s A')) ?></span></td>
                    <td class="nowrap"><?php if ($s['logout_at']): ?><?= e(fmt_dt($s['logout_at'], 'd M Y')) ?><span class="sub"><?= e(fmt_dt($s['logout_at'], 'h:i:s A')) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td class="nowrap"><?= e(fmt_duration($dur)) ?></td>
                    <td class="nowrap"><span class="mono"><?= e($s['ip']) ?></span><span class="sub"><?= flag($s['country_code']) ?> <?= e(location_str($s['city'], $s['country'])) ?></span></td>
                    <td class="nowrap"><span class="dev"><?= device_icon($s['device']) ?><?= e($s['browser']) ?></span><span class="sub"><?= e($s['os']) ?></span></td>
                    <td class="nowrap">
                        <?php if ($s['impersonated_by']): ?><span class="badge b-purple">Admin: <?= e($s['admin_username'] ?? '#' . $s['impersonated_by']) ?></span><?php endif; ?>
                        <?php if (!$s['logout_at']): ?><span class="badge b-green b-dot">Active</span>
                        <?php else: ?><span class="badge <?= $s['logout_type'] === 'forced' ? 'b-red' : 'b-gray' ?>"><?= e(['manual' => 'Logged out', 'expired' => 'Expired', 'forced' => 'Forced', 'admin_exit' => 'Admin exited'][$s['logout_type']] ?? 'Ended') ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pg) ?>
</div>
<?php layout_end();
