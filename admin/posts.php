<?php
require __DIR__ . '/includes/cms-ui.php';
require_admin();
if (!can('posts.write') && !can('posts.publish')) deny_page();
$me = current_admin();
$type = ($_GET['type'] ?? 'blog') === 'news' ? 'news' : 'blog';
$label = $type === 'news' ? 'News' : 'Blog Posts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf("posts.php?type=$type");
    if (!can('posts.publish')) { flash('error', 'You do not have permission to delete posts.'); redirect("posts.php?type=$type"); }
    $id = (int)($_POST['id'] ?? 0);
    $p = q('SELECT id, title FROM cms_posts WHERE id = ?', [$id])->fetch();
    if ($p && ($_POST['action'] ?? '') === 'delete') {
        q('DELETE FROM cms_posts WHERE id = ?', [$id]);
        audit('post.delete', 'post', $id, $p['title']);
        flash('success', '"' . $p['title'] . '" was deleted.');
    }
    redirect("posts.php?type=$type");
}

$status = (string)($_GET['status'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);
$where = ['p.type = ?'];
$params = [$type];
if ($status === 'scheduled') { $where[] = "p.status = 'published' AND p.published_at > NOW()"; }
elseif ($status === 'published') { $where[] = "p.status = 'published' AND p.published_at <= NOW()"; }
elseif (in_array($status, ['draft', 'pending'], true)) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($search !== '') { $where[] = '(p.title LIKE ? OR p.slug LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($cat) { $where[] = 'p.category_id = ?'; $params[] = $cat; }
$w = implode(' AND ', $where);

$total = (int)q("SELECT COUNT(*) FROM cms_posts p WHERE $w", $params)->fetchColumn();
$pg = paginate($total, 20);
$posts = q("SELECT p.*, c.name AS cat_name, a.username AS author_user FROM cms_posts p
            LEFT JOIN cms_categories c ON c.id = p.category_id LEFT JOIN admin_users a ON a.id = p.author_id
            WHERE $w ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params)->fetchAll();
$counts = q("SELECT
        COUNT(*) AS allc,
        SUM(status = 'published' AND published_at <= NOW()) AS published,
        SUM(status = 'published' AND published_at > NOW()) AS scheduled,
        SUM(status = 'draft') AS draft, SUM(status = 'pending') AS pending
    FROM cms_posts WHERE type = ?", [$type])->fetch();
$cats = q('SELECT id, name FROM cms_categories WHERE type = ? ORDER BY sort_order, name', [$type])->fetchAll();

layout_start($label, 'posts-' . $type, $type === 'news' ? 'Short, timely news stories — shown at /news' : 'Guides and articles — shown at /blog',
    '<a class="btn btn-light" href="/' . $type . '" target="_blank" rel="noopener">' . icon('globe', 16) . 'View ' . $type . '</a>'
    . '<a class="btn btn-primary" href="post-edit.php?type=' . $type . '">' . icon('plus', 16) . 'New ' . ($type === 'news' ? 'story' : 'post') . '</a>');
echo cms_assets();
$tab = function ($key, $text, $n) use ($status, $type) {
    return '<a class="' . ($status === $key ? 'on' : '') . '" href="posts.php?type=' . $type . ($key ? '&status=' . $key : '') . '">' . $text
        . ' <span class="badge b-gray">' . (int)$n . '</span></a>';
};
?>
<div class="card">
    <div class="tabs">
        <?= $tab('', 'All', $counts['allc']) ?><?= $tab('published', 'Published', $counts['published']) ?><?= $tab('scheduled', 'Scheduled', $counts['scheduled']) ?>
        <?= $tab('pending', 'In review', $counts['pending']) ?><?= $tab('draft', 'Drafts', $counts['draft']) ?>
    </div>
    <form class="filters" method="get">
        <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
        <div class="search"><?= icon('search', 16) ?><input type="text" name="q" value="<?= e($search) ?>" placeholder="Search titles…"></div>
        <select name="cat"><option value="">All categories</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-light">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th></th><th>Title</th><th>Category</th><th>Author</th><th>Status</th><th>Date</th><th class="text-right">Views</th><th></th></tr></thead>
            <tbody>
            <?php if (!$posts): ?><tr><td colspan="8"><div class="empty-state"><?= icon('file', 36) ?><p>No posts here yet.</p>
                <a class="btn btn-primary" href="post-edit.php?type=<?= $type ?>"><?= icon('plus', 16) ?>Write the first one</a></div></td></tr><?php endif; ?>
            <?php foreach ($posts as $p):
                $mine = (int)$p['author_id'] === (int)$me['id'];
                $canEdit = can('posts.publish') || ($mine && $p['status'] !== 'published');
                $live = $p['status'] === 'published' && strtotime((string)$p['published_at']) <= time(); ?>
                <tr>
                    <td><?php if ($p['cover_image']): ?><img class="thumb" src="<?= e($p['cover_image']) ?>" alt=""><?php else: ?><span class="thumb"></span><?php endif; ?></td>
                    <td><strong><?= $canEdit ? '<a href="post-edit.php?id=' . (int)$p['id'] . '">' . e($p['title']) . '</a>' : e($p['title']) ?></strong>
                        <?= $p['featured'] ? ' <span class="badge b-amber">Featured</span>' : '' ?><span class="sub mono">/<?= $type ?>/<?= e($p['slug']) ?></span></td>
                    <td><?= e($p['cat_name'] ?: '—') ?></td>
                    <td class="nowrap"><?= e($p['author_name'] ?: ($p['author_user'] ? '@' . $p['author_user'] : '—')) ?></td>
                    <td><?= post_status_badge($p) ?></td>
                    <td class="nowrap"><?= e(fmt_dt($p['published_at'] ?: $p['created_at'], 'd M Y')) ?><span class="sub">Updated <?= e(time_ago($p['updated_at'])) ?></span></td>
                    <td class="text-right"><?= number_format((int)$p['views']) ?></td>
                    <td><div class="actions">
                        <?php if ($canEdit): ?><a class="btn btn-light btn-sm" href="post-edit.php?id=<?= (int)$p['id'] ?>"><?= icon('edit', 14) ?>Edit</a><?php endif; ?>
                        <a class="btn btn-light btn-icon" href="<?= $live ? '/' . $type . '/' . e($p['slug']) : 'post-preview.php?id=' . (int)$p['id'] ?>" target="_blank" rel="noopener" title="<?= $live ? 'View' : 'Preview' ?>"><?= icon('eye', 15) ?></a>
                        <?php if (can('posts.publish')): ?>
                        <form method="post" class="inline" data-confirm="Delete “<?= e($p['title']) ?>” permanently?"><?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="delete">
                            <button class="btn btn-danger btn-icon" title="Delete"><?= icon('trash', 15) ?></button></form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($pg) ?>
</div>
<?php layout_end();
