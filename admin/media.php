<?php
require __DIR__ . '/includes/cms-ui.php';
require_perm('media.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('media.php');
    $m = q('SELECT * FROM cms_media WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch();
    if ($m && ($_POST['action'] ?? '') === 'delete') {
        $file = realpath(SPH_ROOT . $m['path']);
        if ($file && strpos($file, realpath(SPH_ROOT . '/uploads') . '/') === 0) @unlink($file);
        q('DELETE FROM cms_media WHERE id = ?', [$m['id']]);
        audit('media.delete', 'media', $m['id'], $m['path']);
        flash('success', 'Image deleted.');
    } elseif ($m && ($_POST['action'] ?? '') === 'alt') {
        q('UPDATE cms_media SET alt = ? WHERE id = ?', [mb_substr(trim((string)$_POST['alt']), 0, 255), $m['id']]);
        flash('success', 'Description saved.');
    }
    redirect('media.php?page=' . (int)($_GET['page'] ?? 1));
}

$total = (int)q('SELECT COUNT(*) FROM cms_media')->fetchColumn();
$pg = paginate($total, 48);
$items = q("SELECT m.*, a.username FROM cms_media m LEFT JOIN admin_users a ON a.id = m.uploaded_by ORDER BY m.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}")->fetchAll();
$fmtSize = fn($b) => $b > 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';

layout_start('Media Library', 'media', number_format($total) . ' images · used in posts, pages and the header/footer');
echo cms_assets();
?>
<div id="mediaModal" data-csrf="<?= e(csrf_token()) ?>" data-disabled="1" hidden></div>
<label class="dropzone" data-dropzone>
    <?= icon('image', 30) ?><br><strong>Drop images here or click to upload</strong><br>
    <span class="muted small">JPG, PNG, GIF or WebP · up to 8 MB · photos wider than 2000px are scaled down</span>
    <input type="file" accept="image/*" multiple hidden>
</label>
<?php if (!$items): ?><div class="card empty-state"><?= icon('image', 36) ?><p>No images yet.</p></div><?php endif; ?>
<div class="media-grid">
<?php foreach ($items as $m): ?>
    <div class="media-card">
        <a href="<?= e($m['path']) ?>" target="_blank" rel="noopener"><img src="<?= e($m['path']) ?>" alt="<?= e((string)$m['alt']) ?>" loading="lazy"></a>
        <div class="mc-body">
            <b title="<?= e((string)$m['original_name']) ?>"><?= e($m['original_name'] ?: basename($m['path'])) ?></b>
            <span class="muted"><?= (int)$m['width'] ?>×<?= (int)$m['height'] ?> · <?= $fmtSize((int)$m['size']) ?></span>
            <form method="post" class="form" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="alt">
                <input class="input" type="text" name="alt" value="<?= e((string)$m['alt']) ?>" placeholder="Description (alt)" style="padding:5px 8px;font-size:12px"></form>
            <div class="mc-actions">
                <button type="button" class="btn btn-light btn-sm" data-copy="<?= e($m['path']) ?>">Copy URL</button>
                <form method="post" class="inline" data-confirm="Delete this image? Pages and posts that use it will show a broken image."><?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn btn-danger btn-icon btn-sm" title="Delete"><?= icon('trash', 14) ?></button></form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?= render_pagination($pg) ?>
<?php layout_end();
