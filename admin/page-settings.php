<?php
require __DIR__ . '/includes/cms-ui.php';
require_admin();
if (!can('pages.edit') && !can('seo.manage')) deny_page();

$path = cms_clean_path((string)($_GET['p'] ?? $_POST['p'] ?? ''));
$static = cms_static_pages();
$row = $path ? cms_page_row($path) : null;
$kind = $path === null ? null : (isset($static[$path]) ? 'static' : (isset(CMS_DYNAMIC_PAGES[$path]) ? 'dynamic' : ($row['kind'] ?? null)));
if (!$kind) { flash('error', 'Page not found.'); redirect('pages.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('page-settings.php?p=' . rawurlencode($path));
    $row = cms_page_ensure($path, $kind);
    $in = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $og = $in('og_image', 500);
    if ($og !== '' && !cms_safe_url($og)) $og = '';
    q('UPDATE cms_pages SET meta_title = ?, meta_description = ?, og_image = ?, noindex = ?, updated_by = ? WHERE path = ?', [
        $in('meta_title', 255) ?: null, $in('meta_description', 500) ?: null, $og ?: null, !empty($_POST['noindex']) ? 1 : 0, current_admin()['id'], $path,
    ]);
    if (can('pages.edit') && $kind === 'static') {
        q('UPDATE cms_pages SET use_chrome = ? WHERE path = ?', [!empty($_POST['use_chrome']) ? 1 : 0, $path]);
    }
    cms_publish_cache();
    audit('page.seo', 'page', $path);
    flash('success', 'Saved. Changes are live.');
    redirect('page-settings.php?p=' . rawurlencode($path));
}

// defaults shown as placeholders: the page's own <title>/<description>
$ownTitle = $ownDesc = '';
if ($kind === 'static') {
    $src = cms_static_source($path) ?? '';
    if (preg_match('~<title>(.*?)</title>~is', $src, $m)) $ownTitle = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    if (preg_match('~<meta\s+name="description"\s+content="([^"]*)"~i', $src, $m)) $ownDesc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
} elseif ($kind === 'custom') {
    $ownTitle = (string)$row['title'];
}
$r = $row ?: ['meta_title' => '', 'meta_description' => '', 'og_image' => '', 'noindex' => 0, 'use_chrome' => 1];
$showT = $r['meta_title'] ?: $ownTitle ?: $path;
$showD = $r['meta_description'] ?: $ownDesc;

layout_start('SEO & settings', 'pages', '<a href="pages.php">Pages</a> / <span class="mono">' . e($path) . '</span>',
    '<a class="btn btn-light" href="' . e($path) . '" target="_blank" rel="noopener">' . icon('globe', 16) . 'View page</a>');
echo cms_assets();
?>
<form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="p" value="<?= e($path) ?>">
<div class="grid grid-main">
    <div class="card"><div class="card-head"><h2>Search & social</h2></div><div class="card-body">
        <div class="field"><label class="lbl">SEO title</label><input type="text" name="meta_title" value="<?= e((string)$r['meta_title']) ?>" maxlength="255" data-count="60" placeholder="<?= e($ownTitle) ?>"></div>
        <div class="field"><label class="lbl">Meta description</label><textarea name="meta_description" rows="3" maxlength="500" data-count="160" placeholder="<?= e($ownDesc) ?>"><?= e((string)$r['meta_description']) ?></textarea>
            <div class="hint">Leave empty to keep the page's own text (shown greyed out).</div></div>
        <?= image_field('og_image', $r['og_image'], 'Share image (Facebook, X, WhatsApp…) — 1200×630 works best') ?>
        <label class="switch"><input type="checkbox" name="noindex" value="1" <?= $r['noindex'] ? 'checked' : '' ?>> Hide from Google (noindex)</label>
    </div></div>
    <div>
        <div class="card mb"><div class="card-head"><h2>Google preview</h2></div><div class="card-body">
            <div class="seo-prev"><div class="t"><?= e(mb_strimwidth($showT, 0, 62, '…')) ?></div><div class="u">smartpredictionhub.com<?= e($path === '/' ? '' : $path) ?></div>
                <div class="d"><?= e(mb_strimwidth($showD ?: 'No description — Google will pick text from the page.', 0, 165, '…')) ?></div></div>
        </div></div>
        <?php if ($kind === 'static' && can('pages.edit')): ?>
        <div class="card mb"><div class="card-head"><h2>Layout</h2></div><div class="card-body">
            <label class="switch"><input type="checkbox" name="use_chrome" value="1" <?= $r['use_chrome'] ? 'checked' : '' ?>> Use the site-wide header & footer</label>
            <div class="hint">On: this page shows the header/footer from <a href="page-edit.php?p=<?= rawurlencode('@chrome') ?>">Header & Footer</a>. Off: it keeps its own copy.</div>
        </div></div>
        <?php endif; ?>
        <button class="btn btn-primary btn-block"><?= icon('check', 16) ?>Save</button>
    </div>
</div>
</form>
<?= media_modal() ?>
<?php layout_end();
