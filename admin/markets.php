<?php
require __DIR__ . '/includes/cms-ui.php';
require_perm('markets.manage');
require_once SPH_ROOT . '/app/markets/config.php';

$back = 'markets.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'show', 'cat', 'page'])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf($back);
    $action = $_POST['action'] ?? '';
    $me = current_admin()['id'];
    if ($action === 'platforms') {
        $on = array_intersect(array_keys(MK_PLATFORMS), (array)($_POST['on'] ?? []));
        if (!$on) { flash('error', 'Keep at least one platform switched on.'); redirect($back); }
        cms_setting_set('disabled_platforms', implode(',', array_diff(array_keys(MK_PLATFORMS), $on)));
        audit('markets.platforms', 'settings', null, 'on: ' . implode(', ', $on));
        flash('success', 'Platforms saved. Switched-off platforms disappear now and are skipped from the next data refresh.');
    } elseif ($action === 'refresh') {
        audit('markets.refresh', 'markets');
        flash('success', 'Refresh started — new data appears in 1–3 minutes.');
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            set_time_limit(600);
            require_once SPH_ROOT . '/app/markets/aggregate.php';
            try { mk_refresh_locked(); } catch (Throwable $e) { error_log('markets refresh (admin): ' . $e->getMessage()); }
        });
    } elseif (in_array($action, ['pin', 'unpin', 'hide', 'show', 'edit', 'reset'], true)) {
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match('/^[a-z0-9-]{1,200}$/', $id)) redirect($back);
        q('INSERT IGNORE INTO cms_market_overrides (event_id) VALUES (?)', [$id]);
        if ($action === 'pin' || $action === 'unpin') q('UPDATE cms_market_overrides SET pinned = ?, updated_by = ? WHERE event_id = ?', [(int)($action === 'pin'), $me, $id]);
        if ($action === 'hide' || $action === 'show') q('UPDATE cms_market_overrides SET hidden = ?, updated_by = ? WHERE event_id = ?', [(int)($action === 'hide'), $me, $id]);
        if ($action === 'edit') {
            $t = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
            $c = (string)($_POST['category'] ?? '');
            q('UPDATE cms_market_overrides SET title = ?, category = ?, updated_by = ? WHERE event_id = ?',
                [$t !== '' && $t !== ($_POST['orig_title'] ?? '') ? $t : null, isset(MK_CATEGORIES[$c]) && $c !== ($_POST['orig_cat'] ?? '') ? $c : null, $me, $id]);
        }
        if ($action === 'reset') q('DELETE FROM cms_market_overrides WHERE event_id = ?', [$id]);
        q('DELETE FROM cms_market_overrides WHERE hidden = 0 AND pinned = 0 AND title IS NULL AND category IS NULL');
        audit('markets.' . $action, 'market', $id);
        flash('success', ['pin' => 'Pinned to the top of the markets list.', 'unpin' => 'Unpinned.', 'hide' => 'Hidden from the website.', 'show' => 'Visible again.',
            'edit' => 'Saved.', 'reset' => 'All changes for this market removed.'][$action]);
    }
    cms_publish_cache();
    redirect($back);
}

$file = MK_CACHE_DIR . '/markets.json';
$data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
$age = is_file($file) ? time() - filemtime($file) : null;
$ov = [];
foreach (q('SELECT * FROM cms_market_overrides')->fetchAll() as $o) $ov[$o['event_id']] = $o;
$off = array_filter(explode(',', (string)cms_setting_get('disabled_platforms', '')));

$search = mb_strtolower(trim((string)($_GET['q'] ?? '')));
$show = (string)($_GET['show'] ?? '');
$cat = (string)($_GET['cat'] ?? '');
$events = array_values(array_filter($data['events'] ?? [], function ($e) use ($search, $show, $cat, $ov) {
    $o = $ov[$e['id']] ?? null;
    if ($search !== '' && mb_strpos(mb_strtolower($e['title'] . ' ' . ($o['title'] ?? '')), $search) === false) return false;
    if ($cat !== '' && ($o['category'] ?? $e['category']) !== $cat) return false;
    if ($show === 'hidden') return $o && $o['hidden'];
    if ($show === 'pinned') return $o && $o['pinned'];
    if ($show === 'edited') return $o && ($o['title'] !== null || $o['category'] !== null);
    return true;
}));
usort($events, fn($a, $b) => (int)!empty($ov[$b['id']]['pinned']) <=> (int)!empty($ov[$a['id']]['pinned']) ?: $b['volume'] <=> $a['volume']);
$pg = paginate(count($events), 40);
$pageEvents = array_slice($events, $pg['offset'], $pg['per']);
$money = fn($v) => $v >= 1e9 ? '$' . round($v / 1e9, 1) . 'B' : ($v >= 1e6 ? '$' . round($v / 1e6, 1) . 'M' : ($v >= 1e3 ? '$' . round($v / 1e3) . 'K' : '$' . round($v)));
$st = $data['stats'] ?? [];

layout_start('Markets', 'markets', 'Live data from Polymarket, Kalshi, Limitless and PredictIt — hide, pin or rename markets',
    '<a class="btn btn-light" href="/markets" target="_blank" rel="noopener">' . icon('globe', 16) . 'View markets</a>');
