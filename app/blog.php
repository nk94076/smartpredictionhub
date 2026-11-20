<?php
/** Public blog & news: queries + small render helpers shared by /posts.php, /article.php and the admin preview. */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/site.php';

const BLOG_LIVE = "p.status = 'published' AND p.published_at <= NOW()";

function blog_type(string $t): string
{
    return $t === 'news' ? 'news' : 'blog';
}

function blog_category(string $type, string $slug): ?array
{
    $c = q('SELECT * FROM cms_categories WHERE type = ? AND slug = ?', [$type, $slug])->fetch();
    return $c ?: null;
}

function blog_categories(string $type): array
{
    return q('SELECT c.*, (SELECT COUNT(*) FROM cms_posts p WHERE p.category_id = c.id AND ' . BLOG_LIVE . ') AS n
              FROM cms_categories c WHERE c.type = ? ORDER BY c.sort_order, c.name', [$type])->fetchAll();
}

/** [posts, total] */
function blog_list(string $type, ?int $catId, string $search, int $page, int $per, ?int $excludeId = null): array
{
    $w = ['p.type = ?', BLOG_LIVE];
    $a = [$type];
    if ($catId) { $w[] = 'p.category_id = ?'; $a[] = $catId; }
    if ($search !== '') { $w[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ?)'; array_push($a, "%$search%", "%$search%", "%$search%"); }
    if ($excludeId) { $w[] = 'p.id <> ?'; $a[] = $excludeId; }
    $where = implode(' AND ', $w);
    $total = (int)q("SELECT COUNT(*) FROM cms_posts p WHERE $where", $a)->fetchColumn();
    $off = max(0, ($page - 1) * $per);
    $rows = q("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM cms_posts p LEFT JOIN cms_categories c ON c.id = p.category_id
               WHERE $where ORDER BY p.published_at DESC, p.id DESC LIMIT $per OFFSET $off", $a)->fetchAll();
    return [$rows, $total];
}

function blog_featured(string $type): ?array
{
    $r = q("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM cms_posts p LEFT JOIN cms_categories c ON c.id = p.category_id
            WHERE p.type = ? AND p.featured = 1 AND " . BLOG_LIVE . ' ORDER BY p.published_at DESC LIMIT 1', [$type])->fetch();
    return $r ?: null;
}

function blog_post(string $type, string $slug, bool $any = false): ?array
{
    $r = q('SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM cms_posts p LEFT JOIN cms_categories c ON c.id = p.category_id
            WHERE p.type = ? AND p.slug = ?' . ($any ? '' : ' AND ' . BLOG_LIVE), [$type, $slug])->fetch();
    return $r ?: null;
}

function blog_related(array $post, int $n = 3): array
{
    return q('SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM cms_posts p LEFT JOIN cms_categories c ON c.id = p.category_id
              WHERE p.type = ? AND p.id <> ? AND ' . BLOG_LIVE . '
              ORDER BY (p.category_id <=> ?) DESC, p.published_at DESC LIMIT ' . $n, [$post['type'], $post['id'], $post['category_id']])->fetchAll();
}

function blog_url(array $p): string
{
    return '/' . $p['type'] . '/' . rawurlencode($p['slug']);
}

function blog_date(?string $d): string
{
    return $d ? date('M j, Y', strtotime($d)) : '';
}

function blog_summary(array $p, int $len = 170): string
{
    $t = trim((string)$p['excerpt']);
    if ($t === '') {
        $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$p['content']), ENT_QUOTES, 'UTF-8')));
    }
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1)) . '…' : $t;
}

/** Picture block for cards: cover image, or the original gradient art. */
function blog_art(array $p, string $class = 'post-art'): string
{
    if (!empty($p['cover_image'])) {
        return '<div class="' . $class . ' has-img"><img src="' . sph_h($p['cover_image']) . '" alt="' . sph_h($p['cover_alt'] ?: $p['title']) . '" loading="lazy"></div>';
    }
    $tones = ['', ' orange', ' green', ' blue'];
    $icons = $p['type'] === 'news' ? ['fa-newspaper', 'fa-bolt', 'fa-chart-line', 'fa-landmark'] : ['fa-scale-balanced', 'fa-coins', 'fa-percent', 'fa-water'];
    $k = (int)$p['id'] % 4;
    return '<div class="' . $class . $tones[$k] . '"><i class="fa-solid ' . $icons[$k] . '"></i>'
        . '<svg class="mini-chart" viewBox="0 0 100 50"><path d="M2 44 L22 31 L42 35 L60 16 L76 22 L98 7" fill="none" stroke="#A78BFA" stroke-width="3"/></svg></div>';
}

/** Adds id="…" to <h2> tags that lack one; returns [html, toc]. */
function blog_toc(string $html): array
{
    $toc = [];
    $used = [];
    $html = preg_replace_callback('~<h2\b([^>]*)>(.*?)</h2>~is', function ($m) use (&$toc, &$used) {
        $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
        if ($text === '') return $m[0];
        if (preg_match('~\bid="([^"]+)"~', $m[1], $id)) {
            $slug = $id[1];
            $attrs = $m[1];
        } else {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-') ?: 'section';
            $base = $slug; $i = 2;
            while (isset($used[$slug])) $slug = $base . '-' . $i++;
            $attrs = $m[1] . ' id="' . $slug . '"';
        }
        $used[$slug] = true;
        $toc[] = [$slug, $text];
        return '<h2' . $attrs . '>' . $m[2] . '</h2>';
    }, $html);
    return [$html, $toc];
}

function blog_pager(int $page, int $pages, callable $url): string
{
    if ($pages <= 1) return '';
    $h = '<nav class="blog-pager" aria-label="Pages">';
    if ($page > 1) $h .= '<a href="' . sph_h($url($page - 1)) . '" rel="prev">‹ Newer</a>';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $h .= $i === $page ? '<span class="on">' . $i . '</span>' : '<a href="' . sph_h($url($i)) . '">' . $i . '</a>';
    }
    if ($page < $pages) $h .= '<a href="' . sph_h($url($page + 1)) . '" rel="next">Older ›</a>';
    return $h . '</nav>';
}
