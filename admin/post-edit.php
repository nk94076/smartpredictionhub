<?php
require __DIR__ . '/includes/cms-ui.php';
require_admin();
if (!can('posts.write') && !can('posts.publish')) deny_page();
$me = current_admin();
$canPublish = can('posts.publish');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$post = $id ? q('SELECT * FROM cms_posts WHERE id = ?', [$id])->fetch() : null;
if ($id && !$post) { flash('error', 'Post not found.'); redirect('posts.php'); }
$type = $post['type'] ?? (($_GET['type'] ?? $_POST['type'] ?? 'blog') === 'news' ? 'news' : 'blog');
if ($post && !$canPublish && ((int)$post['author_id'] !== (int)$me['id'] || $post['status'] === 'published')) {
    deny_page('Writers can edit only their own posts, and only until they are published.');
}

$f = $post ?: [
    'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '', 'cover_image' => '', 'cover_alt' => '', 'category_id' => null,
    'tags' => '', 'author_name' => $me['name'] ?: $me['username'], 'status' => 'draft', 'featured' => 0,
    'meta_title' => '', 'meta_description' => '', 'published_at' => null,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf($id ? "post-edit.php?id=$id" : "post-edit.php?type=$type");
    $action = (string)($_POST['action'] ?? 'save');
    $in = fn($k, $max = 500) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $f['title'] = $in('title', 255);
    $f['slug'] = cms_slugify($in('slug', 200) ?: $f['title']);
    $f['excerpt'] = $in('excerpt', 1000);
    $f['content'] = cms_sanitize((string)($_POST['content'] ?? ''));
    $f['cover_image'] = $in('cover_image');
    $f['cover_alt'] = $in('cover_alt', 255);
    $f['category_id'] = (int)($_POST['category_id'] ?? 0) ?: null;
    $f['tags'] = implode(', ', array_filter(array_map('trim', explode(',', $in('tags')))));
    $f['author_name'] = $in('author_name', 100);
    $f['featured'] = !empty($_POST['featured']) ? 1 : 0;
    $f['meta_title'] = $in('meta_title', 255);
    $f['meta_description'] = $in('meta_description', 500);
    $when = trim((string)($_POST['published_at'] ?? ''));
    $f['published_at'] = $when !== '' && strtotime($when) ? date('Y-m-d H:i:s', strtotime($when)) : ($post['published_at'] ?? null);

    if ($f['cover_image'] !== '' && !cms_safe_url($f['cover_image'])) $errors[] = 'Cover image must be a /uploads/… path or an https:// URL.';
    if ($f['title'] === '') $errors[] = 'Title is required.';
    if ($f['category_id'] && !q('SELECT id FROM cms_categories WHERE id = ? AND type = ?', [$f['category_id'], $type])->fetchColumn()) $f['category_id'] = null;
    if (q('SELECT id FROM cms_posts WHERE type = ? AND slug = ? AND id <> ?', [$type, $f['slug'], $id])->fetchColumn()) {
        $errors[] = 'Another ' . $type . ' post already uses the URL “' . $f['slug'] . '”. Change the URL slug.';
    }

    $new = $f['status'];
    if ($action === 'publish') {
        if (!$canPublish) $errors[] = 'You cannot publish. Use “Send for review”.';
        $new = 'published';
        if (!$f['published_at'] || ($post && $post['status'] !== 'published' && $when === '')) $f['published_at'] = date('Y-m-d H:i:s');
    } elseif ($action === 'unpublish' && $canPublish) {
        $new = 'draft';
    } elseif ($action === 'submit') {
        $new = 'pending';
    } elseif ($action === 'save' && !$canPublish) {
        $new = $f['status'] === 'pending' ? 'pending' : 'draft';
    }

    if (!$errors) {
        $cols = ['title', 'slug', 'excerpt', 'content', 'cover_image', 'cover_alt', 'category_id', 'tags', 'author_name', 'featured', 'meta_title', 'meta_description', 'published_at'];
        $vals = array_map(fn($c) => $f[$c] === '' && in_array($c, ['cover_image', 'cover_alt', 'tags', 'meta_title', 'meta_description', 'excerpt'], true) ? null : $f[$c], $cols);
        $vals[] = $new;
        $vals[] = cms_read_minutes((string)$f['content']);
        $vals[] = $me['id'];
        if ($post) {
            if ($post['content'] !== $f['content'] || $post['title'] !== $f['title']) cms_revision_add('post', (string)$id, $post['title'], $post['content']);
            q('UPDATE cms_posts SET ' . implode(' = ?, ', $cols) . ' = ?, status = ?, read_minutes = ?, updated_by = ? WHERE id = ?', array_merge($vals, [$id]));
            audit('post.' . ($action === 'publish' ? 'publish' : ($action === 'unpublish' ? 'unpublish' : 'update')), 'post', $id, $f['title']);
        } else {
            q('INSERT INTO cms_posts (' . implode(', ', $cols) . ', status, read_minutes, updated_by, type, author_id) VALUES (' . str_repeat('?, ', count($cols) + 4) . '?)',
                array_merge($vals, [$type, $me['id']]));
            $id = (int)db()->lastInsertId();
            audit('post.create', 'post', $id, $f['title']);
        }
        $msg = ['publish' => strtotime($f['published_at']) > time() ? 'Scheduled — it goes live on ' . fmt_dt($f['published_at']) . '.' : 'Published — it is live now.',
            'unpublish' => 'Unpublished — moved back to drafts.', 'submit' => 'Sent for review. An editor will publish it.'][$action] ?? 'Saved.';
        flash('success', $msg);
        redirect("post-edit.php?id=$id");
    }
    $f['status'] = $post['status'] ?? 'draft';
}

