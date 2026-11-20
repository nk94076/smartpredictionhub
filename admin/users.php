<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/user_filters.php';
require_perm('users.view');

[$f, $whereSql, $params, $order] = user_filters();

$total = (int)q("SELECT COUNT(*) FROM users u $whereSql", $params)->fetchColumn();
$pg = paginate($total, 25);
$users = q("SELECT u.* FROM users u $whereSql ORDER BY $order LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();

$countries = q("SELECT reg_country_code cc, MAX(reg_country) name, COUNT(*) c FROM users WHERE reg_country_code IS NOT NULL GROUP BY reg_country_code ORDER BY c DESC")->fetchAll();
$sources = q("SELECT reg_source s, COUNT(*) c FROM users WHERE reg_source IS NOT NULL GROUP BY reg_source ORDER BY c DESC")->fetchAll();
$onlineCut = time() - ONLINE_WINDOW_MIN * 60;

$actions = '';
if (can('users.export')) {
    $actions .= '<a class="btn btn-light" href="export-users.php?' . e(http_build_query(array_diff_key($_GET, ['page' => 1]))) . '">' . icon('download', 16) . 'Export CSV</a>';
}
layout_start('All Users', 'users', number_format($total) . ' users match', $actions);
?>
<div class="card">
    <form class="filters" method="get" action="users.php">
        <div class="search"><?= icon('search', 16) ?><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="Search by name, email, phone or IP…"></div>
        <select name="status">
            <option value="">All status</option>
            <option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="blocked" <?= $f['status'] === 'blocked' ? 'selected' : '' ?>>Blocked</option>
            <option value="online" <?= $f['status'] === 'online' ? 'selected' : '' ?>>Online now</option>
        </select>
        <select name="country">
            <option value="">All countries</option>
            <?php foreach ($countries as $c): ?><option value="<?= e($c['cc']) ?>" <?= $f['country'] === $c['cc'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= (int)$c['c'] ?>)</option><?php endforeach; ?>
        </select>
        <select name="source">
            <option value="">All sources</option>
            <?php foreach ($sources as $s): ?><option value="<?= e($s['s']) ?>" <?= $f['source'] === $s['s'] ? 'selected' : '' ?>><?= e(ucfirst($s['s'])) ?> (<?= (int)$s['c'] ?>)</option><?php endforeach; ?>
        </select>
        <select name="device">
            <option value="">All devices</option>
            <?php foreach (['Desktop', 'Mobile', 'Tablet'] as $d): ?><option <?= $f['device'] === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($f['from']) ?>" title="Registered from">
        <input type="date" name="to" value="<?= e($f['to']) ?>" title="Registered to">
        <select name="sort">
            <option value="newest">Newest first</option>
            <option value="oldest" <?= $f['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            <option value="last_login" <?= $f['sort'] === 'last_login' ? 'selected' : '' ?>>Recent login</option>
            <option value="logins" <?= $f['sort'] === 'logins' ? 'selected' : '' ?>>Most logins</option>
            <option value="name" <?= $f['sort'] === 'name' ? 'selected' : '' ?>>Name A–Z</option>
        </select>
        <button class="btn btn-primary" type="submit"><?= icon('filter', 15) ?>Filter</button>
        <?php if (array_filter(array_diff_key($f, ['sort' => 1]))): ?><a class="btn btn-light" href="users.php">Reset</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>User</th><th>Registered</th><th>Location</th><th>Source</th><th>Device</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            <?php if (!$users): ?><tr><td colspan="7" class="empty">No users found.</td></tr><?php endif; ?>
            <?php foreach ($users as $u):
                $online = $u['last_seen_at'] && strtotime($u['last_seen_at']) >= $onlineCut; ?>
                <tr>
                    <td><a class="u-cell" href="user-view.php?id=<?= (int)$u['id'] ?>"><span class="avatar"><?= e(initials($u['name'])) ?></span>
                        <span><strong><?= e($u['name']) ?><?= $u['status'] === 'blocked' ? ' <span class="badge b-red">Blocked</span>' : '' ?></strong><small><?= e($u['email']) ?></small></span></a></td>
                    <td class="nowrap"><?= e(fmt_dt($u['created_at'], 'd M Y')) ?><span class="sub"><?= e(fmt_dt($u['created_at'], 'h:i A')) ?></span></td>
                    <td class="nowrap"><?= flag($u['reg_country_code']) ?> <?= e(location_str($u['reg_city'], $u['reg_country'])) ?><span class="sub mono"><?= e($u['reg_ip']) ?></span></td>
                    <td class="nowrap"><span class="badge b-blue"><?= e(ucfirst($u['reg_source'] ?? 'direct')) ?></span><span class="sub"><?= e($u['reg_medium'] ?? '') ?><?= $u['reg_campaign'] ? ' · ' . e($u['reg_campaign']) : '' ?></span></td>
                    <td class="nowrap"><span class="dev"><?= device_icon($u['reg_device']) ?><?= e($u['reg_device'] ?? '—') ?></span><span class="sub"><?= e(($u['reg_browser'] ?? '') . ' · ' . ($u['reg_os'] ?? '')) ?></span></td>
                    <td class="nowrap"><?= e(time_ago($u['last_login_at'])) ?><span class="sub"><?php if ($online): ?><span class="live-dot"></span>Online · <?php endif; ?><?= (int)$u['login_count'] ?> logins</span></td>
                    <td>
                        <div class="actions">
                            <a class="btn btn-light btn-icon" href="user-view.php?id=<?= (int)$u['id'] ?>" title="View details"><?= icon('eye', 16) ?></a>
                            <?php if (can('users.impersonate')): ?>
                                <form method="post" action="impersonate.php" class="inline" data-confirm="Sign in as <?= e($u['name']) ?>?">
                                    <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-soft btn-icon" type="submit" title="Login as user"><?= icon('login', 16) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pg) ?>
</div>
<?php layout_end();
