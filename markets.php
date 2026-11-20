<?php
/** Live prediction markets: every event across platforms, with filters (like a market screener). */
define('SPH_APP', true);
if (is_file(__DIR__ . '/app/config.php')) require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/markets/config.php';
require_once __DIR__ . '/app/site.php';

$cat = (string)($_GET['category'] ?? '');
$catLabel = MK_CATEGORIES[$cat] ?? null;
$tabs = ['trending' => 'Trending', 'breaking' => 'Breaking', 'new' => 'New', 'ending' => 'Ending Soon', 'matched' => 'Matched'];
$tabLabel = $tabs[(string)($_GET['tab'] ?? '')] ?? null;

$heading = $catLabel ? "$catLabel prediction markets" : ($tabLabel ? "$tabLabel prediction markets" : 'Prediction market odds');
$pageTitle = ($catLabel ? "$catLabel Prediction Markets & Odds" : ($tabLabel ? "$tabLabel Prediction Markets" : 'Prediction Market Odds & Probabilities'))
    . ' | Smart Prediction Hub';
$pageDescription = 'Compare live odds, volume and liquidity' . ($catLabel ? ' for ' . strtolower($catLabel) . ' markets' : '')
    . ' across Polymarket, Kalshi, Limitless and PredictIt. See where prediction markets agree — updated every few minutes.';
$canonical = 'https://smartpredictionhub.com/markets' . ($catLabel ? '/' . rawurlencode($cat) : '');

require __DIR__ . '/includes/layout-top.php';
?>
<section class="mk-hero">
    <div class="mk-wrap">
        <div class="mk-crumbs"><a href="/">Home</a> / <a href="/markets">Markets</a><?= $catLabel ? ' / ' . htmlspecialchars($catLabel) : '' ?></div>
        <?php if (!$catLabel && !$tabLabel && sph_setting('markets_heading') !== ''): ?>
        <h1><?= htmlspecialchars(sph_setting('markets_heading')) ?></h1>
        <?php else: ?>
        <h1><?= htmlspecialchars($heading) ?> <span>across every platform</span></h1>
        <?php endif; ?>
        <?php if (sph_setting('markets_intro') !== ''): ?>
        <p><?= nl2br(htmlspecialchars(sph_setting('markets_intro'))) ?></p>
        <?php else: ?>
        <p>See what prediction markets are pricing and why. Compare odds, liquidity and price moves across Polymarket, Kalshi,
            Limitless and PredictIt — normalized in one place, with a volume-weighted consensus and the best price for every outcome.</p>
        <?php endif; ?>
    </div>
</section>
<main class="mk-page">
    <div class="mk-wrap">
        <!-- Filled by assets/markets.js -->
        <div id="mk-dashboard"><div class="mk-empty">Loading live markets…</div></div>
        <noscript><p class="mk-empty">Turn on JavaScript to see live market data.</p></noscript>
    </div>
</main>
<?php require __DIR__ . '/includes/layout-bottom.php';
