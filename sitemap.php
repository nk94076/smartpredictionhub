<?php
/** /sitemap.xml — every public page, post and category (pages hidden or set to noindex in the CMS are left out). */
define('SPH_APP', true);
require __DIR__ . '/app/blog.php';
require_once __DIR__ . '/app/markets/config.php';

$urls = [];
$add = function (string $path, ?string $mod = null, string $freq = 'weekly', string $prio = '0.6') use (&$urls) {
    $m = sph_page_meta($path);
    if ($m && ($m['status'] !== 'published' || !empty($m['noindex']))) return;
    $urls[$path] = [$mod, $freq, $prio];
};

$add('/', null, 'hourly', '1.0');
$add('/markets', null, 'hourly', '0.9');
foreach (array_keys(MK_CATEGORIES) as $c) $add('/markets/' . $c, null, 'hourly', '0.7');
foreach (array_merge(glob(__DIR__ . '/*.html') ?: [], glob(__DIR__ . '/reviews/*.html') ?: []) as $f) {
    $p = substr($f, strlen(__DIR__), -5);
    if (in_array($p, ['/index', '/blog', '/post', '/index-2', '/coming-soon'], true)) continue;
    $add($p, date('Y-m-d', filemtime($f)));
}
foreach (sph_json_file('pages.json') as $p => $m) {
    if ($m['kind'] === 'custom') $add($p, substr((string)$m['updated'], 0, 10), 'monthly', '0.5');
}
foreach (['blog', 'news'] as $t) {
    $add('/' . $t, null, 'daily', '0.8');
    foreach (blog_categories($t) as $c) if ($c['n']) $add("/$t/category/" . $c['slug'], null, 'weekly', '0.5');
}
foreach (q("SELECT type, slug, updated_at FROM cms_posts p WHERE " . BLOG_LIVE . ' ORDER BY published_at DESC LIMIT 5000')->fetchAll() as $p) {
    $add('/' . $p['type'] . '/' . $p['slug'], substr($p['updated_at'], 0, 10), $p['type'] === 'news' ? 'monthly' : 'weekly', '0.7');
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $p => [$mod, $freq, $prio]) {
    echo '  <url><loc>' . htmlspecialchars(SPH_SITE_URL . $p, ENT_XML1) . '</loc>' . ($mod ? "<lastmod>$mod</lastmod>" : '')
        . "<changefreq>$freq</changefreq><priority>$prio</priority></url>\n";
}
echo '</urlset>' . "\n";
