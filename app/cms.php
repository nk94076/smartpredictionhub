<?php
/**
 * CMS core (database side): settings, slugs, HTML sanitising, revisions, static-page discovery,
 * the visual-editor engine, and cms_publish_cache() which writes the files the public site reads.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/site.php';

const CMS_SETTING_DEFAULTS = [
    'site_name'        => 'Smart Prediction Hub',
    'default_og_image' => '',
    'site_noindex'     => '0',
    'head_code'        => '',
    'body_code'        => '',
    'announce_on'      => '0',
    'announce_text'    => '',
    'announce_link'    => '',
    'announce_bg'      => '#6D4AFF',
    'social_x'         => '',
    'social_linkedin'  => '',
    'social_facebook'  => '',
    'social_instagram' => '',
    'social_youtube'   => '',
    'social_telegram'  => '',
    'contact_email'    => '',
    'posts_per_page'   => '9',
    'blog_title'       => 'Prediction market news, guides and practical explainers.',
    'blog_intro'       => 'Understand platforms, event contracts, probability, fees and market mechanics without digging through technical documentation.',
    'news_title'       => 'Prediction market news',
    'news_intro'       => 'The latest moves, launches and regulation across Polymarket, Kalshi and every other prediction market.',
    'markets_heading'  => '',
    'markets_intro'    => '',
    'disabled_platforms' => '',
];

/* ---------------- Settings ---------------- */
function cms_settings(): array
{
    $rows = q("SELECT k, v FROM cms_settings WHERE k NOT LIKE 'chrome\\_%' AND k NOT LIKE 'seeded\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge(CMS_SETTING_DEFAULTS, $rows);
}

function cms_setting_get(string $k, ?string $default = null): ?string
{
    $v = q('SELECT v FROM cms_settings WHERE k = ?', [$k])->fetchColumn();
    return $v === false ? $default : $v;
}

function cms_setting_set(string $k, ?string $v): void
{
    q('INSERT INTO cms_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

/* ---------------- Text helpers ---------------- */
function cms_slugify(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['&' => ' and ', '%' => ' percent ']);
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false) $s = $t; }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(substr(trim($s, '-'), 0, 120), '-') ?: 'item';
}

function cms_read_minutes(string $html): int
{
    $words = str_word_count(strip_tags($html));
    return max(1, (int)round($words / 220));
}

function cms_excerpt(string $html, int $len = 180): string
{
    $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1)) . '…' : $t;
}

/** Path typed by an admin -> "/a/b" (lowercase letters, digits, - _ /). Null if invalid. */
function cms_clean_path(string $p): ?string
{
    $p = sph_norm_path($p);
    return preg_match('~^/([a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*)?$~', $p) ? $p : null;
}

/* ---------------- HTML sanitiser for post bodies ---------------- */
const CMS_ALLOWED_TAGS = ['p', 'br', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li',
    'blockquote', 'figure', 'figcaption', 'img', 'hr', 'pre', 'code', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'span', 'div', 'iframe', 'sup', 'sub', 'mark', 'small', 'caption'];
const CMS_ALLOWED_ATTRS = ['class', 'id', 'style', 'title', 'href', 'target', 'rel', 'src', 'alt', 'width', 'height', 'loading',
    'colspan', 'rowspan', 'allowfullscreen', 'frameborder', 'allow', 'data-align'];

function cms_safe_url(string $u, bool $embed = false): bool
{
    $u = trim($u);
    if ($embed) return (bool)preg_match('~^https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com)/~i', $u);
    if ($u === '' || $u[0] === '/' || $u[0] === '#' || $u[0] === '?') return true;
    return (bool)preg_match('~^(https?:|mailto:|tel:)~i', $u);
}

function cms_sanitize(string $html): string
{
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="sph-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('sph-root');
    if (!$root) return '';
    cms_sanitize_node($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}

function cms_sanitize_node(DOMNode $node): void
{
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $c = $node->childNodes->item($i);
        if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) { $node->removeChild($c); continue; }
        if (!$c instanceof DOMElement) continue;
        $tag = strtolower($c->tagName);
        if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math'], true)) {
            $node->removeChild($c); continue;
        }
        if (!in_array($tag, CMS_ALLOWED_TAGS, true)) {           // unknown tag: keep its children
            cms_sanitize_node($c);
            while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
            $node->removeChild($c); continue;
        }
        foreach (iterator_to_array($c->attributes) as $a) {
            $n = strtolower($a->name);
            $v = $a->value;
            $ok = in_array($n, CMS_ALLOWED_ATTRS, true);
            if ($ok && ($n === 'href' || $n === 'src')) $ok = cms_safe_url($v, $tag === 'iframe');
            if ($ok && $n === 'style') $ok = !preg_match('~(expression|javascript:|url\s*\()~i', $v);
            if (!$ok) $c->removeAttribute($a->name);
        }
        if ($tag === 'iframe' && !$c->hasAttribute('src')) { $node->removeChild($c); continue; }
        if ($tag === 'a' && $c->getAttribute('target') === '_blank') $c->setAttribute('rel', 'noopener');
        cms_sanitize_node($c);
    }
}

