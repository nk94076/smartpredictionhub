<?php
/**
 * One fetcher per platform. Each returns a list of "platform events" in one shape:
 *
 *  platform, pid, title, category (site slug), url, image, end (ISO), created (ISO),
 *  volume, volume24, liquidity (USD), binary (bool),
 *  outcomes: [ name, prob 0-1, ask 0-1|null, d1/d7 (points)|null, volume, hist (how to fetch history) ]
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

function mk_num($v): float { return is_numeric($v) ? (float)$v : 0.0; }

/** Mid-price when the spread is tight, otherwise last trade (same rule Polymarket's UI uses). */
function mk_price($bid, $ask, $last): ?float
{
    $bid = mk_num($bid); $ask = mk_num($ask); $last = mk_num($last);
    if ($bid > 0 && $ask > 0 && $ask >= $bid && $ask - $bid <= 0.10) return ($bid + $ask) / 2;
    if ($last > 0) return $last;
    if ($bid > 0 && $ask > 0) return ($bid + $ask) / 2;
    return null;
}

function mk_iso($t): ?string
{
    if (!$t) return null;
    $ts = is_numeric($t) ? (int)($t > 2e10 ? $t / 1000 : $t) : strtotime((string)$t);
    return $ts ? gmdate('c', $ts) : null;
}

/* ---------------------------------------------------------------- Polymarket */

function mk_poly_category(array $tags): string
{
    $l = array_map('strtolower', array_column($tags, 'label'));
    $has = fn(array $words) => (bool)array_intersect($l, $words);
    if ($has(['sports', 'nba', 'nfl', 'soccer', 'mlb', 'nhl', 'tennis', 'f1', 'golf', 'ufc', 'esports', 'cricket', 'boxing'])) return 'sports';
    if ($has(['crypto', 'bitcoin', 'ethereum', 'crypto prices', 'solana', 'airdrops'])) return 'crypto';
    if ($has(['elections', 'us election', 'world elections', 'global elections'])) return 'elections';
    if ($has(['fed', 'economy', 'economics', 'inflation', 'fed rates', 'macro indicators', 'recession'])) return 'economics';
    if ($has(['finance', 'stocks', 'commodities', 'ipos', 'equities', 'indices'])) return 'financials';
    if ($has(['business', 'companies', 'tech companies', 'big tech'])) return 'companies';
    if ($has(['tech', 'ai', 'science', 'space', 'openai'])) return 'tech';
    if ($has(['pop culture', 'culture', 'movies', 'music', 'awards', 'entertainment', 'oscars', 'tv'])) return 'entertainment';
    if ($has(['weather', 'climate', 'temperature', 'hurricanes'])) return 'weather';
    if ($has(['politics', 'geopolitics', 'world', 'trump', 'middle east'])) return 'politics';
    return 'other';
}

function mk_fetch_polymarket(): array
{
    $base = 'https://gamma-api.polymarket.com/events?active=true&closed=false&archived=false&limit=100';
    $urls = [];
    for ($p = 0; $p < MK_POLY_PAGES; $p++) $urls["vol$p"] = $base . '&order=volume&ascending=false&offset=' . ($p * 100);
    $urls['v24a'] = $base . '&order=volume24hr&ascending=false';
    $urls['v24b'] = $base . '&order=volume24hr&ascending=false&offset=100';
    $urls['new']  = $base . '&order=startDate&ascending=false';
    $urls['end']  = $base . '&order=endDate&ascending=true&end_date_min=' . gmdate('Y-m-d\TH:i:s\Z');

    $seen = [];
    $out = [];
    foreach (mk_get_json_multi($urls, 6, 30) as $page) {
        foreach (($page ?? []) as $e) {
            $id = $e['id'] ?? null;
            if (!$id || isset($seen[$id])) continue;
            $seen[$id] = true;
            $ev = mk_poly_event($e);
            if ($ev) $out[] = $ev;
        }
    }
    return $out;
}

