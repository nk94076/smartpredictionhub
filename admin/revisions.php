<?php
/** Revision history + restore for posts, pages and the header/footer. ?entity=post|page|chrome&id=… */
require __DIR__ . '/includes/ve.php';
require_admin();

$entity = (string)($_GET['entity'] ?? $_POST['entity'] ?? '');
$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
$back = 'pages.php';
if ($entity === 'post') {
    $post = q('SELECT * FROM cms_posts WHERE id = ?', [(int)$id])->fetch();
    if (!$post) { flash('error', 'Post not found.'); redirect('posts.php'); }
    if (!can('posts.publish') && !((int)$post['author_id'] === (int)current_admin()['id'] && $post['status'] !== 'published' && can('posts.write'))) deny_page();
    $title = $post['title'];
    $back = 'post-edit.php?id=' . (int)$id;
} elseif ($entity === 'page' || $entity === 'chrome') {
    require_perm('pages.edit');
    if ($entity === 'chrome') { $id = 'main'; $title = 'Header & Footer'; $back = 'page-edit.php?p=' . rawurlencode('@chrome'); }
    else {
        $id = cms_clean_path($id) ?? '';
        $row = cms_page_row($id);
        $title = $id;
        $back = ($row && $row['kind'] === 'custom') ? 'page-custom.php?p=' . rawurlencode($id) : 'page-edit.php?p=' . rawurlencode($id);
    }
} else {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf("revisions.php?entity=$entity&id=" . rawurlencode($id));
    $rev = q('SELECT * FROM cms_revisions WHERE id = ? AND entity = ? AND entity_id = ?', [(int)$_POST['rev'], $entity, $id])->fetch();
    if (!$rev) { flash('error', 'Revision not found.'); redirect($back); }
    if ($entity === 'post') {
        cms_revision_add('post', $id, $post['title'], $post['content']);
        q('UPDATE cms_posts SET title = COALESCE(?, title), content = ?, read_minutes = ? WHERE id = ?', [$rev['title'], $rev['content'], cms_read_minutes((string)$rev['content']), (int)$id]);
    } elseif ($entity === 'chrome') {
        cms_chrome_save((string)$rev['content']);
        cms_publish_cache();
    } else {
        $row = cms_page_row($id);
        if ($row && $row['kind'] === 'custom') {
            cms_revision_add('page', $id, $row['title'], $row['content']);
            q('UPDATE cms_pages SET content = ?, title = COALESCE(?, title) WHERE path = ?', [$rev['content'], $rev['title'], $id]);
        } else {
            $row = cms_page_ensure($id, 'static');
            cms_revision_add('page', $id, null, cms_static_source($id));
            q('UPDATE cms_pages SET content = ? WHERE path = ?', [$rev['content'], $id]);
        }
        cms_publish_cache();
    }
    audit($entity . '.restore', $entity, $id, 'revision #' . $rev['id']);
    flash('success', 'Restored the version from ' . fmt_dt($rev['created_at']) . '. (The version you replaced was kept as a revision.)');
    redirect($back);
}

$revs = cms_revisions($entity, $id);
layout_start('Revisions', $entity === 'post' ? 'posts-' . ($post['type'] ?? 'blog') : ($entity === 'chrome' ? 'chrome' : 'pages'),
    '<a href="' . e($back) . '">' . e($title) . '</a> / Revisions');
?>
<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>Saved</th><th>By</th><th>Note</th><th class="text-right">Size</th><th></th></tr></thead>
        <tbody>
        <?php if (!$revs): ?><tr><td colspan="5" class="muted">No earlier versions yet — one is kept every time you save.</td></tr><?php endif; ?>
        <?php foreach ($revs as $r): ?>
            <tr>
                <td class="nowrap"><?= e(fmt_dt($r['created_at'])) ?><span class="sub"><?= e(time_ago($r['created_at'])) ?></span></td>
                <td><?= $r['admin_username'] ? '@' . e($r['admin_username']) : '—' ?></td>
                <td><?= e((string)$r['title']) ?></td>
                <td class="text-right"><?= number_format((int)$r['size'] / 1024, 1) ?> KB</td>
                <td><form method="post" class="inline" data-confirm="Restore this version? The current version is kept as a revision."><?= csrf_field() ?>
                    <input type="hidden" name="entity" value="<?= e($entity) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="rev" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-light btn-sm"><?= icon('history', 14) ?>Restore</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php layout_end();
