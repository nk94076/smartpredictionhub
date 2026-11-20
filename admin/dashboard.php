<?php
require __DIR__ . '/includes/auth.php';
require_admin();
close_stale_user_sessions();

$one = fn(string $sql, array $p = []) => (int)q($sql, $p)->fetchColumn();

$totalUsers  = $one('SELECT COUNT(*) FROM users');
$newToday    = $one('SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()');
$new7        = $one('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY');
$prev7       = $one('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 14 DAY AND created_at < NOW() - INTERVAL 7 DAY');
$loginsToday = $one('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE login_at >= CURDATE() AND impersonated_by IS NULL');
$onlineNow   = $one('SELECT COUNT(*) FROM users WHERE last_seen_at >= NOW() - INTERVAL ' . (int)ONLINE_WINDOW_MIN . ' MINUTE');
$delta       = $prev7 > 0 ? round(($new7 - $prev7) / $prev7 * 100) : ($new7 > 0 ? 100 : 0);

// Last 30 days registrations
$rows = q('SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)')->fetchAll(PDO::FETCH_KEY_PAIR);
$series = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $series[$d] = (int)($rows[$d] ?? 0);
}
$max = max(1, max($series));
$niceMax = $max <= 4 ? 4 : (int)(ceil($max / 4) * 4);

$countries = q('SELECT COALESCE(reg_country, \'Unknown\') label, reg_country_code cc, COUNT(*) c FROM users GROUP BY label, cc ORDER BY c DESC LIMIT 6')->fetchAll();
$sources   = q('SELECT COALESCE(reg_source, \'direct\') label, COUNT(*) c FROM users GROUP BY label ORDER BY c DESC LIMIT 6')->fetchAll();
$devices   = q('SELECT COALESCE(reg_device, \'Unknown\') label, COUNT(*) c FROM users GROUP BY label ORDER BY c DESC')->fetchAll();

$recentUsers  = can('users.view') ? q('SELECT id, name, email, reg_country, reg_country_code, reg_city, reg_source, reg_device, created_at FROM users ORDER BY id DESC LIMIT 6')->fetchAll() : [];
$recentLogins = can('sessions.view') ? q('SELECT s.*, u.name, u.email FROM user_sessions s JOIN users u ON u.id = s.user_id ORDER BY s.id DESC LIMIT 7')->fetchAll() : [];

$me = current_admin();
layout_start('Dashboard', 'dashboard', 'Welcome back, <strong>' . e($me['name'] ?: $me['username']) . '</strong> 👋 &nbsp;·&nbsp; ' . e(date('l, d M Y')));

function hbars(array $rows, int $total, string $cls = '', bool $flags = false): void
{
    if (!$rows) { echo '<p class="muted small">No data yet.</p>'; return; }
    echo '<div class="hbar">';
    foreach ($rows as $r) {
        $pct = $total > 0 ? round($r['c'] / $total * 100) : 0;
        $label = ($flags ? flag($r['cc'] ?? null) . ' ' : '') . e(ucfirst($r['label']));
        echo '<div class="hbar-row"><span>' . $label . '</span><span class="muted">' . number_format($r['c']) . ' · ' . $pct . '%</span>'
           . '<div class="hbar-track"><div class="hbar-fill ' . $cls . '" style="width:' . $pct . '%"></div></div></div>';
    }
    echo '</div>';
}
?>
<div class="stats">
    <div class="card stat"><div class="stat-ic si-purple"><?= icon('users', 22) ?></div><div><div class="stat-label">Total users</div><div class="stat-value"><?= number_format($totalUsers) ?></div><div class="stat-delta muted">All time</div></div></div>
    <div class="card stat"><div class="stat-ic si-green"><?= icon('user-plus', 22) ?></div><div><div class="stat-label">New today</div><div class="stat-value"><?= number_format($newToday) ?></div>
        <div class="stat-delta <?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>% <span class="muted">7d vs prev 7d</span></div></div></div>
    <div class="card stat"><div class="stat-ic si-blue"><?= icon('login', 22) ?></div><div><div class="stat-label">Users logged in today</div><div class="stat-value"><?= number_format($loginsToday) ?></div><div class="stat-delta muted">Unique users</div></div></div>
    <div class="card stat"><div class="stat-ic si-amber"><?= icon('radio', 22) ?></div><div><div class="stat-label">Online now</div><div class="stat-value"><span class="live-dot"></span><?= number_format($onlineNow) ?></div><div class="stat-delta muted">Last <?= (int)ONLINE_WINDOW_MIN ?> min active</div></div></div>
