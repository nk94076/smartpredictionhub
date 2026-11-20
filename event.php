<?php
/** One event: consensus, 30-day chart and side-by-side prices on every platform. */
define('SPH_APP', true);
if (is_file(__DIR__ . '/app/config.php')) require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/markets/config.php';

$id = (string)($_GET['id'] ?? '');
$event = null;
if (preg_match('/^[a-z0-9-]{1,200}$/', $id)) {
    $file = MK_CACHE_DIR . '/events/' . $id . '.json';
    if (is_file($file)) $event = json_decode((string)file_get_contents($file), true);
}
if (!$event) {
    http_response_code(404);
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$title = $event['title'] ?? 'Event not found';
$pageTitle = $title . ' — Odds & Probability | Smart Prediction Hub';
$pageDescription = $event
    ? $event['summary'] . '. Compare prices on ' . implode(', ', array_map(fn($p) => MK_PLATFORMS[$p][0], array_keys($event['platforms']))) . '.'
    : 'This prediction market could not be found.';
$canonical = $event ? 'https://smartpredictionhub.com/event.php?id=' . rawurlencode($id) : '';

require __DIR__ . '/includes/layout-top.php';
?>
<section class="mk-hero">
    <div class="mk-wrap">
        <div class="mk-crumbs"><a href="/">Home</a> / <a href="/markets.php">Markets</a>
            <?php if ($event): ?> / <a href="/markets.php?category=<?= $h($event['category']) ?>"><?= $h(MK_CATEGORIES[$event['category']] ?? 'Other') ?></a><?php endif; ?></div>
        <div class="mk-ev-head">
            <div>
                <h1><?= $h($title) ?></h1>
                <p><?= $event ? $h($event['summary']) : 'This market may have closed or been removed.' ?></p>
                <div class="meta" id="mk-ev-meta"></div>
            </div>
            <?php if ($event): ?><div class="mk-ev-cons" id="mk-ev-cons"><span class="mk-skel" style="opacity:.3"></span></div><?php endif; ?>
        </div>
    </div>
</section>
<main class="mk-page">
    <div class="mk-wrap">
        <?php if ($event): ?>
        <div id="mk-event" data-id="<?= $h($id) ?>">
            <div class="mk-grid">
                <div style="display:grid;gap:18px;min-width:0">
                    <section class="mk-panel"><h2>Price history <span class="mk-sub" style="font-size:12px;font-weight:500;color:#64748B">Last 30 days</span></h2>
                        <div id="mk-ev-chart" style="padding-top:10px"><div class="pad"><span class="mk-skel" style="height:240px"></span></div></div></section>
                    <section class="mk-panel"><h2>Compare prices across platforms</h2>
                        <div id="mk-ev-cmp"><div class="pad"><span class="mk-skel"></span></div></div>
                        <div class="mk-note">Consensus is the volume-weighted probability across platforms. ★ marks the cheapest place to buy Yes. <span id="mk-ev-upd"></span></div></section>
                </div>
                <aside style="display:grid;gap:18px;align-content:start">
                    <section class="mk-panel"><h2>Where to trade</h2><div id="mk-ev-venues"><div class="pad"><span class="mk-skel"></span></div></div></section>
                    <section class="mk-panel mk-related"><h2>Related markets</h2><div id="mk-ev-related"><div class="pad"><span class="mk-skel"></span></div></div></section>
                </aside>
            </div>
            <p class="mk-disclaimer">Prices are probabilities implied by each platform's market and can change quickly. Event contracts carry risk and may not be
                available where you live — check <a href="/coming-soon.html" style="color:#4F46E5">legality by location</a> first. This is not trading advice.</p>
        </div>
        <?php else: ?>
        <div class="mk-empty">We couldn't find this event. <a href="/markets.php" style="color:#4F46E5;font-weight:600">Browse all live markets →</a></div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/includes/layout-bottom.php';