function mk_poly_event(array $e): ?array
{
    $markets = array_filter($e['markets'] ?? [], fn($m) => !empty($m['active']) && empty($m['closed']));
    if (!$markets) return null;
    $binary = count($markets) === 1;
    $outcomes = [];
    foreach ($markets as $m) {
        $prices = json_decode($m['outcomePrices'] ?? '[]', true) ?: [];
        $prob = mk_price($m['bestBid'] ?? null, $m['bestAsk'] ?? null, $m['lastTradePrice'] ?? null);
        if ($prob === null && isset($prices[0])) $prob = (float)$prices[0];
        if ($prob === null) continue;
        $tokens = json_decode($m['clobTokenIds'] ?? '[]', true) ?: [];
        $name = $binary ? 'Yes' : (trim((string)($m['groupItemTitle'] ?? '')) ?: ($m['question'] ?? ''));
        $outcomes[] = [
            'name'   => $name,
            'prob'   => round($prob, 4),
            'ask'    => isset($m['bestAsk']) && $m['bestAsk'] > 0 ? (float)$m['bestAsk'] : null,
            'd1'     => isset($m['oneDayPriceChange']) ? round($m['oneDayPriceChange'] * 100, 1) : null,
            'd7'     => isset($m['oneWeekPriceChange']) ? round($m['oneWeekPriceChange'] * 100, 1) : null,
            'volume' => mk_num($m['volumeNum'] ?? $m['volume'] ?? 0),
            'hist'   => isset($tokens[0]) ? ['poly', $tokens[0]] : null,
        ];
    }
    if (!$outcomes) return null;
    return [
        'platform'  => 'polymarket',
        'pid'       => (string)$e['id'],
        'slug'      => $e['slug'] ?? null,
        'title'     => trim((string)($e['title'] ?? '')),
        'category'  => mk_poly_category($e['tags'] ?? []),
        'url'       => 'https://polymarket.com/event/' . ($e['slug'] ?? ''),
        'image'     => $e['icon'] ?? $e['image'] ?? null,
        'end'       => mk_iso($e['endDate'] ?? null),
        'created'   => mk_iso($e['startDate'] ?? $e['createdAt'] ?? null),
        'volume'    => mk_num($e['volume'] ?? 0),
        'volume24'  => mk_num($e['volume24hr'] ?? 0),
        'liquidity' => mk_num($e['liquidity'] ?? 0),
        'binary'    => $binary,
        'outcomes'  => $outcomes,
    ];
}

/* -------------------------------------------------------------------- Kalshi */

/** First Kalshi base URL that answers, cached for this run. */
function mk_kalshi_base(): ?string
{
    static $base = false;
    if ($base !== false) return $base;
    foreach (MK_KALSHI_BASES as $b) {
        if (mk_get_json($b . '/trade-api/v2/exchange/status', 2, 10) !== null) return $base = $b;
    }
    return $base = null;
}

function mk_kalshi_category(string $c): string
{
    $map = [
        'politics' => 'politics', 'elections' => 'elections', 'sports' => 'sports', 'economics' => 'economics',
        'financials' => 'financials', 'crypto' => 'crypto', 'companies' => 'companies',
        'science and technology' => 'tech', 'entertainment' => 'entertainment',
        'climate and weather' => 'weather', 'world' => 'politics',
    ];
    return $map[strtolower($c)] ?? 'other';
}

/**
 * Kalshi has ~15,000 open events and no volume sort, so we ask per series instead:
 * once a day we rank all series by lifetime volume (skipping 15-min/hourly crypto),
 * then every refresh pulls the open events of the top MK_KALSHI_SERIES series.
 */
function mk_kalshi_top_series(string $base): array
{
    $file = MK_CACHE_DIR . '/kalshi-series.json';
    $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if ($cached && filemtime($file) > time() - 86400) return $cached;
    if (PHP_SAPI === 'cli') @ini_set('memory_limit', '1024M'); // the full series list is ~20 MB of JSON
    $raw = mk_get_json($base . '/trade-api/v2/series?include_volume=true', 3, 90);
    $series = $raw['series'] ?? [];
    if (!$series) return $cached ?: [];
    $series = array_filter($series, fn($x) => !in_array($x['frequency'] ?? '', ['fifteen_min', 'hourly'], true));
    usort($series, fn($a, $b) => mk_num($b['volume_fp'] ?? 0) <=> mk_num($a['volume_fp'] ?? 0));
    $top = array_column(array_slice($series, 0, MK_KALSHI_SERIES), 'ticker');
    unset($raw, $series);
    mk_write($file, $top);
    return $top;
}

