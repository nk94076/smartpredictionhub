<?php
/**
 * GET /api/markets.php — live prediction-market events across platforms.
 *   tab=all|trending|breaking|new|ending|matched   category=<slug>   platform=polymarket,kalshi
 *   q=<search>  ends=24h|7d|30d|1y  ids=a,b,c (watchlist)
 *   sort=volume|volume24|liquidity|consensus|change|ends|new|platforms  dir=asc|desc
 *   page=1  per=50  include=stats,cards
 */
define('SPH_APP', true);
if (is_file(__DIR__ . '/../app/config.php')) require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/markets/store.php';

$data = mk_load();
mk_refresh_if_stale();
if (!$data) {
    http_response_code(503);
    mk_json_out(['error' => 'Market data is being prepared. Try again in a minute.'], 10);
    exit;
}

$out = mk_query($data['events'], $_GET);
$include = explode(',', (string)($_GET['include'] ?? ''));
if (in_array('stats', $include, true)) $out['stats'] = $data['stats'];
if (in_array('cards', $include, true)) $out['cards'] = mk_cards($data['events']);
$out['updated'] = $data['stats']['updated'] ?? null;
mk_json_out($out, 60);
