<?php
/**
 * Renders a full static HTML page with CMS changes applied: global header/footer, SEO tags,
 * announcement bar, tracking code and the live-markets ticker. Used by /page.php and the admin preview.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/site.php';

/** [start, end) of the header block: everything after <body> up to the end of the mobile menu. */
function sph_region_header(string $html): ?array
{
    if (!preg_match('~<body\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) return null;
    $s = $m[0][1] + strlen($m[0][0]);
    $menu = strpos($html, '<aside id="mobileMenu"', $s);
    $hdr = strpos($html, 'id="siteHeader"', $s);
    if ($menu === false || $hdr === false || $hdr > $menu) return null;
    $e = stripos($html, '</aside>', $menu);
    return $e === false ? null : [$s, $e + 8];
}

/** [start, end) of the standard site footer (<footer class="foot">…</footer>). */
function sph_region_footer(string $html): ?array
{
    $s = strpos($html, '<footer class="foot"');
    if ($s === false) return null;
    $e = stripos($html, '</footer>', $s);
    return $e === false ? null : [$s, $e + 9];
}

function sph_swap_chrome(string $html, string $mark = ''): string
{
    $f = sph_region_footer($html);
    if ($f) $html = substr($html, 0, $f[0]) . $mark . sph_chrome('footer') . substr($html, $f[1]);
    $h = sph_region_header($html);
    if ($h) $html = substr($html, 0, $h[0]) . "\n" . $mark . sph_chrome('header') . substr($html, $h[1]);
    return $html;
}

/** Full page HTML -> HTML ready to send. */
function sph_render_page(string $html, string $path, bool $useChrome = true): string
{
    if ($useChrome) $html = sph_swap_chrome($html);

    // SEO: page's own <title>/<meta description>, overridden by the CMS when set
    $title = preg_match('~<title>(.*?)</title>~is', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : '';
    $desc = preg_match('~<meta\s+name="description"\s+content="([^"]*)"~i', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    $extra = sph_seo($path, $title, $desc);
    $html = preg_replace('~<title>.*?</title>~is', '<title>' . sph_h($title) . '</title>', $html, 1, $n);
    if (!$n) $extra = '<title>' . sph_h($title) . '</title>' . "\n" . $extra;
    if ($desc !== '') {
        $html = preg_replace('~<meta\s+name="description"\s+content="[^"]*"\s*/?>~i', '', $html);
        $extra = '<meta name="description" content="' . sph_h($desc) . '">' . "\n    " . $extra;
    }
    if (stripos($html, 'rel="canonical"') === false) {
        $extra = '<link rel="canonical" href="' . sph_h(SPH_SITE_URL . $path) . '">' . "\n    " . $extra;
    }

    $live = strpos($html, 'id="mk-ticker"') !== false && strpos($html, '/assets/markets.js') === false;
    if ($live) {
        $v = @filemtime(SPH_ROOT . '/assets/markets.css');
        $extra .= '<link rel="stylesheet" href="/assets/markets.css?v=' . $v . '">' . "\n";
    }
    $pos = stripos($html, '</head>');
    if ($pos !== false) $html = substr($html, 0, $pos) . $extra . substr($html, $pos);
    $start = sph_body_start();
    $html = preg_replace_callback('~<body\b[^>]*>~i', fn($m) => $m[0] . "\n" . $start, $html, 1);

    $end = sph_body_end();
    if ($live) $end = '<script src="/assets/markets.js?v=' . @filemtime(SPH_ROOT . '/assets/markets.js') . '" defer></script>' . "\n" . $end;
    $pos = strripos($html, '</body>');
    return $pos === false ? $html . $end : substr($html, 0, $pos) . $end . "\n" . substr($html, $pos);
}
