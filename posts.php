<?php
/** /blog, /news, /blog/category/<slug>, /news/category/<slug> — post listings (CMS: Admin → Blog Posts / News). */
define('SPH_APP', true);
require __DIR__ . '/app/blog.php';

$type = blog_type((string)($_GET['type'] ?? 'blog'));
$isNews = $type === 'news';
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = max(1, min(50, (int)sph_setting('posts_per_page', 9)));
$cat = null;
if (!empty($_GET['category'])) {
    $cat = blog_category($type, (string)$_GET['category']);
    if (!$cat) { http_response_code(404); }
}

$featured = (!$cat && $search === '' && $page === 1 && !$isNews) ? blog_featured($type) : null;
[$posts, $total] = blog_list($type, $cat['id'] ?? null, $search, $page, $per, $featured['id'] ?? null);
$pages = (int)ceil($total / $per);
$cats = blog_categories($type);
$base = '/' . $type . ($cat ? '/category/' . rawurlencode($cat['slug']) : '');
$url = fn($n) => $base . '?' . http_build_query(array_filter(['q' => $search, 'page' => $n > 1 ? $n : null]));

$heading = $cat ? $cat['name'] : sph_setting($isNews ? 'news_title' : 'blog_title', $isNews ? 'Prediction market news' : 'Prediction market guides');
$intro = $cat && $cat['description'] ? $cat['description'] : sph_setting($isNews ? 'news_intro' : 'blog_intro');
$site = sph_setting('site_name', 'Smart Prediction Hub');
$pageTitle = ($cat ? $cat['name'] . ' — ' . ($isNews ? 'News' : 'Blog') : ($isNews ? 'Prediction Market News' : 'Prediction Market Guides & Insights'))
    . ($page > 1 ? " (page $page)" : '') . ' | ' . $site;
$pageDescription = mb_strimwidth(strip_tags($intro), 0, 160, '…');
$canonical = SPH_SITE_URL . $base . ($page > 1 ? '?page=' . $page : '');
$extraHead = '<link rel="stylesheet" href="/assets/blog.css?v=' . @filemtime(__DIR__ . '/assets/blog.css') . '">'
    . ($search !== '' ? '<meta name="robots" content="noindex, follow">' : '');

require __DIR__ . '/includes/layout-top.php';
$h = 'sph_h';
?>
<section class="blog-hero">
  <div class="page-wrap">
    <span class="page-kicker"><i class="fa-regular fa-newspaper"></i> <?= $isNews ? 'SPH News' : 'SPH Insights' ?><?= $cat ? ' · ' . $h($cat['name']) : '' ?></span>
    <h1 class="page-title"><?= $h($heading) ?></h1>
    <?php if ($intro): ?><p class="page-lede"><?= $h($intro) ?></p><?php endif; ?>
    <form class="blog-search" action="/<?= $type ?>" method="get" role="search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= $h($search) ?>" placeholder="Search <?= $isNews ? 'news' : 'guides, platforms and topics' ?>..." aria-label="Search">
      <button type="submit">Search</button>
    </form>
  </div>
</section>