function mk_fetch_kalshi(): array
{
    $base = mk_kalshi_base();
    if (!$base) return [];
    $urls = [];
    foreach (mk_kalshi_top_series($base) as $t) {
        $urls[$t] = $base . '/trade-api/v2/events?status=open&with_nested_markets=true&limit=100&series_ticker=' . rawurlencode($t);
    }
    // plus the first pages of the general list, for brand-new events
    for ($p = 0; $p < MK_KALSHI_PAGES; $p++) $urls["page$p"] = null;

    // Kalshi rate-limits public reads hard, so go one at a time and keep the last good
    // answer for any series that still fails, instead of dropping its events.
    $lastFile = MK_CACHE_DIR . '/kalshi-last.json';
    $last = is_file($lastFile) ? (json_decode((string)file_get_contents($lastFile), true) ?: []) : [];
    // the top 100 series every run; the rest in three rotating groups (each refreshed every 3rd run)
    $slot = (int)floor(time() / 300) % 3;
    $pages = [];
    $good = $last;
    $i = 0;
    foreach (array_filter($urls) as $k => $url) {
        $i++;
        if ($i > 100 && $i % 3 !== $slot && isset($last[$k])) { $pages[$k] = $last[$k]; continue; }
        $r = mk_get_json($url, 4, 30);
        if ($r !== null) {
            $pages[$k] = $r;
            $good[$k] = $r;
        } elseif (isset($last[$k])) {
            $pages[$k] = $last[$k];
            $good[$k] = $last[$k];
        }
        usleep(120000);
    }
    mk_write($lastFile, array_intersect_key($good, $urls));
    $cursor = '';
    for ($p = 0; $p < MK_KALSHI_PAGES; $p++) {
        $page = mk_get_json($base . '/trade-api/v2/events?status=open&with_nested_markets=true&limit=200'
            . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : ''), 4, 30);
        if (!$page) break;
        $pages["page$p"] = $page;
        $cursor = (string)($page['cursor'] ?? '');
        if ($cursor === '') break;
        usleep(300000);
    }

    $seen = [];
    $out = [];
    foreach ($pages as $page) {
        foreach (($page['events'] ?? []) as $e) {
            $t = $e['event_ticker'] ?? '';
            if ($t === '' || isset($seen[$t])) continue;
            $seen[$t] = true;
            $ev = mk_kalshi_event($e);
            if ($ev) $out[] = $ev;
        }
    }
    return $out;
}

function mk_kalshi_event(array $e): ?array
{
    if (strpos($e['event_ticker'] ?? '', 'KXMVE') === 0) return null; // multi-leg parlays
    $markets = array_filter($e['markets'] ?? [], fn($m) => ($m['status'] ?? '') === 'active');
    if (!$markets) return null;
    $binary = count($markets) === 1;
    $outcomes = [];
    $vol = $vol24 = $oi = 0.0;
    $end = null; $created = null;
    foreach ($markets as $m) {
        $v = mk_num($m['volume_fp'] ?? 0);
        $vol += $v;
        $vol24 += mk_num($m['volume_24h_fp'] ?? 0);
        $oi += mk_num($m['open_interest_fp'] ?? 0) * mk_num($m['last_price_dollars'] ?? 0);
        $close = $m['close_time'] ?? null;
        if ($close && (!$end || $close < $end)) $end = $close;
        if (!empty($m['open_time']) && (!$created || $m['open_time'] < $created)) $created = $m['open_time'];
        $prob = mk_price($m['yes_bid_dollars'] ?? null, $m['yes_ask_dollars'] ?? null, $m['last_price_dollars'] ?? null);
        if ($prob === null) continue;
        $prev = mk_num($m['previous_price_dollars'] ?? 0);
        $last = mk_num($m['last_price_dollars'] ?? 0);
        $name = $binary ? 'Yes' : (trim((string)($m['yes_sub_title'] ?? '')) ?: ($m['title'] ?? ''));
        $outcomes[] = [
            'name'   => $name,
            'prob'   => round($prob, 4),
            'ask'    => mk_num($m['yes_ask_dollars'] ?? 0) ?: null,
            'd1'     => ($prev > 0 && $last > 0) ? round(($last - $prev) * 100, 1) : null,
            'd7'     => null,
            'volume' => $v,
            'hist'   => ['kalshi', $e['series_ticker'] ?? '', $m['ticker'] ?? ''],
        ];
    }
    if (!$outcomes || $vol <= 0) return null;
    $title = trim((string)($e['title'] ?? ''));
    return [
        'platform'  => 'kalshi',
        'pid'       => (string)$e['event_ticker'],
        'slug'      => null,
        'title'     => $title,
        'category'  => mk_kalshi_category((string)($e['category'] ?? '')),
        'url'       => 'https://kalshi.com/markets/' . strtolower((string)($e['series_ticker'] ?? '')),
        'image'     => null,
        'end'       => mk_iso($end),
        'created'   => mk_iso($created),
        'volume'    => $vol,      // contracts are $1 notional, so contracts ≈ USD
        'volume24'  => $vol24,
        'liquidity' => round($oi, 2),
        'binary'    => $binary,
        'outcomes'  => $outcomes,
    ];
}