$cats = q('SELECT id, name FROM cms_categories WHERE type = ? ORDER BY sort_order, name', [$type])->fetchAll();
$isLive = $post && $post['status'] === 'published' && strtotime((string)$post['published_at']) <= time();
$url = '/' . $type . '/' . ($f['slug'] ?: 'your-post-url');
$revCount = $post ? count(cms_revisions('post', (string)$id)) : 0;

layout_start($post ? 'Edit ' . ($type === 'news' ? 'story' : 'post') : 'New ' . ($type === 'news' ? 'news story' : 'blog post'), 'posts-' . $type,
    '<a href="posts.php?type=' . $type . '">' . ($type === 'news' ? 'News' : 'Blog Posts') . '</a> / ' . e($post['title'] ?? 'New'),
    $post ? ($isLive ? '<a class="btn btn-light" href="' . e($url) . '" target="_blank" rel="noopener">' . icon('globe', 16) . 'View live</a>' : '')
        . '<a class="btn btn-light" href="post-preview.php?id=' . $id . '" target="_blank" rel="noopener">' . icon('eye', 16) . 'Preview</a>' : '');
echo cms_assets();
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($er) ?></span></div><?php endforeach; ?>
<?php if ($post && $post['status'] === 'pending' && $canPublish): ?><div class="alert alert-warn"><?= icon('clock', 16) ?><span>This post is waiting for review. Check it and press <b>Publish</b>.</span></div><?php endif; ?>
<form method="post" class="form" data-once>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="grid grid-main">
        <div>
            <div class="card mb"><div class="card-body">
                <div class="field"><label class="lbl">Title</label>
                    <input type="text" name="title" value="<?= e($f['title']) ?>" maxlength="255" required data-slug-src placeholder="A clear, specific headline" style="font-size:20px;font-weight:600"></div>
                <div class="field"><label class="lbl">URL</label>
                    <div style="display:flex;align-items:center;gap:6px"><span class="muted mono small">smartpredictionhub.com/<?= $type ?>/</span>
                    <input type="text" name="slug" value="<?= e($f['slug']) ?>" maxlength="200" data-slug placeholder="auto-from-title" class="mono"></div>
                    <?php if ($isLive): ?><div class="hint">Changing the URL of a live post breaks old links — add a redirect under <a href="redirects.php">Redirects</a>.</div><?php endif; ?></div>
                <div class="field"><label class="lbl">Content</label><?= rich_editor('content', (string)$f['content']) ?></div>
                <div class="field"><label class="lbl">Summary <span class="muted">(shown on cards and under the title)</span></label>
                    <textarea name="excerpt" rows="3" maxlength="1000" data-count="200" placeholder="1–2 sentences. Leave empty to use the start of the article."><?= e($f['excerpt']) ?></textarea></div>
            </div></div>
            <div class="card mb"><div class="card-head"><h2>SEO</h2><span class="muted small">How it looks on Google</span></div><div class="card-body">
                <div class="field"><label class="lbl">SEO title</label><input type="text" name="meta_title" value="<?= e($f['meta_title']) ?>" maxlength="255" data-count="60" placeholder="Defaults to the post title"></div>
                <div class="field"><label class="lbl">Meta description</label><textarea name="meta_description" rows="2" maxlength="500" data-count="160" placeholder="Defaults to the summary"><?= e($f['meta_description']) ?></textarea></div>
            </div></div>
        </div>
        <div>
            <div class="card mb"><div class="card-head"><h2>Publish</h2><?= $post ? post_status_badge($post) : '<span class="badge b-gray">New</span>' ?></div><div class="card-body">
                <div class="field"><label class="lbl">Publish date</label>
                    <input type="datetime-local" name="published_at" value="<?= $f['published_at'] ? e(date('Y-m-d\TH:i', strtotime($f['published_at']))) : '' ?>">
                    <div class="hint">Leave empty to publish now. A future date schedules the post.</div></div>
                <label class="switch" style="margin-bottom:14px"><input type="checkbox" name="featured" value="1" <?= $f['featured'] ? 'checked' : '' ?>> Featured (shown big at the top)</label>
                <?php if ($canPublish): ?>
                    <button type="submit" name="action" value="publish" class="btn btn-primary btn-block"><?= icon('check', 16) ?><?= $isLive ? 'Update' : 'Publish' ?></button>
                    <button type="submit" name="action" value="save" class="btn btn-light btn-block" style="margin-top:8px"><?= $isLive ? 'Save (stays live)' : 'Save draft' ?></button>
                    <?php if ($post && $post['status'] === 'published'): ?>
                        <button type="submit" name="action" value="unpublish" class="btn btn-light btn-block" style="margin-top:8px"><?= icon('eye-off', 15) ?>Unpublish</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button type="submit" name="action" value="submit" class="btn btn-primary btn-block"><?= icon('check', 16) ?>Send for review</button>
                    <button type="submit" name="action" value="save" class="btn btn-light btn-block" style="margin-top:8px">Save draft</button>
                <?php endif; ?>
                <?php if ($revCount): ?><a class="btn btn-light btn-block" style="margin-top:8px" href="revisions.php?entity=post&id=<?= $id ?>"><?= icon('history', 15) ?>Revisions (<?= $revCount ?>)</a><?php endif; ?>
            </div></div>
            <div class="card mb"><div class="card-body">
                <div class="field"><label class="lbl">Category</label><select name="category_id"><option value="">— None —</option>
                    <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$f['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
                    <?php if (can('posts.categories')): ?><div class="hint"><a href="categories.php?type=<?= $type ?>">Manage categories</a></div><?php endif; ?></div>
                <div class="field"><label class="lbl">Tags</label><input type="text" name="tags" value="<?= e((string)$f['tags']) ?>" placeholder="kalshi, polymarket, fees"></div>
                <div class="field"><label class="lbl">Author name</label><input type="text" name="author_name" value="<?= e((string)$f['author_name']) ?>" maxlength="100"></div>
            </div></div>
            <div class="card mb"><div class="card-body">
                <?= image_field('cover_image', $f['cover_image'], 'Cover image') ?>
                <div class="field"><label class="lbl">Cover image description (alt)</label><input type="text" name="cover_alt" value="<?= e((string)$f['cover_alt']) ?>" maxlength="255"></div>
            </div></div>
        </div>
    </div>
</form>
<?= media_modal() ?>
<?php layout_end();
