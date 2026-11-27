<?php
/** Preview any post (drafts, scheduled) exactly as it will look on the site. */
require __DIR__ . '/includes/cms-ui.php';
require_admin();
if (!can('posts.write') && !can('posts.publish')) deny_page();
require_once SPH_ROOT . '/app/blog.php';

$row = q('SELECT type, slug FROM cms_posts WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$row) { http_response_code(404); exit('Post not found'); }
$post = blog_post($row['type'], $row['slug'], true);
$preview = true;

// the article uses the public site's fonts, Tailwind and icons
header_remove('Content-Security-Policy');
header('X-Frame-Options: SAMEORIGIN');
require SPH_ROOT . '/app/views/article.php';
