<?php
require __DIR__ . '/includes/ve.php';
require_perm('pages.edit');

$p = (string)($_GET['p'] ?? '');
$t = ve_target($p);
if (!$t) { flash('error', 'This page cannot be edited visually.'); redirect('pages.php'); }
$isChrome = $t['key'] === '@chrome';
$enc = rawurlencode($t['key']);

layout_start($isChrome ? 'Header & Footer' : 'Visual editor', $isChrome ? 'chrome' : 'pages');
echo cms_assets();
$v = @filemtime(__DIR__ . '/assets/visual-editor.js');
?>
<div class="ve-top" data-ve data-p="<?= e($t['key']) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <h1><?= $isChrome ? 'Header & Footer <span class="muted small">(used on every page)</span>' : e($t['title']) ?></h1>
    <div class="ve-dev">
        <button type="button" class="on" data-ve-mode="edit" title="Edit mode: click text to change it"><?= icon('edit', 16) ?></button>
        <button type="button" data-ve-mode="browse" title="Browse mode: click menus, tabs and links normally"><?= icon('eye', 16) ?></button>
    </div>
    <div class="ve-dev">
        <button type="button" class="on" data-ve-dev="100%" title="Desktop"><?= icon('monitor', 16) ?></button>
        <button type="button" data-ve-dev="820px" title="Tablet"><?= icon('tablet', 16) ?></button>
        <button type="button" data-ve-dev="400px" title="Mobile"><?= icon('smartphone', 16) ?></button>
    </div>
    <span class="ve-hint grow" data-ve-status>Click any text to edit · click an image to replace it · click a link, then 🔗 to change its address</span>
    <a class="btn btn-light btn-sm" href="page-code.php?p=<?= $enc ?>"><?= icon('code', 14) ?>HTML</a>
    <a class="btn btn-light btn-sm" href="revisions.php?entity=<?= $isChrome ? 'chrome&id=main' : 'page&id=' . $enc ?>"><?= icon('history', 14) ?>Revisions</a>
    <?php if (!$isChrome): ?><a class="btn btn-light btn-sm" href="page-settings.php?p=<?= $enc ?>"><?= icon('search', 14) ?>SEO</a>
        <a class="btn btn-light btn-sm" href="<?= e($t['key']) ?>" target="_blank" rel="noopener"><?= icon('globe', 14) ?>View</a><?php endif; ?>
    <button type="button" class="btn btn-primary btn-sm" data-ve-save disabled><?= icon('check', 14) ?>Save <span class="ve-count" data-ve-count></span></button>
</div>
<?php if (!$isChrome && $t['use_chrome']): ?>
<div class="alert alert-info" style="margin:10px 16px 0"><?= icon('panel', 16) ?><span>The header and footer on this page come from <a href="page-edit.php?p=<?= rawurlencode('@chrome') ?>">Header & Footer</a> — edit them there once and every page updates.</span></div>
<?php endif; ?>
<div class="ve-stage"><iframe src="page-render.php?p=<?= $enc ?>" data-ve-frame title="Page editor"></iframe></div>
<?= media_modal() ?>
<script src="assets/visual-editor.js?v=<?= $v ?>" defer></script>
<?php layout_end();