<?php if ($cats): ?>
<div class="blog-categories">
  <div class="page-wrap">
    <div class="category-row scrollbar-hide">
      <a class="category-chip<?= !$cat ? ' active' : '' ?>" href="/<?= $type ?>">All</a>
      <?php foreach ($cats as $c): if (!$c['n'] && (!$cat || $cat['id'] !== $c['id'])) continue; ?>
        <a class="category-chip<?= $cat && $cat['id'] === $c['id'] ? ' active' : '' ?>" href="/<?= $type ?>/category/<?= $h($c['slug']) ?>"><?= $h($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<main class="page-wrap blog-main">
  <?php if ($featured): ?>
  <div class="section-head"><div><h2>Featured reading</h2><p>Start with the core mechanics before comparing platforms or individual markets.</p></div></div>
  <article class="featured-story card">
    <?php if ($featured['cover_image']): ?>
      <div class="featured-art has-img"><img src="<?= $h($featured['cover_image']) ?>" alt="<?= $h($featured['cover_alt'] ?: $featured['title']) ?>"></div>
    <?php else: ?>
    <div class="featured-art">
      <div class="featured-chart">
        <span class="chart-label">PROBABILITY MOVES AS INFORMATION CHANGES</span>
        <div class="chart-line"><svg viewBox="0 0 600 110" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="blogLine" x1="0" x2="1"><stop offset="0" stop-color="#8B5CF6"/><stop offset="1" stop-color="#F59E0B"/></linearGradient></defs>
          <path d="M5,91 C75,88 105,59 155,68 S250,90 300,53 S386,25 432,44 S515,61 594,14" fill="none" stroke="url(#blogLine)" stroke-width="5" stroke-linecap="round"/></svg></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="featured-copy">
      <div class="story-meta">
        <?php if ($featured['cat_name']): ?><span class="tag"><?= $h($featured['cat_name']) ?></span><?php endif; ?>
        <span><?= blog_date($featured['published_at']) ?></span><span>•</span><span><?= (int)$featured['read_minutes'] ?> min read</span>
      </div>
      <h2><a href="<?= blog_url($featured) ?>"><?= $h($featured['title']) ?></a></h2>
      <p><?= $h(blog_summary($featured, 260)) ?></p>
      <a class="read-link" href="<?= blog_url($featured) ?>">Read the guide <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </article>
  <?php endif; ?>

  <div class="blog-layout">
    <section>
      <div class="section-head"><div>
        <h2><?= $search !== '' ? 'Results for “' . $h($search) . '”' : ($cat ? $h($cat['name']) : ($isNews ? 'Latest news' : 'Latest from Smart Prediction Hub')) ?></h2>
        <p><?= $total ? number_format($total) . ' ' . ($total === 1 ? 'article' : 'articles') : '' ?></p>
      </div></div>

      <?php if (!$posts): ?>
        <div class="card blog-empty"><i class="fa-regular fa-newspaper"></i>
          <?= $search !== '' ? 'Nothing matches your search. Try another word.' : 'No articles here yet — check back soon.' ?></div>
      <?php elseif ($isNews): ?>
        <div class="news-list">
        <?php foreach ($posts as $p): ?>
          <article class="news-item card">
            <a href="<?= blog_url($p) ?>" aria-hidden="true" tabindex="-1" style="display:block;position:relative"><?= blog_art($p) ?></a>
            <div class="post-copy">
              <div class="story-meta"><?php if ($p['cat_name']): ?><span class="tag"><?= $h($p['cat_name']) ?></span><?php endif; ?><span><?= blog_date($p['published_at']) ?></span></div>
              <h3><a href="<?= blog_url($p) ?>"><?= $h($p['title']) ?></a></h3>
              <p><?= $h(blog_summary($p)) ?></p>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="post-grid">
        <?php foreach ($posts as $p): ?>
          <article class="post-card card">
            <a href="<?= blog_url($p) ?>" aria-hidden="true" tabindex="-1" style="display:block;position:relative"><?= blog_art($p) ?></a>
            <div class="post-copy">
              <div class="story-meta"><?php if ($p['cat_name']): ?><span class="tag"><?= $h($p['cat_name']) ?></span><?php endif; ?><span><?= (int)$p['read_minutes'] ?> min read</span></div>
              <h3><a href="<?= blog_url($p) ?>"><?= $h($p['title']) ?></a></h3>
              <p><?= $h(blog_summary($p)) ?></p>
              <a class="read-link" href="<?= blog_url($p) ?>">Read article <i class="fa-solid fa-arrow-right"></i></a>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?= blog_pager($page, $pages, $url) ?>
    </section>

    <aside class="blog-sidebar">
      <?php if (array_filter($cats, fn($c) => $c['n'] > 0)): ?>
      <div class="sidebar-card card">
        <h3>Explore topics</h3>
        <div class="topic-list">
          <?php foreach ($cats as $c): if (!$c['n']) continue; ?>
            <a href="/<?= $type ?>/category/<?= $h($c['slug']) ?>"><span><?= $h($c['name']) ?></span><b><?= (int)$c['n'] ?></b></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="sidebar-dark">
        <h3><?= $isNews ? 'Read the guides' : 'See live odds' ?></h3>
        <p><?= $isNews ? 'New to prediction markets? Our guides explain prices, fees and settlement in plain English.' : 'Compare what Polymarket, Kalshi and other platforms are pricing right now — in one place.' ?></p>
        <a href="<?= $isNews ? '/blog' : '/markets' ?>"><?= $isNews ? 'Start learning' : 'Open live markets' ?> <i class="fa-solid fa-arrow-right"></i></a>
      </div>
    </aside>
  </div>
</main>
<?php require __DIR__ . '/includes/layout-bottom.php';
