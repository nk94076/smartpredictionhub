<?php
/**
 * Public-side CMS helpers. NO database: everything is read from files that the admin CMS writes
 * into app/cache/cms/ (see cms_publish_cache() in app/cms.php), so pages stay up even if MySQL is down.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

defined('SPH_ROOT') || define('SPH_ROOT', dirname(__DIR__));
defined('SPH_CMS_DIR') || define('SPH_CMS_DIR', SPH_ROOT . '/app/cache/cms');
defined('SPH_SITE_URL') || define('SPH_SITE_URL', 'https://smartpredictionhub.com');

function sph_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sph_json_file(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $f = SPH_CMS_DIR . '/' . $name;
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        $cache[$name] = is_array($d) ? $d : [];
    }
    return $cache[$name];
}

function sph_settings(): array
{
    return sph_json_file('settings.json');
}

function sph_setting(string $k, $default = '')
{
    $s = sph_settings();
    return (isset($s[$k]) && $s[$k] !== '') ? $s[$k] : $default;
}

/** "/Reviews/x/?a=1" -> "/Reviews/x" ; "" -> "/" */
function sph_norm_path(string $uri): string
{
    $p = parse_url($uri, PHP_URL_PATH);
    $p = '/' . trim(rawurldecode((string)$p), '/');
    return preg_replace('#/+#', '/', $p);
}

function sph_current_path(): string
{
    return sph_norm_path($_SERVER['REQUEST_URI'] ?? '/');
}

/** Page record from the CMS (SEO, status, custom content file) or null. */
function sph_page_meta(string $path): ?array
{
    $pages = sph_json_file('pages.json');
    return $pages[$path] ?? null;
}

function sph_redirect_for(string $path): ?array
{
    $r = sph_json_file('redirects.json');
    if (isset($r[$path])) return $r[$path];
    $lower = strtolower($path);
    return $r[$lower] ?? null;
}

/** Global header / footer HTML (CMS-edited copy, else the default built from index.html). */
function sph_chrome(string $part): string
{
    $part = $part === 'footer' ? 'footer' : 'header';
    $f = SPH_CMS_DIR . "/$part.html";
    if (!is_file($f)) $f = SPH_ROOT . "/includes/site-$part.html";
    return is_file($f) ? (string)file_get_contents($f) : '';
}

/** Market overrides: ['hidden' => [id => 1], 'pinned' => [id => 1], 'titles' => [id => t], 'categories' => [id => c]] */
function sph_market_overrides(): array
{
    return sph_json_file('markets.json') + ['hidden' => [], 'pinned' => [], 'titles' => [], 'categories' => [], 'disabled_platforms' => []];
}

/**
 * Apply the CMS SEO override for $path to the page's own title/description.
 * Returns the extra <head> tags (OG/Twitter, robots, tracking code).
 */
function sph_seo(string $path, string &$title, string &$desc, string $image = ''): string
{
    $m = sph_page_meta($path);
    if ($m) {
        if (!empty($m['meta_title'])) $title = $m['meta_title'];
        if (!empty($m['meta_description'])) $desc = $m['meta_description'];
        if (!empty($m['og_image'])) $image = $m['og_image'];
    }
    if ($image === '') $image = (string)sph_setting('default_og_image');
    if ($image !== '' && $image[0] === '/') $image = SPH_SITE_URL . $image;
    $noindex = !empty($m['noindex']) || sph_setting('site_noindex') === '1';

    $t = [];
    $t[] = '<meta property="og:type" content="website">';
    $t[] = '<meta property="og:site_name" content="' . sph_h(sph_setting('site_name', 'Smart Prediction Hub')) . '">';
    $t[] = '<meta property="og:title" content="' . sph_h($title) . '">';
    if ($desc !== '') $t[] = '<meta property="og:description" content="' . sph_h($desc) . '">';
    $t[] = '<meta property="og:url" content="' . sph_h(SPH_SITE_URL . ($path === '/' ? '/' : $path)) . '">';
    if ($image !== '') {
        $t[] = '<meta property="og:image" content="' . sph_h($image) . '">';
        $t[] = '<meta name="twitter:card" content="summary_large_image">';
    }
    if ($noindex) $t[] = '<meta name="robots" content="noindex, nofollow">';
    return implode("\n    ", $t) . "\n" . sph_setting('head_code') . "\n";
}

/** Announcement bar shown at the very top of every page (Settings → Announcement). */
function sph_body_start(): string
{
    if (sph_setting('announce_on') !== '1' || sph_setting('announce_text') === '') return '';
    $bg = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)sph_setting('announce_bg')) ? sph_setting('announce_bg') : '#6D4AFF';
    $text = sph_h(sph_setting('announce_text'));
    $link = (string)sph_setting('announce_link');
    $inner = $link !== ''
        ? '<a href="' . sph_h($link) . '" style="color:inherit;text-decoration:underline;text-underline-offset:3px">' . $text . '</a>'
        : $text;
    return '<div class="sph-announce" style="background:' . $bg . ';color:#fff;text-align:center;padding:9px 16px;font:600 14px/1.4 Poppins,sans-serif;position:relative;z-index:200">'
        . $inner . '</div>' . "\n";
}

function sph_body_end(): string
{
    return (string)sph_setting('body_code');
}