/* ----------------------------------------------------------------- Limitless */

function mk_fetch_limitless(): array
{
    $urls = [];
    for ($p = 1; $p <= MK_LIMITLESS_PAGES; $p++) {
        $urls[$p] = "https://api.limitless.exchange/markets/active?page=$p&limit=25&sortBy=high_value";
    }
    $out = [];
    foreach (mk_get_json_multi($urls, 4) as $page) {
        foreach (($page['data'] ?? []) as $m) {
            $sub = !empty($m['markets']) ? $m['markets'] : [$m];
            $binary = count($sub) === 1;
            $outcomes = [];
            foreach ($sub as $s) {
                $prices = $s['prices'] ?? null;
                if (!$prices || !isset($prices[0])) continue;
                $p0 = (float)$prices[0];
                if ($p0 > 1) $p0 /= 100;
                $outcomes[] = [
                    'name' => $binary ? 'Yes' : (string)($s['title'] ?? ''),
                    'prob' => round($p0, 4), 'ask' => null, 'd1' => null, 'd7' => null,
                    'volume' => mk_num($s['volumeFormatted'] ?? 0), 'hist' => null,
                ];
            }
            $vol = mk_num($m['volumeFormatted'] ?? 0);
            if (!$outcomes || $vol < 100) continue;
            $cats = array_map('strtolower', $m['categories'] ?? []);
            $out[] = [
                'platform'  => 'limitless',
                'pid'       => (string)($m['id'] ?? $m['slug'] ?? ''),
                'slug'      => null,
                'title'     => trim((string)($m['title'] ?? '')),
                'category'  => in_array('crypto', $cats, true) ? 'crypto' : (in_array('sports', $cats, true) ? 'sports' : 'other'),
                'url'       => 'https://limitless.exchange/markets/' . ($m['slug'] ?? ''),
                'image'     => $m['logo'] ?? null,
                'end'       => mk_iso($m['expirationTimestamp'] ?? null),
                'created'   => mk_iso($m['createdAt'] ?? null),
                'volume'    => $vol,
                'volume24'  => 0,
                'liquidity' => 0,
                'binary'    => $binary,
                'outcomes'  => $outcomes,
            ];
        }
    }
    return $out;
}

/* ----------------------------------------------------------------- PredictIt */

function mk_fetch_predictit(): array
{
    $raw = mk_get_json('https://www.predictit.org/api/marketdata/all/', 2, 25);
    $out = [];
    foreach (($raw['markets'] ?? []) as $m) {
        $contracts = $m['contracts'] ?? [];
        $binary = count($contracts) === 1;
        $outcomes = [];
        $end = null;
        foreach ($contracts as $c) {
            $prob = mk_price($c['bestSellYesCost'] ?? null, $c['bestBuyYesCost'] ?? null, $c['lastTradePrice'] ?? null);
            if ($prob === null) continue;
            if (!empty($c['dateEnd']) && $c['dateEnd'] !== 'N/A') $end = $c['dateEnd'];
            $outcomes[] = [
                'name' => $binary ? 'Yes' : (string)($c['shortName'] ?? $c['name'] ?? ''),
                'prob' => round($prob, 4),
                'ask'  => mk_num($c['bestBuyYesCost'] ?? 0) ?: null,
                'd1'   => null, 'd7' => null, 'volume' => 0, 'hist' => null,
            ];
        }
        if (!$outcomes) continue;
        $out[] = [
            'platform' => 'predictit', 'pid' => (string)$m['id'], 'slug' => null,
            'title' => trim((string)($m['shortName'] ?? $m['name'] ?? '')),
            'category' => 'politics', 'url' => $m['url'] ?? 'https://www.predictit.org',
            'image' => $m['image'] ?? null, 'end' => mk_iso($end), 'created' => null,
            'volume' => 0, 'volume24' => 0, 'liquidity' => 0,
            'binary' => $binary, 'outcomes' => $outcomes,
        ];
    }
    return $out;
}
