<?php
require __DIR__ . '/includes/cms-ui.php';
require_perm('posts.categories');
$type = ($_GET['type'] ?? $_POST['type'] ?? 'blog') === 'news' ? 'news' : 'blog';
$back = "categories.php?type=$type";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf($back);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    $slug = cms_slugify(trim((string)($_POST['slug'] ?? '')) ?: $name);
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
    $sort = (int)($_POST['sort_order'] ?? 0);
    if (in_array($action, ['add', 'update'], true)) {
        if ($name === '') { flash('error', 'Name is required.'); redirect($back); }
        if (q('SELECT id FROM cms_categories WHERE type = ? AND slug = ? AND id <> ?', [$type, $slug, $id])->fetchColumn()) {
            flash('error', "The URL “$slug” is already used by another category."); redirect($back);
        }
    }
    if ($action === 'add') {
        q('INSERT INTO cms_categories (type, name, slug, description, sort_order) VALUES (?, ?, ?, ?, ?)', [$type, $name, $slug, $desc ?: null, $sort]);
        audit('category.create', 'category', db()->lastInsertId(), $name);
        flash('success', "Category “$name” added.");
    } elseif ($action === 'update') {
        q('UPDATE cms_categories SET name = ?, slug = ?, description = ?, sort_order = ? WHERE id = ? AND type = ?', [$name, $slug, $desc ?: null, $sort, $id, $type]);
        audit('category.update', 'category', $id, $name);
        flash('success', "Category “$name” saved.");
    } elseif ($action === 'delete') {
        q('UPDATE cms_posts SET category_id = NULL WHERE category_id = ?', [$id]);
        q('DELETE FROM cms_categories WHERE id = ?', [$id]);
        audit('category.delete', 'category', $id);
        flash('success', 'Category deleted. Its posts are now uncategorised.');
    }
    redirect($back);
}

$cats = q('SELECT c.*, (SELECT COUNT(*) FROM cms_posts p WHERE p.category_id = c.id) AS n FROM cms_categories c WHERE c.type = ? ORDER BY c.sort_order, c.name', [$type])->fetchAll();
layout_start('Categories', 'categories', 'Group posts by topic. Each category gets its own page at /' . $type . '/category/…');
echo cms_assets();
?>
<div class="card mb">
    <div class="tabs"><a class="<?= $type === 'blog' ? 'on' : '' ?>" href="categories.php?type=blog">Blog</a><a class="<?= $type === 'news' ? 'on' : '' ?>" href="categories.php?type=news">News</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Name</th><th>URL</th><th>Description</th><th>Order</th><th class="text-right">Posts</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): $fid = 'cat' . (int)$c['id']; ?>
            <tr>
                <td><form id="<?= $fid ?>" method="post" class="form"><?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="update"></form>
                    <input form="<?= $fid ?>" class="input" type="text" name="name" value="<?= e($c['name']) ?>" maxlength="100" required></td>
                <td><input form="<?= $fid ?>" class="input mono" type="text" name="slug" value="<?= e($c['slug']) ?>" maxlength="120"></td>
                <td><input form="<?= $fid ?>" class="input" type="text" name="description" value="<?= e((string)$c['description']) ?>" maxlength="500" placeholder="Optional intro shown on the category page"></td>
                <td style="width:90px"><input form="<?= $fid ?>" class="input" type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></td>
                <td class="text-right"><?= (int)$c['n'] ?></td>
                <td><div class="actions">
                    <button form="<?= $fid ?>" class="btn btn-light btn-sm"><?= icon('check', 14) ?>Save</button>
                    <a class="btn btn-light btn-icon" href="/<?= $type ?>/category/<?= e($c['slug']) ?>" target="_blank" rel="noopener" title="View"><?= icon('eye', 15) ?></a>
                    <form method="post" class="inline" data-confirm="Delete category “<?= e($c['name']) ?>”? Its <?= (int)$c['n'] ?> posts stay, without a category."><?= csrf_field() ?>
                        <input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="delete">
                        <button class="btn btn-danger btn-icon" title="Delete"><?= icon('trash', 15) ?></button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$cats): ?><tr><td colspan="6" class="muted">No categories yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<div class="card"><div class="card-head"><h2>Add a <?= $type ?> category</h2></div><div class="card-body">
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="action" value="add">
        <div class="form-row">
            <div class="field"><label class="lbl">Name</label><input type="text" name="name" maxlength="100" required data-slug-src placeholder="e.g. Election Markets"></div>
            <div class="field"><label class="lbl">URL</label><input type="text" name="slug" maxlength="120" data-slug class="mono" placeholder="auto"></div>
        </div>
        <div class="form-row">
            <div class="field"><label class="lbl">Description</label><input type="text" name="description" maxlength="500"></div>
            <div class="field"><label class="lbl">Order</label><input type="number" name="sort_order" value="<?= count($cats) ?>"></div>
        </div>
        <button class="btn btn-primary"><?= icon('plus', 16) ?>Add category</button>
    </form>
</div></div>
<?php layout_end();
