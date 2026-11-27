<?php
/**
 * Front controller for content pages (nginx sends every extension-less URL that is not a PHP page here):
 *   1. CMS redirects   2. pages hidden in the CMS   3. pages created in the CMS
 *   4. the designed .html pages (CMS-edited copy if any) with the shared header/footer + SEO   5. 404
 * Reads only cache files written by the admin — no database needed.
 */
defined('SPH_APP') || define('SPH_APP', true);
require_once __DIR__ . '/app/render.php';

$path = sph_norm_path((string)($_GET['p'] ?? $_SERVER['REQUEST_URI'] ?? '/'));
if ($path === '/index') $path = '/';

function sph_404(): void
{
    http_response_code(404);
    $pageTitle = 'Page not found | ' . sph_setting('site_name', 'Smart Prediction Hub');
    $pageDescription = '';
    $extraHead = '<meta name="robots" content="noindex"><link rel="stylesheet" href="/assets/blog.css?v=' . @filemtime(__DIR__ . '/assets/blog.css') . '">';
    require __DIR__ . '/includes/layout-top.php';
    echo '<section class="blog-hero"><div class="page-wrap"><span class="page-kicker"><i class="fa-solid fa-compass"></i> 404</span>'
        . '<h1 class="page-title">This page could not be found.</h1><p class="page-lede">The link may be old or mistyped. Try live markets or the latest guides instead.</p>'
        . '<p style="margin-top:26px;display:flex;gap:10px;flex-wrap:wrap"><a href="/markets" style="padding:12px 18px;border-radius:10px;background:#5B4BFF;color:#fff;font-weight:600">Live markets</a>'
        . '<a href="/blog" style="padding:12px 18px;border-radius:10px;border:1px solid rgba(255,255,255,.2);color:#fff;font-weight:600">Read the blog</a>'
        . '<a href="/" style="padding:12px 18px;border-radius:10px;border:1px solid rgba(255,255,255,.2);color:#fff;font-weight:600">Home</a></p></div></section>';
    require __DIR__ . '/includes/layout-bottom.php';
    exit;
}

// 1. redirects
if ($r = sph_redirect_for($path)) {
    header('Location: ' . $r['to'], true, $r['code'] === 302 ? 302 : 301);
    exit;
}
if (!preg_match('~^/([A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*)?$~', $path)) sph_404();

$meta = sph_page_meta($path);
// 2. hidden
if ($meta && ($meta['status'] === 'hidden' || ($meta['kind'] === 'custom' && $meta['status'] !== 'published'))) sph_404();

header('Content-Type: text/html; charset=utf-8');

// 3. CMS-created page
if ($meta && $meta['kind'] === 'custom') {
    $content = !empty($meta['file']) ? @file_get_contents(SPH_CMS_DIR . '/pages/' . basename($meta['file'])) : '';
    $pageTitle = ($meta['title'] ?: 'Page') . ' | ' . sph_setting('site_name', 'Smart Prediction Hub');
    $pageDescription = $meta['meta_description'] ?: '';
    $canonical = SPH_SITE_URL . $path;
    $extraHead = '<link rel="stylesheet" href="/assets/blog.css?v=' . @filemtime(__DIR__ . '/assets/blog.css') . '">';
    require __DIR__ . '/includes/layout-top.php';
    echo '<section class="blog-hero"><div class="page-wrap"><h1 class="page-title">' . sph_h($meta['title']) . '</h1></div></section>'
        . '<main class="page-wrap article-shell" style="padding-top:44px"><article class="article-card card">' . $content . '</article></main>';
    require __DIR__ . '/includes/layout-bottom.php';
    exit;
}

// 4. designed static page: root *.html or reviews/*.html
if (!preg_match('~^/(reviews/)?[A-Za-z0-9_-]+$~', $path) && $path !== '/') sph_404();
$file = $path === '/' ? __DIR__ . '/index.html' : __DIR__ . $path . '.html';
if (in_array($path, ['/blog', '/post'], true) || !is_file($file)) sph_404();
$html = null;
if ($meta && !empty($meta['file'])) $html = @file_get_contents(SPH_CMS_DIR . '/pages/' . basename($meta['file']));
if (!$html) $html = (string)file_get_contents($file);

echo sph_render_page($html, $path, !$meta || !empty($meta['use_chrome']));