?>
<div class="grid grid-2 mb">
    <div class="card"><div class="card-head"><h2>Data status</h2>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="btn btn-light btn-sm"><?= icon('refresh', 14) ?>Refresh now</button></form></div>
        <div class="card-body">
        <?php if (!$data): ?><p class="muted">No market data yet. Press “Refresh now” or wait for the cron job.</p>
        <?php else: ?>
            <div class="kv">
                <div><span class="muted small">Last update</span><br><strong><?= $age < 120 ? 'just now' : e(time_ago(date('Y-m-d H:i:s', time() - $age))) ?></strong>
                    <?= $age > 1800 ? '<span class="badge b-red">Stale — check the cron job</span>' : '' ?></div>
                <div><span class="muted small">Events on the site</span><br><strong><?= number_format((int)($st['events'] ?? 0)) ?></strong> <span class="muted small">(<?= (int)($st['matched'] ?? 0) ?> matched across platforms)</span></div>
                <div><span class="muted small">Total volume</span><br><strong><?= $money((float)($st['volume'] ?? 0)) ?></strong></div>
                <div><span class="muted small">24h volume</span><br><strong><?= $money((float)($st['volume24'] ?? 0)) ?></strong></div>
            </div>
        <?php endif; ?>
    </div></div>
    <div class="card"><div class="card-head"><h2>Platforms</h2></div><div class="card-body">
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="platforms">
            <?php foreach (MK_PLATFORMS as $k => [$label]): ?>
                <div class="field" style="margin-bottom:10px"><label class="switch"><input type="checkbox" name="on[]" value="<?= $k ?>" <?= in_array($k, $off, true) ? '' : 'checked' ?>> <?= e($label) ?>
                    <span class="muted small">— <?= number_format((int)($st['platform_counts'][$k] ?? 0)) ?> events in last fetch</span></label></div>
            <?php endforeach; ?>
            <button class="btn btn-primary btn-sm"><?= icon('check', 14) ?>Save platforms</button>
        </form>
    </div></div>
</div>

<div class="card">
    <form class="filters" method="get">
        <div class="search"><?= icon('search', 16) ?><input type="text" name="q" value="<?= e($search) ?>" placeholder="Search markets…"></div>
        <select name="show"><option value="">All markets</option>
            <?php foreach (['pinned' => 'Pinned', 'hidden' => 'Hidden', 'edited' => 'Renamed / recategorised'] as $k => $v): ?><option value="<?= $k ?>" <?= $show === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
        <select name="cat"><option value="">All categories</option>
            <?php foreach (MK_CATEGORIES as $k => $v): ?><option value="<?= $k ?>" <?= $cat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <button class="btn btn-light">Filter</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Market</th><th>Platforms</th><th class="text-right">Volume</th><th>Title & category on the site</th><th></th></tr></thead>
        <tbody>
        <?php if (!$pageEvents): ?><tr><td colspan="5" class="muted">No markets match.</td></tr><?php endif; ?>
        <?php foreach ($pageEvents as $e):
            $o = $ov[$e['id']] ?? null;
            $fid = 'mk' . substr(md5($e['id']), 0, 10);
            $hid = $o && $o['hidden']; $pin = $o && $o['pinned']; ?>
            <tr <?= $hid ? 'style="opacity:.55"' : '' ?>>
                <td style="max-width:320px"><strong><?= e($e['title']) ?></strong>
                    <?= $pin ? ' <span class="badge b-amber">Pinned</span>' : '' ?><?= $hid ? ' <span class="badge b-red">Hidden</span>' : '' ?>
                    <span class="sub"><a href="/event/<?= e($e['id']) ?>" target="_blank" rel="noopener">/event/<?= e(mb_strimwidth($e['id'], 0, 50, '…')) ?></a></span></td>
                <td class="nowrap small"><?= e(implode(', ', array_map(fn($p) => MK_PLATFORMS[$p][0] ?? $p, array_keys($e['platforms'])))) ?></td>
                <td class="text-right nowrap"><?= $money((float)$e['volume']) ?></td>
                <td style="min-width:300px">
                    <form id="<?= $fid ?>" method="post" class="form" style="display:flex;gap:6px"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= e($e['id']) ?>">
                        <input type="hidden" name="orig_title" value="<?= e($e['title']) ?>"><input type="hidden" name="orig_cat" value="<?= e($e['category']) ?>">
                        <input class="input" type="text" name="title" value="<?= e($o['title'] ?? $e['title']) ?>" style="padding:6px 9px;font-size:13px">
                        <select name="category" style="width:auto;padding:6px;font-size:13px"><?php foreach (MK_CATEGORIES as $k => $v): ?><option value="<?= $k ?>" <?= ($o['category'] ?? $e['category']) === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-light btn-sm" title="Save"><?= icon('check', 14) ?></button>
                    </form></td>
                <td><div class="actions">
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e['id']) ?>"><input type="hidden" name="action" value="<?= $pin ? 'unpin' : 'pin' ?>">
                        <button class="btn btn-light btn-icon" title="<?= $pin ? 'Unpin' : 'Pin to top' ?>"><?= icon('pin', 15) ?></button></form>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e['id']) ?>"><input type="hidden" name="action" value="<?= $hid ? 'show' : 'hide' ?>">
                        <button class="btn btn-light btn-icon" title="<?= $hid ? 'Show on site' : 'Hide from site' ?>"><?= icon($hid ? 'eye' : 'eye-off', 15) ?></button></form>
                    <?php if ($o): ?><form method="post" class="inline" data-confirm="Remove all changes for this market?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e['id']) ?>"><input type="hidden" name="action" value="reset">
                        <button class="btn btn-light btn-icon" title="Reset"><?= icon('refresh', 15) ?></button></form><?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= render_pagination($pg) ?>
</div>
<?php layout_end();
