<?php
require __DIR__ . '/includes/cms-ui.php';
require_perm('pages.edit');

$orig = isset($_GET['p']) || isset($_POST['orig']) ? cms_clean_path((string)($_GET['p'] ?? $_POST['orig'] ?? '')) : null;
$row = $orig ? cms_page_row($orig) : null;
if ($orig && (!$row || $row['kind'] !== 'custom')) { flash('error', 'Page not found.'); redirect('pages.php'); }
$f = $row ?: ['path' => '', 'title' => '', 'content' => '', 'status' => 'published', 'meta_title' => '', 'meta_description' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('pages.php');
    $f['title'] = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $rawPath = trim((string)($_POST['path'] ?? ''));
    $path = cms_clean_path($rawPath !== '' ? '/' . ltrim($rawPath, '/') : '/' . cms_slugify($f['title']));
    $f['path'] = $path ?? $rawPath;
    $f['content'] = (string)($_POST['content'] ?? '');
    $f['status'] = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
    $f['meta_title'] = mb_substr(trim((string)($_POST['meta_title'] ?? '')), 0, 255);
    $f['meta_description'] = mb_substr(trim((string)($_POST['meta_description'] ?? '')), 0, 500);

    if ($f['title'] === '') $errors[] = 'Title is required.';
    if ($path === null || $path === '/') $errors[] = 'URL can use only lowercase letters, numbers, - and /.';
    elseif (cms_is_reserved($path)) $errors[] = "$path is used by the website itself. Pick another URL.";
    elseif (isset(cms_static_pages()[$path]) || (file_exists(SPH_ROOT . $path . '.php'))) $errors[] = "A page already exists at $path.";
    elseif ($path !== $orig && cms_page_row($path)) $errors[] = "A page already exists at $path.";

    if (!$errors) {
        $vals = [$f['title'], $f['content'], $f['status'], $f['meta_title'] ?: null, $f['meta_description'] ?: null, current_admin()['id']];
        if ($row) {
            cms_revision_add('page', $orig, $row['title'], $row['content']);
            q('UPDATE cms_pages SET title = ?, content = ?, status = ?, meta_title = ?, meta_description = ?, updated_by = ?, path = ? WHERE path = ?', array_merge($vals, [$path, $orig]));
            audit('page.update', 'page', $path, $f['title']);
        } else {
            q("INSERT INTO cms_pages (title, content, status, meta_title, meta_description, updated_by, path, kind) VALUES (?, ?, ?, ?, ?, ?, ?, 'custom')", array_merge($vals, [$path]));
            audit('page.create', 'page', $path, $f['title']);
        }
        cms_publish_cache();
        flash('success', $f['status'] === 'published' ? "Saved — live at $path" : 'Saved as draft (not visible to visitors).');
        redirect('page-custom.php?p=' . rawurlencode($path));
    }
}

layout_start($row ? 'Edit page' : 'New page', 'pages', '<a href="pages.php">Pages</a> / ' . e($row['title'] ?? 'New'),
    $row && $row['status'] === 'published' ? '<a class="btn btn-light" href="' . e($orig) . '" target="_blank" rel="noopener">' . icon('globe', 16) . 'View page</a>' : '');
echo cms_assets();
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($er) ?></span></div><?php endforeach; ?>
<form method="post" class="form" data-once><?= csrf_field() ?><?php if ($orig): ?><input type="hidden" name="orig" value="<?= e($orig) ?>"><?php endif; ?>
<div class="grid grid-main">
    <div class="card"><div class="card-body">
        <div class="field"><label class="lbl">Page title</label><input type="text" name="title" value="<?= e($f['title']) ?>" maxlength="255" required data-slug-src style="font-size:20px;font-weight:600" placeholder="e.g. About us"></div>
        <div class="field"><label class="lbl">URL</label><div style="display:flex;align-items:center;gap:6px"><span class="muted mono small">smartpredictionhub.com/</span>
            <input type="text" name="path" value="<?= e(ltrim($f['path'], '/')) ?>" maxlength="190" class="mono" data-slug placeholder="about-us"></div></div>
        <div class="field"><label class="lbl">Content</label><?= rich_editor('content', (string)$f['content']) ?>
            <div class="hint">Shown with the site header and footer. Use <b>HTML</b> to paste custom code (Tailwind classes work).</div></div>
    </div></div>
    <div>
        <div class="card mb"><div class="card-head"><h2>Publish</h2></div><div class="card-body">
            <div class="field"><select name="status"><option value="published" <?= $f['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="draft" <?= $f['status'] !== 'published' ? 'selected' : '' ?>>Draft (hidden)</option></select></div>
            <button class="btn btn-primary btn-block"><?= icon('check', 16) ?>Save</button>
            <?php if ($row): ?><a class="btn btn-light btn-block" style="margin-top:8px" href="revisions.php?entity=page&id=<?= rawurlencode($orig) ?>"><?= icon('history', 15) ?>Revisions</a><?php endif; ?>
        </div></div>
        <div class="card"><div class="card-head"><h2>SEO</h2></div><div class="card-body">
            <div class="field"><label class="lbl">SEO title</label><input type="text" name="meta_title" value="<?= e((string)$f['meta_title']) ?>" data-count="60" maxlength="255"></div>
            <div class="field"><label class="lbl">Meta description</label><textarea name="meta_description" rows="3" data-count="160" maxlength="500"><?= e((string)$f['meta_description']) ?></textarea></div>
            <?php if ($row): ?><a href="page-settings.php?p=<?= rawurlencode($orig) ?>">Share image & more →</a><?php endif; ?>
        </div></div>
    </div>
</div>
</form>
<?= media_modal() ?>
<?php layout_end();
