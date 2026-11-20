<?php
/** Single blog/news article. Expects $post (row with cat_name/cat_slug) and $preview (bool). Included by /article.php and admin/post-preview.php. */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

$type = $post['type'];
$isNews = $type === 'news';
$url = SPH_SITE_URL . blog_url($post);
$site = sph_setting('site_name', 'Smart Prediction Hub');
[$body, $toc] = blog_toc((string)$post['content']);
$related = blog_related($post);
$summary = blog_summary($post, 300);
$author = $post['author_name'] ?: $site . ' Editorial Team';
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($author)), 0, 2)))) ?: 'SPH';

$pageTitle = $post['meta_title'] ?: $post['title'] . ' | ' . $site;
$pageDescription = $post['meta_description'] ?: blog_summary($post, 160);
$canonical = $url;
$ogImage = (string)$post['cover_image'];
$cover = $ogImage !== '' && $ogImage[0] === '/' ? SPH_SITE_URL . $ogImage : $ogImage;
$ld = [
    '@context' => 'https://schema.org', '@type' => $isNews ? 'NewsArticle' : 'Article',
    'headline' => mb_substr($post['title'], 0, 110), 'description' => $pageDescription, 'mainEntityOfPage' => $url,
    'datePublished' => date('c', strtotime((string)($post['published_at'] ?: $post['created_at']))), 'dateModified' => date('c', strtotime((string)$post['updated_at'])),
    'author' => ['@type' => 'Person', 'name' => $author],
    'publisher' => ['@type' => 'Organization', 'name' => $site, 'url' => SPH_SITE_URL],
];
if ($cover) $ld['image'] = [$cover];
$extraHead = '<link rel="stylesheet" href="/assets/blog.css?v=' . @filemtime(SPH_ROOT . '/assets/blog.css') . '">'
    . '<meta property="article:published_time" content="' . sph_h($ld['datePublished']) . '">'
    . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>'
    . ($preview ? '<meta name="robots" content="noindex">' : '');

require SPH_ROOT . '/includes/layout-top.php';
$h = 'sph_h';
$share = rawurlencode($url);
?>
<?php if ($preview): ?>
<div style="background:#F5A524;color:#17142E;text-align:center;padding:10px;font:600 13px Poppins,sans-serif;position:relative;z-index:50">
  Preview — <?= $post['status'] === 'published' ? (strtotime((string)$post['published_at']) > time() ? 'scheduled for ' . blog_date($post['published_at']) : 'published') : 'not published yet' ?>. Visitors cannot see unpublished posts.
</div>
<?php endif; ?>
<section class="blog-hero">
  <div class="page-wrap">
    <div class="post-breadcrumbs"><a href="/">Home</a><span>/</span><a href="/<?= $type ?>"><?= $isNews ? 'News' : 'Blog' ?></a>
      <?php if ($post['cat_name']): ?><span>/</span><a href="/<?= $type ?>/category/<?= $h($post['cat_slug']) ?>"><?= $h($post['cat_name']) ?></a><?php endif; ?></div>
    <h1 class="page-title" style="margin-top:18px"><?= $h($post['title']) ?></h1>
    <?php if ($summary): ?><p class="page-lede"><?= $h($summary) ?></p><?php endif; ?>
    <div class="post-byline">
      <div class="author-avatar"><?= $h($initials) ?></div>
      <div><strong><?= $h($author) ?></strong><span><?= blog_date($post['published_at'] ?: $post['created_at']) ?> · <?= (int)$post['read_minutes'] ?> min read</span></div>
    </div>
  </div>
</section>

<main class="page-wrap article-shell" style="padding-top:44px">
  <div class="article-layout">
    <article class="article-card card">
      <?php if ($post['cover_image']): ?><figure class="post-cover"><img src="<?= $h($post['cover_image']) ?>" alt="<?= $h($post['cover_alt'] ?: $post['title']) ?>"></figure><?php endif; ?>
      <?= $body ?>
      <?php if ($post['tags']): ?><div class="post-tags"><?php foreach (array_filter(array_map('trim', explode(',', $post['tags']))) as $t): ?><span>#<?= $h($t) ?></span><?php endforeach; ?></div><?php endif; ?>
      <div class="author-box card">
        <div class="author-avatar"><?= $h($initials) ?></div>
        <div><h3><?= $h($author) ?></h3>
          <p>We publish platform research, explainers and comparison frameworks designed to make prediction-market products easier to understand.</p></div>
      </div>
    </article>

    <aside>
      <div class="toc-card card">
        <?php if (count($toc) > 1): ?>
          <h3>In this <?= $isNews ? 'story' : 'guide' ?></h3>
          <?php foreach ($toc as [$id, $text]): ?><a href="#<?= $h($id) ?>"><?= $h($text) ?></a><?php endforeach; ?>
        <?php else: ?>
          <h3>Share</h3>
        <?php endif; ?>
        <div class="share-row" aria-label="Share article">
          <a href="https://x.com/intent/post?url=<?= $share ?>&text=<?= rawurlencode($post['title']) ?>" target="_blank" rel="noopener" aria-label="Share on X"><i class="fa-brands fa-x-twitter"></i></a>
          <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $share ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
          <a href="https://wa.me/?text=<?= rawurlencode($post['title'] . ' ' . $url) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
          <a href="https://t.me/share/url?url=<?= $share ?>" target="_blank" rel="noopener" aria-label="Share on Telegram"><i class="fa-brands fa-telegram"></i></a>
        </div>
      </div>
    </aside>
  </div>
</main>

<?php if ($related): ?>
<section class="related-section">
  <div class="page-wrap">
    <div class="section-head">
      <div><h2>Continue reading</h2><p><?= $isNews ? 'More from the newsroom.' : 'Build on the basics with platform comparisons, pricing and liquidity guides.' ?></p></div>
      <a class="section-link" href="/<?= $type ?>">View all <?= $isNews ? 'news' : 'articles' ?> →</a>
    </div>
    <div class="related-grid">
      <?php foreach ($related as $r): ?>
      <article class="related-card card">
        <div class="icon"><i class="fa-solid <?= $isNews ? 'fa-newspaper' : 'fa-book-open' ?>"></i></div>
        <small><?= $h(mb_strtoupper($r['cat_name'] ?: ($isNews ? 'News' : 'Guide'))) ?></small>
        <h3><?= $h($r['title']) ?></h3>
        <p><?= $h(blog_summary($r, 110)) ?></p>
        <a class="read-link" href="<?= blog_url($r) ?>">Read article <i class="fa-solid fa-arrow-right"></i></a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php require SPH_ROOT . '/includes/layout-bottom.php';
