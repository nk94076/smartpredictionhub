<?php
require __DIR__ . '/includes/cms-ui.php';
require_admin();
if (!can('pages.edit') && !can('seo.manage')) deny_page();
$canEdit = can('pages.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('pages.php');
    if (!$canEdit) { flash('error', 'You do not have permission to change pages.'); redirect('pages.php'); }
    $path = cms_clean_path((string)($_POST['path'] ?? ''));
    $row = $path ? cms_page_row($path) : null;
    $action = $_POST['action'] ?? '';
    if ($path && $action === 'reset' && $row && $row['kind'] === 'static') {
        cms_revision_add('page', $path, 'Before reset', $row['content']);
        q('UPDATE cms_pages SET content = NULL, updated_by = ? WHERE path = ?', [current_admin()['id'], $path]);
        audit('page.reset', 'page', $path);
        flash('success', "$path was reset to the original design file.");
    } elseif ($path && in_array($action, ['hide', 'show'], true)) {
        $kind = isset(cms_static_pages()[$path]) ? 'static' : ($row['kind'] ?? 'custom');
        cms_page_ensure($path, $kind);
        q('UPDATE cms_pages SET status = ?, updated_by = ? WHERE path = ?', [$action === 'hide' ? 'hidden' : 'published', current_admin()['id'], $path]);
        audit('page.' . $action, 'page', $path);
        flash('success', $action === 'hide' ? "$path is now hidden (visitors get “page not found”)." : "$path is visible again.");
    } elseif ($path && $action === 'delete' && $row && $row['kind'] === 'custom') {
        cms_revision_add('page', $path, $row['title'] . ' (deleted)', $row['content']);
        q('DELETE FROM cms_pages WHERE path = ?', [$path]);
        audit('page.delete', 'page', $path);
        flash('success', "Page $path deleted.");
    }
    cms_publish_cache();
    redirect('pages.php');
}

$rows = [];
foreach (q('SELECT * FROM cms_pages')->fetchAll() as $r) $rows[$r['path']] = $r;
$title = function (string $file): string {
    $head = (string)file_get_contents($file, false, null, 0, 4000);
    return preg_match('~<title>(.*?)</title>~is', $head, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : basename($file);
};
$list = [];
foreach (cms_static_pages() as $path => $file) {
    $r = $rows[$path] ?? null;
    $list[] = ['path' => $path, 'kind' => 'static', 'title' => $r['meta_title'] ?? '' ?: $title($file), 'row' => $r];
}
foreach (CMS_DYNAMIC_PAGES as $path => $t) $list[] = ['path' => $path, 'kind' => 'dynamic', 'title' => $rows[$path]['meta_title'] ?? '' ?: $t, 'row' => $rows[$path] ?? null];
foreach ($rows as $path => $r) {
    if ($r['kind'] === 'custom') $list[] = ['path' => $path, 'kind' => 'custom', 'title' => $r['title'] ?: $path, 'row' => $r];
}
$kindBadge = ['static' => '<span class="badge b-purple">Designed page</span>', 'dynamic' => '<span class="badge b-blue">Live data</span>', 'custom' => '<span class="badge b-green">CMS page</span>'];

layout_start('Pages', 'pages', 'Every page on the website — edit content visually, change SEO, hide pages or add new ones',
    $canEdit ? '<a class="btn btn-light" href="page-edit.php?p=' . rawurlencode('@chrome') . '">' . icon('panel', 16) . 'Header & Footer</a><a class="btn btn-primary" href="page-custom.php">' . icon('plus', 16) . 'New page</a>' : '');
?>
<div class="alert alert-info"><?= icon('edit', 16) ?><span><b>Visual edit</b> opens the real page — click any text to change it, click images to swap them, click links to change where they go. Every save keeps a revision you can restore.</span></div>
<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>Page</th><th>Type</th><th>Status</th><th>Last change</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $p):
            $r = $p['row'];
            $hidden = ($r['status'] ?? '') === 'hidden';
            $edited = $p['kind'] === 'static' && $r && $r['content'] !== null && $r['content'] !== '';
            $enc = rawurlencode($p['path']); ?>
            <tr>
                <td><strong><?= e($p['title']) ?></strong><span class="sub mono"><a href="<?= e($p['path']) ?>" target="_blank" rel="noopener"><?= e($p['path']) ?></a></span></td>
                <td><?= $kindBadge[$p['kind']] ?></td>
                <td class="nowrap"><?= $hidden ? '<span class="badge b-red b-dot">Hidden</span>' : '<span class="badge b-green b-dot">Live</span>' ?>
                    <?= $edited ? ' <span class="badge b-amber">Edited</span>' : '' ?><?= ($r && (!empty($r['meta_title']) || !empty($r['meta_description']))) ? ' <span class="badge b-gray">SEO</span>' : '' ?></td>
                <td class="nowrap"><?= $r ? e(time_ago($r['updated_at'])) : '<span class="muted">—</span>' ?></td>
                <td><div class="actions">
                    <?php if ($canEdit && $p['kind'] === 'static'): ?>
                        <a class="btn btn-primary btn-sm" href="page-edit.php?p=<?= $enc ?>"><?= icon('edit', 14) ?>Visual edit</a>
                        <a class="btn btn-light btn-icon" href="page-code.php?p=<?= $enc ?>" title="Edit HTML code"><?= icon('code', 15) ?></a>
                    <?php elseif ($canEdit && $p['kind'] === 'custom'): ?>
                        <a class="btn btn-primary btn-sm" href="page-custom.php?p=<?= $enc ?>"><?= icon('edit', 14) ?>Edit</a>
                    <?php endif; ?>
                    <a class="btn btn-light btn-sm" href="page-settings.php?p=<?= $enc ?>"><?= icon('search', 14) ?>SEO</a>
                    <?php if ($canEdit && $p['kind'] !== 'dynamic'): ?>
                        <a class="btn btn-light btn-icon" href="revisions.php?entity=page&id=<?= $enc ?>" title="Revisions"><?= icon('history', 15) ?></a>
                        <form method="post" class="inline" data-confirm="<?= $hidden ? 'Show' : 'Hide' ?> <?= e($p['path']) ?>?"><?= csrf_field() ?>
                            <input type="hidden" name="path" value="<?= e($p['path']) ?>"><input type="hidden" name="action" value="<?= $hidden ? 'show' : 'hide' ?>">
                            <button class="btn btn-light btn-icon" title="<?= $hidden ? 'Show' : 'Hide' ?>"><?= icon($hidden ? 'eye' : 'eye-off', 15) ?></button></form>
                    <?php endif; ?>
                    <?php if ($canEdit && $edited): ?>
                        <form method="post" class="inline" data-confirm="Undo ALL CMS edits on <?= e($p['path']) ?> and go back to the original design file? (A revision is kept.)"><?= csrf_field() ?>
                            <input type="hidden" name="path" value="<?= e($p['path']) ?>"><input type="hidden" name="action" value="reset">
                            <button class="btn btn-light btn-icon" title="Reset to original"><?= icon('refresh', 15) ?></button></form>
                    <?php endif; ?>
                    <?php if ($canEdit && $p['kind'] === 'custom'): ?>
                        <form method="post" class="inline" data-confirm="Delete page <?= e($p['path']) ?>?"><?= csrf_field() ?>
                            <input type="hidden" name="path" value="<?= e($p['path']) ?>"><input type="hidden" name="action" value="delete">
                            <button class="btn btn-danger btn-icon" title="Delete"><?= icon('trash', 15) ?></button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php layout_end();
