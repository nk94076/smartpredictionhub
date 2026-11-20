<?php
require __DIR__ . '/includes/cms-ui.php';
require_perm('seo.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('redirects.php');
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $from = cms_clean_path(strtolower(trim((string)($_POST['from'] ?? ''))));
        $to = trim((string)($_POST['to'] ?? ''));
        $code = ($_POST['code'] ?? '') === '302' ? 302 : 301;
        if ($from === null || $from === '/') flash('error', 'From must be a path on this site, like /old-page (lowercase letters, numbers, - and /).');
        elseif ($to === '' || !cms_safe_url($to) || $to[0] === '#') flash('error', 'To must be a path like /new-page or a full https:// address.');
        elseif (sph_norm_path($to) === $from) flash('error', 'A page cannot redirect to itself.');
        else {
            q('INSERT INTO cms_redirects (from_path, to_url, code) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE to_url = VALUES(to_url), code = VALUES(code)', [$from, $to, $code]);
            audit('redirect.save', 'redirect', $from, $to);
            flash('success', "$from now redirects to $to.");
        }
    } elseif ($action === 'delete') {
        q('DELETE FROM cms_redirects WHERE id = ?', [(int)$_POST['id']]);
        audit('redirect.delete', 'redirect', (int)$_POST['id']);
        flash('success', 'Redirect removed.');
    }
    cms_publish_cache();
    redirect('redirects.php');
}

$rows = q('SELECT * FROM cms_redirects ORDER BY from_path')->fetchAll();
layout_start('Redirects', 'redirects', 'Send old or mistyped URLs to the right page (keeps Google rankings when a URL changes)');
?>
<div class="card mb"><div class="card-head"><h2>Add redirect</h2></div><div class="card-body">
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div class="form-row">
            <div class="field"><label class="lbl">From (old URL)</label><input type="text" name="from" class="mono" required placeholder="/old-page"></div>
            <div class="field"><label class="lbl">To (new URL)</label><input type="text" name="to" class="mono" required placeholder="/new-page or https://…"></div>
        </div>
        <div class="field"><select name="code" style="width:auto"><option value="301">301 — moved permanently (best for SEO)</option><option value="302">302 — temporary</option></select></div>
        <button class="btn btn-primary"><?= icon('plus', 16) ?>Add redirect</button>
        <div class="hint">Works for URLs that no longer exist on the site. A redirect for a page that exists also wins over the page.</div>
    </form>
</div></div>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>From</th><th>To</th><th>Type</th><th>Added</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No redirects yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><td class="mono"><a href="<?= e($r['from_path']) ?>" target="_blank" rel="noopener"><?= e($r['from_path']) ?></a></td>
            <td class="mono"><?= e($r['to_url']) ?></td><td><?= (int)$r['code'] ?></td><td class="nowrap"><?= e(fmt_dt($r['created_at'], 'd M Y')) ?></td>
            <td><form method="post" class="inline" data-confirm="Remove this redirect?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-danger btn-icon"><?= icon('trash', 15) ?></button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php layout_end();
