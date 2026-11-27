<?php
/**
 * Market aggregator settings. Not secret: safe to keep in git.
 * Override any value by defining it first in app/config.php.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

// Where the refreshed data is written (app/ is blocked from the browser).
defined('MK_CACHE_DIR') || define('MK_CACHE_DIR', dirname(__DIR__) . '/cache');

// Data older than this (seconds) is refreshed by the next API request
// if cron has not done it already. Cron is the normal way (see DEPLOY.md).
defined('MK_STALE_AFTER') || define('MK_STALE_AFTER', 900);

// Kalshi blocks many datacenter IPs. List endpoints in order; the first
// one that answers is used. The Cloudflare worker (kalshi-worker.js) is the fallback.
defined('MK_KALSHI_BASES') || define('MK_KALSHI_BASES', [
    'https://api.elections.kalshi.com',
    'https://kalshi-proxy2.naveen-p-bcf.workers.dev/proxy',
]);

// How much to pull from each platform on every refresh.
defined('MK_POLY_PAGES') || define('MK_POLY_PAGES', 8);        // x100 events, by total volume
defined('MK_KALSHI_SERIES') || define('MK_KALSHI_SERIES', 300); // top Kalshi series by volume, see sources.php
defined('MK_KALSHI_PAGES') || define('MK_KALSHI_PAGES', 5);     // x200 newest events, on top of the series
defined('MK_LIMITLESS_PAGES') || define('MK_LIMITLESS_PAGES', 4); // x25 markets
defined('MK_KEEP_EVENTS') || define('MK_KEEP_EVENTS', 1000);   // unified events kept after merge
defined('MK_HISTORY_EVENTS') || define('MK_HISTORY_EVENTS', 120); // events that get a 7-day sparkline

// Platforms shown on the site. key => [label, colour, site]
const MK_PLATFORMS = [
    'polymarket' => ['Polymarket', '#2E5CFF', 'https://polymarket.com'],
    'kalshi'     => ['Kalshi',     '#00D991', 'https://kalshi.com'],
    'limitless'  => ['Limitless',  '#DCF20A', 'https://limitless.exchange'],
    'predictit'  => ['PredictIt',  '#0B5FA5', 'https://www.predictit.org'],
];

// Site categories, in tab order. slug => label
const MK_CATEGORIES = [
    'politics'      => 'Politics',
    'elections'     => 'Elections',
    'sports'        => 'Sports',
    'crypto'        => 'Crypto',
    'economics'     => 'Economics',
    'financials'    => 'Financials',
    'companies'     => 'Companies',
    'tech'          => 'Science & Tech',
    'entertainment' => 'Entertainment',
    'weather'       => 'Weather',
    'other'         => 'Other',
];
