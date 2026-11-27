<?php
/** /blog/<slug> and /news/<slug> — one published article. */
define('SPH_APP', true);
require __DIR__ . '/app/blog.php';

$type = blog_type((string)($_GET['type'] ?? 'blog'));
$slug = (string)($_GET['slug'] ?? '');
$post = preg_match('/^[a-z0-9-]{1,200}$/', $slug) ? blog_post($type, $slug) : null;

if (!$post) {
    $r = sph_redirect_for(sph_current_path());
    if ($r) { header('Location: ' . $r['to'], true, $r['code']); exit; }
    http_response_code(404);
    $_GET['p'] = sph_current_path();
    require __DIR__ . '/page.php';
    exit;
}

// count a view (not for bots)
if (!preg_match('~bot|crawl|spider|slurp|preview~i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
    q('UPDATE cms_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
}
$preview = false;
require __DIR__ . '/app/views/article.php';
