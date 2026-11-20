<?php
/** The page as shown inside the visual editor's iframe: real design + data-sph-i markers on editable tags. */
require __DIR__ . '/includes/ve.php';
require_perm('pages.edit');

$t = ve_target((string)($_GET['p'] ?? ''));
if (!$t) { http_response_code(404); exit('Page not found'); }

$tagged = ve_tag_render($t['source']);
$html = $t['key'] === '@chrome' ? ve_chrome_shell($tagged) : ($t['use_chrome'] ? sph_swap_chrome($tagged) : $tagged);

// The page loads its own fonts, Tailwind, scripts and images: relax the admin CSP for this frame only.
header_remove('Content-Security-Policy');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Content-Type: text/html; charset=utf-8');
echo $html;
