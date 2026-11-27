<?php
require __DIR__ . '/includes/ve.php';
require_perm('pages.edit');

$t = ve_target((string)($_GET['p'] ?? $_POST['p'] ?? ''));
if (!$t) { flash('error', 'Page not found.'); redirect('pages.php'); }
$isChrome = $t['key'] === '@chrome';
$enc = rawurlencode($t['key']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('page-code.php?p=' . $enc);
    $norm = fn($s) => str_replace("\r\n", "\n", (string)$s);
    $src = $isChrome
        ? rtrim($norm($_POST['header'] ?? ''), "\n") . "\n" . CMS_CHROME_SPLIT . "\n" . rtrim($norm($_POST['footer'] ?? ''), "\n") . "\n"
        : $norm($_POST['source'] ?? '');
    if ($src === $t['source']) { flash('info', 'No changes.'); redirect('page-code.php?p=' . $enc); }
    $error = ve_save($t, $src);
    if (!$error) { flash('success', 'Saved — live on the website now.'); redirect('page-code.php?p=' . $enc); }
}
[$hdr, $ftr] = $isChrome ? array_pad(explode(CMS_CHROME_SPLIT, $t['source']), 2, '') : ['', ''];

layout_start($isChrome ? 'Header & Footer — HTML' : 'Edit HTML', $isChrome ? 'chrome' : 'pages',
    ($isChrome ? '' : '<a href="pages.php">Pages</a> / <span class="mono">' . e($t['key']) . '</span>'),
    '<a class="btn btn-light" href="page-edit.php?p=' . $enc . '">' . icon('edit', 16) . 'Visual editor</a>'
    . '<a class="btn btn-light" href="revisions.php?entity=' . ($isChrome ? 'chrome&id=main' : 'page&id=' . $enc) . '">' . icon('history', 16) . 'Revisions</a>');
echo cms_assets();
?>
<?php if ($error): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($error) ?></span></div><?php endif; ?>
<div class="alert alert-warn"><?= icon('code', 16) ?><span>Advanced: this is the page's real HTML. A mistake can break the layout — if that happens, open <b>Revisions</b> and restore the previous version.</span></div>
<form method="post" class="form" data-once><?= csrf_field() ?><input type="hidden" name="p" value="<?= e($t['key']) ?>">
<?php if ($isChrome): ?>
    <div class="card mb"><div class="card-head"><h2>Header</h2><span class="muted small">Top bar, main menu and mobile menu</span></div><div class="card-body">
        <textarea name="header" class="code-area" spellcheck="false"><?= e(trim($hdr, "\n")) ?></textarea></div></div>
    <div class="card mb"><div class="card-head"><h2>Footer</h2></div><div class="card-body">
        <textarea name="footer" class="code-area" style="min-height:40vh" spellcheck="false"><?= e(trim($ftr, "\n")) ?></textarea></div></div>
<?php else: ?>
    <div class="card mb"><div class="card-body"><textarea name="source" class="code-area" spellcheck="false"><?= e($t['source']) ?></textarea></div></div>
<?php endif; ?>
    <button class="btn btn-primary"><?= icon('check', 16) ?>Save HTML</button>
</form>
<?php layout_end();
