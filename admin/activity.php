<?php
require __DIR__ . '/includes/auth.php';
require_perm('audit.view');

$tab = ($_GET['tab'] ?? '') === 'logins' ? 'logins' : 'actions';
$fAdmin = (int)($_GET['admin'] ?? 0);
$fq = trim((string)($_GET['q'] ?? ''));

$admins = q('SELECT id, username FROM admin_users ORDER BY username')->fetchAll();

if ($tab === 'actions') {
    $where = []; $params = [];
    if ($fAdmin) { $where[] = 'l.admin_id = ?'; $params[] = $fAdmin; }
    if ($fq !== '') { $like = '%' . addcslashes($fq, '%_\\') . '%'; $where[] = '(l.action LIKE ? OR l.details LIKE ? OR l.ip = ?)'; array_push($params, $like, $like, $fq); }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $pg = paginate((int)q("SELECT COUNT(*) FROM admin_activity_log l $w", $params)->fetchColumn(), 30);
    $rows = q("SELECT l.* FROM admin_activity_log l $w ORDER BY l.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
} else {
    $where = []; $params = [];
    if ($fq !== '') { $where[] = '(username = ? OR ip = ?)'; array_push($params, $fq, $fq); }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $pg = paginate((int)q("SELECT COUNT(*) FROM admin_login_log $w", $params)->fetchColumn(), 30);
    $rows = q("SELECT * FROM admin_login_log $w ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
}

$labels = [
    'auth.login' => ['Logged in', 'b-green'], 'auth.logout' => ['Logged out', 'b-gray'], 'auth.session_expired' => ['Session expired', 'b-gray'],
    'user.impersonate' => ['Login as user', 'b-purple'], 'user.block' => ['Blocked user', 'b-red'], 'user.unblock' => ['Unblocked user', 'b-green'],
    'user.force_logout' => ['Force logout user', 'b-amber'], 'user.delete' => ['Deleted user', 'b-red'], 'users.export' => ['Exported users', 'b-blue'],
    'admin.create' => ['Created admin', 'b-purple'], 'admin.update' => ['Updated admin', 'b-purple'], 'admin.delete' => ['Deleted admin', 'b-red'],
    'admin.activate' => ['Enabled admin', 'b-green'], 'admin.deactivate' => ['Disabled admin', 'b-red'], 'admin.force_logout' => ['Force logout admin', 'b-amber'],
    'role.create' => ['Created role', 'b-purple'], 'role.update' => ['Updated role', 'b-purple'], 'role.delete' => ['Deleted role', 'b-red'],
    'ip.unblock' => ['Unblocked IP', 'b-green'], 'profile.update' => ['Updated profile', 'b-blue'], 'profile.password' => ['Changed password', 'b-amber'],
];

layout_start('Activity Log', 'activity', 'Every action taken by every admin is recorded here');
?>
<div class="card">
    <div class="filters">
        <a class="btn <?= $tab === 'actions' ? 'btn-primary' : 'btn-light' ?>" href="?tab=actions"><?= icon('list', 15) ?>Admin actions</a>
        <a class="btn <?= $tab === 'logins' ? 'btn-primary' : 'btn-light' ?>" href="?tab=logins"><?= icon('login', 15) ?>Admin login attempts</a>
    </div>
    <form class="filters" method="get">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="search"><?= icon('search', 16) ?><input type="text" name="q" value="<?= e($fq) ?>" placeholder="<?= $tab === 'actions' ? 'Action, details or IP…' : 'Exact username or IP…' ?>"></div>
        <?php if ($tab === 'actions'): ?>
        <select name="admin"><option value="0">All admins</option>
            <?php foreach ($admins as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $fAdmin === (int)$a['id'] ? 'selected' : '' ?>>@<?= e($a['username']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= icon('filter', 15) ?>Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <?php if ($tab === 'actions'): ?>
                <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="6" class="empty">No activity found.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): [$lbl, $cls] = $labels[$r['action']] ?? [$r['action'], 'b-gray']; ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_dt($r['created_at'], 'd M Y')) ?><span class="sub"><?= e(fmt_dt($r['created_at'], 'h:i:s A')) ?></span></td>
                        <td class="nowrap">@<?= e($r['admin_username'] ?? '—') ?></td>
                        <td class="nowrap"><span class="badge <?= $cls ?>"><?= e($lbl) ?></span></td>
                        <td class="nowrap"><?php if ($r['target_type'] === 'user' && $r['target_id']): ?><a href="user-view.php?id=<?= (int)$r['target_id'] ?>">User #<?= (int)$r['target_id'] ?></a>
                            <?php elseif ($r['target_type']): ?><?= e(ucfirst($r['target_type'])) ?><?= $r['target_id'] ? ' #' . e($r['target_id']) : '' ?><?php else: ?>—<?php endif; ?></td>
                        <td class="small"><?= e($r['details'] ?? '') ?></td>
                        <td class="mono nowrap"><?= e($r['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php else: ?>
                <thead><tr><th>Time</th><th>Username tried</th><th>Result</th><th>IP</th><th>Device</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="5" class="empty">No login attempts found.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): $ua = parse_ua($r['user_agent']); ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_dt($r['created_at'], 'd M Y, h:i:s A')) ?></td>
                        <td><?= e($r['username'] !== '' ? $r['username'] : '—') ?></td>
                        <td><?= $r['success'] ? '<span class="badge b-green">Success</span>' : '<span class="badge b-red">Failed</span>' ?></td>
                        <td class="mono"><?= e($r['ip']) ?></td>
                        <td class="nowrap"><span class="dev"><?= device_icon($ua['device']) ?><?= e($ua['browser'] . ' · ' . $ua['os']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endif; ?>
        </table>
    </div>
    <?= render_pagination($pg) ?>
</div>
<?php layout_end();