</div>

<div class="grid grid-main mb">
    <div class="card">
        <div class="card-head"><h2>Registrations · last 30 days</h2><span class="badge b-purple"><?= number_format(array_sum($series)) ?> users</span></div>
        <div class="card-body">
            <svg class="chart" viewBox="0 0 720 220" preserveAspectRatio="none" role="img" aria-label="Registrations chart">
                <?php for ($g = 0; $g <= 4; $g++): $y = 10 + $g * 45; ?>
                    <line class="gl" x1="34" x2="720" y1="<?= $y ?>" y2="<?= $y ?>"/>
                    <text x="28" y="<?= $y + 3 ?>" text-anchor="end"><?= (int)round($niceMax - $g * $niceMax / 4) ?></text>
                <?php endfor; ?>
                <?php $i = 0; $bw = 686 / 30; foreach ($series as $d => $c):
                    $h = $c / $niceMax * 180; $x = 34 + $i * $bw + 3; ?>
                    <rect class="bar" x="<?= round($x, 1) ?>" y="<?= round(190 - $h, 1) ?>" width="<?= round($bw - 6, 1) ?>" height="<?= round(max($h, $c ? 2 : 0), 1) ?>" rx="3"><title><?= e(date('d M', strtotime($d))) ?>: <?= $c ?> users</title></rect>
                    <?php if ($i % 5 === 0 || $i === 29): ?><text x="<?= round($x + ($bw - 6) / 2, 1) ?>" y="208" text-anchor="middle"><?= e(date('d M', strtotime($d))) ?></text><?php endif; ?>
                <?php $i++; endforeach; ?>
            </svg>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Top countries</h2><?= icon('globe', 18, 'muted') ?></div>
        <div class="card-body"><?php hbars($countries, $totalUsers, '', true); ?></div>
    </div>
</div>

<div class="grid grid-2 mb">
    <div class="card">
        <div class="card-head"><h2>Traffic sources</h2><span class="muted small">Where users came from</span></div>
        <div class="card-body"><?php hbars($sources, $totalUsers, 'alt'); ?></div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Devices</h2><?= icon('monitor', 18, 'muted') ?></div>
        <div class="card-body"><?php hbars($devices, $totalUsers, 'green'); ?></div>
    </div>
</div>

<div class="grid grid-2">
    <?php if (can('users.view')): ?>
    <div class="card">
        <div class="card-head"><h2>Latest registrations</h2><a href="users.php" class="btn btn-soft btn-sm">View all</a></div>
        <div class="table-wrap"><table>
            <tbody>
            <?php if (!$recentUsers): ?><tr><td class="empty">No registrations yet.</td></tr><?php endif; ?>
            <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td><a class="u-cell" href="user-view.php?id=<?= (int)$u['id'] ?>"><span class="avatar"><?= e(initials($u['name'])) ?></span><span><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></span></a></td>
                    <td><?= flag($u['reg_country_code']) ?> <span class="small"><?= e(location_str($u['reg_city'], $u['reg_country'])) ?></span><span class="sub"><?= e(ucfirst($u['reg_source'] ?? 'direct')) ?> · <?= e($u['reg_device']) ?></span></td>
                    <td class="text-right small muted nowrap"><?= e(time_ago($u['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
    <?php if (can('sessions.view')): ?>
    <div class="card">
        <div class="card-head"><h2>Recent logins</h2><a href="sessions.php" class="btn btn-soft btn-sm">View logs</a></div>
        <div class="table-wrap"><table>
            <tbody>
            <?php if (!$recentLogins): ?><tr><td class="empty">No login activity yet.</td></tr><?php endif; ?>
            <?php foreach ($recentLogins as $s): ?>
                <tr>
                    <td><a class="u-cell" href="user-view.php?id=<?= (int)$s['user_id'] ?>"><span class="avatar"><?= e(initials($s['name'])) ?></span><span><strong><?= e($s['name']) ?></strong><small><?= e($s['ip']) ?> · <?= flag($s['country_code']) ?> <?= e($s['city'] ?: $s['country']) ?></small></span></a></td>
                    <td class="nowrap">
                        <?php if ($s['impersonated_by']): ?><span class="badge b-purple">Admin view</span>
                        <?php elseif (!$s['logout_at']): ?><span class="badge b-green b-dot">Active</span>
                        <?php else: ?><span class="badge b-gray">Logged out</span><?php endif; ?>
                    </td>
                    <td class="text-right small muted nowrap"><?= e(time_ago($s['login_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>
<?php layout_end();