/* ---------------- Revisions ---------------- */
function cms_revision_add(string $entity, string $id, ?string $title, ?string $content): void
{
    $a = function_exists('current_admin') ? current_admin() : null;
    q('INSERT INTO cms_revisions (entity, entity_id, title, content, admin_id, admin_username) VALUES (?, ?, ?, ?, ?, ?)',
        [$entity, $id, $title, $content, $a['id'] ?? null, $a['username'] ?? null]);
    // keep the newest 30 per item
    $cut = q('SELECT id FROM cms_revisions WHERE entity = ? AND entity_id = ? ORDER BY id DESC LIMIT 1 OFFSET 30', [$entity, $id])->fetchColumn();
    if ($cut) q('DELETE FROM cms_revisions WHERE entity = ? AND entity_id = ? AND id <= ?', [$entity, $id, $cut]);
}

function cms_revisions(string $entity, string $id): array
{
    return q('SELECT id, title, admin_username, created_at, LENGTH(content) AS size FROM cms_revisions
              WHERE entity = ? AND entity_id = ? ORDER BY id DESC', [$entity, $id])->fetchAll();
}

/* ---------------- Pages ---------------- */
/** Paths the CMS never serves as pages. */
const CMS_RESERVED = ['/admin', '/user', '/api', '/app', '/includes', '/deploy', '/assets', '/uploads', '/markets', '/event',
    '/blog', '/news', '/sitemap.xml', '/robots.txt', '/page', '/posts', '/article'];

function cms_is_reserved(string $path): bool
{
    foreach (CMS_RESERVED as $r) {
        if ($path === $r || strpos($path, $r . '/') === 0) return true;
    }
    return false;
}

/** Every .html page on disk: path => file. blog.html / post.html are replaced by the dynamic blog. */
function cms_static_pages(): array
{
    $out = [];
    foreach (array_merge(glob(SPH_ROOT . '/*.html') ?: [], glob(SPH_ROOT . '/reviews/*.html') ?: []) as $f) {
        $rel = substr($f, strlen(SPH_ROOT), -5);
        if (in_array($rel, ['/blog', '/post'], true)) continue;
        $out[$rel === '/index' ? '/' : $rel] = $f;
    }
    ksort($out);
    return $out;
}

/** Built-in PHP pages whose SEO can be edited. */
const CMS_DYNAMIC_PAGES = ['/markets' => 'Live markets', '/blog' => 'Blog', '/news' => 'News'];

function cms_page_row(string $path): ?array
{
    $r = q('SELECT * FROM cms_pages WHERE path = ?', [$path])->fetch();
    return $r ?: null;
}

/** Current HTML source of a static page: the CMS-edited copy, else the file on disk. */
function cms_static_source(string $path): ?string
{
    $row = cms_page_row($path);
    if ($row && $row['content'] !== null && $row['content'] !== '') return $row['content'];
    $files = cms_static_pages();
    return isset($files[$path]) ? (string)file_get_contents($files[$path]) : null;
}

/** Make sure a cms_pages row exists for $path and return it. */
function cms_page_ensure(string $path, string $kind): array
{
    q('INSERT IGNORE INTO cms_pages (path, kind) VALUES (?, ?)', [$path, $kind]);
    return cms_page_row($path);
}

/* ---------------- Header / footer ---------------- */
const CMS_CHROME_SPLIT = '<!--SPH:FOOTER-->';

function cms_chrome_source(): string
{
    return sph_chrome('header') . "\n" . CMS_CHROME_SPLIT . "\n" . sph_chrome('footer');
}

function cms_chrome_save(string $src): bool
{
    $parts = explode(CMS_CHROME_SPLIT, $src);
    if (count($parts) !== 2) return false;
    cms_revision_add('chrome', 'main', 'Header & footer', cms_chrome_source());
    cms_setting_set('chrome_header', trim($parts[0], "\n") . "\n");
    cms_setting_set('chrome_footer', trim($parts[1], "\n") . "\n");
    return true;
}

/* ---------------- Visual editor engine ----------------
 * Every editable tag gets an index (its order in the source). The editor renders the page with
 * data-sph-i="N" on those tags; on save, changes come back as {i, html|attrs|op} and are applied
 * to the ORIGINAL source at the same positions — the rest of the file is never re-serialised.
 */
const VE_TAGS = 'h[1-6]|p|a|span|li|button|small|b|strong|em|label|td|th|blockquote|figcaption|div|img|dt|dd|summary|section';

function ve_protected(string $html): array
{
    preg_match_all('~<script\b.*?</script\s*>|<style\b.*?</style\s*>|<!--.*?-->|<textarea\b.*?</textarea\s*>~is', $html, $m, PREG_OFFSET_CAPTURE);
    return array_map(fn($x) => [$x[1], $x[1] + strlen($x[0])], $m[0]);
}

function ve_in(array $ranges, int $pos): bool
{
    foreach ($ranges as [$s, $e]) {
        if ($pos >= $s && $pos < $e) return true;
        if ($s > $pos) break;
    }
    return false;
}

/** End offset (after ">") of the tag opening at $pos, honouring quoted attribute values. */
function ve_tag_end(string $html, int $pos): int
{
    $len = strlen($html);
    $q = null;
    for ($i = $pos + 1; $i < $len; $i++) {
        $c = $html[$i];
        if ($q !== null) { if ($c === $q) $q = null; continue; }
        if ($c === '"' || $c === "'") { $q = $c; continue; }
        if ($c === '>') return $i + 1;
    }
    return $len;
}

/** List of editable tags: [tag, start, open_end]. */
function ve_tags(string $html): array
{
    $prot = ve_protected($html);
    preg_match_all('~<(' . VE_TAGS . ')(?=[\s/>])~i', $html, $m, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m[1] as $k => [$tag, $_]) {
        $start = $m[0][$k][1];
        if (ve_in($prot, $start)) continue;
        $out[] = [strtolower($tag), $start, ve_tag_end($html, $start)];
    }
    return $out;
}

/** Source with data-sph-i="N" added to every editable tag. */
function ve_tag_render(string $html): string
{
    $tags = ve_tags($html);
    for ($i = count($tags) - 1; $i >= 0; $i--) {
        [$tag, $start] = $tags[$i];
        $at = $start + 1 + strlen($tag);
        $html = substr($html, 0, $at) . ' data-sph-i="' . $i . '"' . substr($html, $at);
    }
    return $html;
}

/** [closeStart, closeEnd] of the element whose opening tag is $t, or null. */
function ve_close(string $html, array $t, array $prot): ?array
{
    [$tag, $start, $openEnd] = $t;
    if ($tag === 'img' || substr($html, $openEnd - 2, 1) === '/') return [$openEnd, $openEnd];
    preg_match_all('~<(/?)' . preg_quote($tag, '~') . '(?=[\s/>])~i', $html, $m, PREG_OFFSET_CAPTURE, $openEnd);
    $depth = 1;
    foreach ($m[0] as $k => [$_, $pos]) {
        if (ve_in($prot, $pos)) continue;
        $depth += $m[1][$k][0] === '/' ? -1 : 1;
        if ($depth === 0) return [$pos, ve_tag_end($html, $pos)];
    }
    return null;
}

function ve_strip_marks(string $s): string
{
    return preg_replace('~\s(data-sph-[a-z-]+|contenteditable|spellcheck)(="[^"]*")?~i', '', $s);
}

/** Rebuild an opening tag with some attributes set (string) or removed (null). */
function ve_set_attrs(string $openTag, string $tag, array $attrs): string
{
    $inner = preg_replace('~^<' . preg_quote($tag, '~') . '|/?>$~i', '', $openTag);
    preg_match_all('~([^\s=/>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~', $inner, $m, PREG_SET_ORDER);
    $list = [];
    foreach ($m as $a) $list[strtolower($a[1])] = [$a[1], $a[2] ?? null];
    foreach ($attrs as $k => $v) {
        $k = strtolower($k);
        if (!preg_match('/^[a-z][a-z0-9_:-]*$/', $k) || strpos($k, 'on') === 0) continue;
        if ($v === null) { unset($list[$k]); continue; }
        $list[$k] = [$k, '"' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"'];
    }
    $s = '<' . $tag;
    foreach ($list as [$n, $v]) $s .= ' ' . $n . ($v !== null ? '=' . $v : '');
    return $s . '>';
}

/**
 * Apply editor changes to the source. $changes: [{i, html?, attrs?, op?: delete|duplicate}].
 * Returns [newHtml, appliedCount, skippedCount].
 */
function ve_apply(string $html, array $changes): array
{
    $tags = ve_tags($html);
    $prot = ve_protected($html);
    $edits = [];
    $skipped = 0;
    foreach ($changes as $c) {
        $i = (int)($c['i'] ?? -1);
        if (!isset($tags[$i])) { $skipped++; continue; }
        $t = $tags[$i];
        $close = ve_close($html, $t, $prot);
        if ($close === null) { $skipped++; continue; }
        $op = $c['op'] ?? '';
        if ($op === 'delete') {
            $edits[] = [$t[1], $close[1], ''];
        } elseif ($op === 'duplicate') {
            $edits[] = [$close[1], $close[1], "\n" . substr($html, $t[1], $close[1] - $t[1])];
        } else {
            if (isset($c['html']) && is_string($c['html']) && $t[0] !== 'img' && $close[0] >= $t[2]) {
                $edits[] = [$t[2], $close[0], ve_strip_marks($c['html'])];
            }
            if (!empty($c['attrs']) && is_array($c['attrs'])) {
                $open = substr($html, $t[1], $t[2] - $t[1]);
                $edits[] = [$t[1], $t[2], ve_set_attrs($open, $t[0], $c['attrs'])];
            }
        }
    }
    // outer edits win over edits nested inside them
    usort($edits, fn($a, $b) => [$a[0], $b[1]] <=> [$b[0], $a[1]]);
    $ok = [];
    foreach ($edits as $e) {
        foreach ($ok as $o) {
            $overlap = $e[0] < $o[1] && $o[0] < $e[1];
            $insideInsert = $e[0] === $e[1] && $e[0] > $o[0] && $e[0] < $o[1];
            if ($overlap || $insideInsert) { $skipped++; continue 2; }
        }
        $ok[] = $e;
    }
    usort($ok, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    foreach ($ok as [$s, $e, $r]) $html = substr($html, 0, $s) . $r . substr($html, $e);
    return [$html, count($ok), $skipped];
}

/* ---------------- Publish: write the files the public site reads ---------------- */
function cms_write_file(string $file, string $data): void
{
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $data) !== false) rename($tmp, $file);
}

function cms_page_cache_file(string $path): string
{
    return SPH_CMS_DIR . '/pages/' . sha1($path) . '.html';
}

function cms_publish_cache(): void
{
    $dir = SPH_CMS_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    // settings
    $set = cms_settings();
    cms_write_file("$dir/settings.json", json_encode($set, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // header / footer
    foreach (['header', 'footer'] as $part) {
        $v = cms_setting_get('chrome_' . $part);
        if ($v !== null && $v !== '') cms_write_file("$dir/$part.html", $v);
        elseif (is_file("$dir/$part.html")) @unlink("$dir/$part.html");
    }

    // pages: SEO + status + content files
    $pages = [];
    $keep = [];
    foreach (q('SELECT * FROM cms_pages')->fetchAll() as $p) {
        $entry = [
            'kind' => $p['kind'], 'status' => $p['status'], 'title' => $p['title'], 'use_chrome' => (int)$p['use_chrome'],
            'meta_title' => $p['meta_title'], 'meta_description' => $p['meta_description'], 'og_image' => $p['og_image'],
            'noindex' => (int)$p['noindex'], 'updated' => $p['updated_at'],
        ];
        if ($p['content'] !== null && $p['content'] !== '') {
            $f = cms_page_cache_file($p['path']);
            cms_write_file($f, $p['content']);
            $entry['file'] = basename($f);
            $keep[basename($f)] = true;
        }
        $pages[$p['path']] = $entry;
    }
    cms_write_file("$dir/pages.json", json_encode($pages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    foreach (glob("$dir/pages/*.html") ?: [] as $f) {
        if (!isset($keep[basename($f)])) @unlink($f);
    }

    // redirects
    $red = [];
    foreach (q('SELECT from_path, to_url, code FROM cms_redirects')->fetchAll() as $r) {
        $red[$r['from_path']] = ['to' => $r['to_url'], 'code' => (int)$r['code']];
    }
    cms_write_file("$dir/redirects.json", json_encode($red, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // markets
    $mk = ['hidden' => [], 'pinned' => [], 'titles' => [], 'categories' => [],
        'disabled_platforms' => array_values(array_filter(explode(',', (string)$set['disabled_platforms'])))];
    foreach (q('SELECT * FROM cms_market_overrides')->fetchAll() as $o) {
        if ($o['hidden']) $mk['hidden'][$o['event_id']] = 1;
        if ($o['pinned']) $mk['pinned'][$o['event_id']] = 1;
        if ($o['title'] !== null && $o['title'] !== '') $mk['titles'][$o['event_id']] = $o['title'];
        if ($o['category'] !== null && $o['category'] !== '') $mk['categories'][$o['event_id']] = $o['category'];
    }
    cms_write_file("$dir/markets.json", json_encode($mk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/* ---------------- First-run content ---------------- */
function cms_seed(): array
{
    $log = [];
    if (cms_setting_get('seeded_v1') === '1') {
        cms_publish_cache();
        return ['CMS cache files refreshed'];
    }

    $roles = [
        ['Content Editor', 'Writes, reviews and publishes blog & news posts',
            ['posts.write', 'posts.publish', 'posts.categories', 'media.manage', 'seo.manage']],
        ['Writer', 'Writes blog & news drafts and sends them for review',
            ['posts.write', 'media.manage']],
        ['Website Manager', 'Edits pages, header & footer, SEO, settings and markets',
            ['pages.edit', 'seo.manage', 'settings.manage', 'markets.manage', 'media.manage']],
    ];
    foreach ($roles as [$n, $d, $perms]) {
        if (q('SELECT id FROM admin_roles WHERE name = ?', [$n])->fetchColumn()) continue;
        q('INSERT INTO admin_roles (name, description) VALUES (?, ?)', [$n, $d]);
        $rid = (int)db()->lastInsertId();
        foreach ($perms as $p) q('INSERT INTO admin_role_permissions (role_id, perm) VALUES (?, ?)', [$rid, $p]);
        $log[] = "Role created: $n";
    }

    $cats = [
        'blog' => ['Beginner Guides', 'Platform Guides', 'Fees & Pricing', 'Sports Markets', 'Politics', 'Market Research'],
        'news' => ['Markets', 'Politics', 'Crypto', 'Sports', 'Regulation'],
    ];
    foreach ($cats as $type => $names) {
        foreach ($names as $i => $n) {
            q('INSERT IGNORE INTO cms_categories (type, name, slug, sort_order) VALUES (?, ?, ?, ?)', [$type, $n, cms_slugify($n), $i]);
        }
    }
    $log[] = 'Default blog/news categories created';

    // The existing "How prediction markets work" article becomes the first real post
    $src = @file_get_contents(SPH_ROOT . '/post.html');
    if ($src && preg_match('~<article class="article-card card">(.*?)<div class="author-box~s', $src, $m)
        && !q("SELECT id FROM cms_posts WHERE type = 'blog' AND slug = 'how-prediction-markets-work'")->fetchColumn()) {
        $body = trim($m[1]);
        $cat = q("SELECT id FROM cms_categories WHERE type = 'blog' AND slug = 'beginner-guides'")->fetchColumn() ?: null;
        q("INSERT INTO cms_posts (type, title, slug, excerpt, content, category_id, author_name, status, featured, meta_title, meta_description, read_minutes, published_at)
           VALUES ('blog', ?, 'how-prediction-markets-work', ?, ?, ?, 'Smart Prediction Hub Editorial Team', 'published', 1, ?, ?, ?, NOW())", [
            'How Prediction Markets Work: A Beginner’s Guide to Event Contracts',
            'Learn what Yes and No prices represent, why market probabilities move, how settlement works and what to check before entering a position.',
            $body, $cat, 'How Prediction Markets Work — Smart Prediction Hub',
            'A beginner-friendly guide to prediction markets, event contracts, Yes and No prices, settlement, fees and liquidity.',
            cms_read_minutes($body),
        ]);
        q("INSERT IGNORE INTO cms_redirects (from_path, to_url, code) VALUES ('/post', '/blog/how-prediction-markets-work', 301)");
        $log[] = 'First blog post imported from post.html';
    }

    cms_setting_set('seeded_v1', '1');
    cms_publish_cache();
    $log[] = 'CMS cache files written';
    return $log;
}
