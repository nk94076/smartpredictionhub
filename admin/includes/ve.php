<?php
/** Visual editor helpers: what is being edited ('@chrome' = site header & footer, else a static page path). */
require_once __DIR__ . '/cms-ui.php';
require_once __DIR__ . '/../../app/render.php';

/** ['key', 'title', 'source', 'use_chrome'] or null */
function ve_target(string $p): ?array
{
    if ($p === '@chrome') {
        return ['key' => '@chrome', 'title' => 'Header & Footer', 'source' => cms_chrome_source(), 'use_chrome' => false];
    }
    $path = cms_clean_path($p);
    if ($path === null || !isset(cms_static_pages()[$path])) return null;
    $row = cms_page_row($path);
    return ['key' => $path, 'title' => $path, 'source' => cms_static_source($path), 'use_chrome' => !$row || $row['use_chrome']];
}

/** Save new source for a target (keeps a revision of the previous one) and refresh the public cache. */
function ve_save(array $t, string $src): ?string
{
    if ($t['key'] === '@chrome') {
        if (!cms_chrome_save($src)) return 'The <!--SPH:FOOTER--> line separating header and footer is missing.';
        audit('chrome.update', 'chrome', 'main');
    } else {
        if (stripos($src, '</html>') === false || stripos($src, '<body') === false) return 'This does not look like a full HTML page (<body> … </html> missing).';
        $row = cms_page_ensure($t['key'], 'static');
        cms_revision_add('page', $t['key'], $row['content'] === null ? 'Original design file' : null, $t['source']);
        q('UPDATE cms_pages SET content = ?, kind = ?, updated_by = ? WHERE path = ?', [$src, 'static', current_admin()['id'], $t['key']]);
        audit('page.update', 'page', $t['key']);
    }
    cms_publish_cache();
    return null;
}

/** Wrap the header/footer source in the home page's <head> so it renders with the site styles + menu script. */
function ve_chrome_shell(string $tagged): string
{
    $index = (string)file_get_contents(SPH_ROOT . '/index.html');
    preg_match('~<body\b[^>]*>~i', $index, $m, PREG_OFFSET_CAPTURE);
    $head = substr($index, 0, $m[0][1] + strlen($m[0][0]));
    $scripts = '';
    $region = sph_region_header($index);
    if ($region && preg_match('~<script>.*?</script>~s', $index, $s, 0, $region[1])) $scripts = $s[0];
    $parts = explode(CMS_CHROME_SPLIT, $tagged);
    $mid = '<section style="padding:120px 20px;text-align:center;background:#F7F6FD;color:#8A86A8;font:500 15px Poppins,sans-serif;border:2px dashed #DDD8F5;margin:30px">'
        . 'Page content appears here — this screen edits only the header (top) and footer (bottom) used on every page.</section>';
    return $head . "\n" . $parts[0] . $mid . ($parts[1] ?? '') . "\n" . $scripts . "\n</body>\n</html>";
}
