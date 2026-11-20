<?php
// Combined prediction-market API proxy — 6 platforms
// Usage: api.php?src=kalshi|polymarket|predictit|manifold|sxbet|limitless
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$src = $_GET['src'] ?? '';
$cache = sys_get_temp_dir() . "/pm6_$src.json";
$cacheTtl = 60; // seconds

if (is_file($cache) && time() - filemtime($cache) < $cacheTtl) {
    readfile($cache);
    exit;
}

function fetch_json($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res !== false && $code === 200) ? json_decode($res, true) : null;
}

$out = [];

switch ($src) {

    // ---------------- KALSHI ----------------
    // Kalshi blocks this server's IP directly (Cloudflare datacenter-IP block),
    // so we go through a Cloudflare Worker proxy instead.
    // Replace this with YOUR worker URL after deploying kalshi-worker.js.
    case 'kalshi':
        $KALSHI_PROXY = 'https://kalshi-proxy2.naveen-p-bcf.workers.dev/proxy';
        $raw = fetch_json($KALSHI_PROXY . '/trade-api/v2/markets?limit=300&status=open&mve_filter=exclude');
        foreach (($raw['markets'] ?? []) as $m) {
            $bid = (float)($m['yes_bid_dollars'] ?? 0);
            $ask = (float)($m['yes_ask_dollars'] ?? 0);
            $last = (float)($m['last_price_dollars'] ?? 0);
            $price = $last > 0 ? $last : (($bid > 0 && $ask > 0) ? ($bid + $ask) / 2 : 0);
            $vol = (float)($m['volume_24h_fp'] ?? 0);
            if ($price <= 0 || $vol <= 0) continue;
            $out[] = [
                'source'   => 'Kalshi',
                'title'    => $m['title'] ?? $m['ticker'],
                'yes_pct'  => round($price * 100),
                'volume'   => $vol,
                'bid'      => round($bid * 100),
                'ask'      => round($ask * 100),
                'close'    => $m['close_time'] ?? null,
                'url'      => null,
            ];
        }
        break;

    // ---------------- POLYMARKET ----------------
    case 'polymarket':
        $raw = fetch_json('https://gamma-api.polymarket.com/markets?active=true&closed=false&limit=60&order=volume24hr&ascending=false');
        foreach (($raw ?? []) as $m) {
            $prices = json_decode($m['outcomePrices'] ?? '[]', true);
            if (!$prices) continue;
            $out[] = [
                'source'   => 'Polymarket',
                'title'    => $m['question'] ?? $m['groupItemTitle'] ?? '',
                'yes_pct'  => round((float)$prices[0] * 100),
                'volume'   => (float)($m['volume24hr'] ?? 0),
                'change_1d'=> isset($m['oneDayPriceChange']) ? round((float)$m['oneDayPriceChange'] * 100, 1) : null,
                'close'    => $m['endDate'] ?? null,
                'url'      => isset($m['slug']) ? "https://polymarket.com/event/{$m['slug']}" : null,
            ];
        }
        break;

    // ---------------- PREDICTIT ----------------
    case 'predictit':
        $raw = fetch_json('https://www.predictit.org/api/marketdata/all/');
        foreach (($raw['markets'] ?? []) as $m) {
            $contracts = [];
            foreach (($m['contracts'] ?? []) as $c) {
                if ($c['lastTradePrice'] === null) continue;
                $contracts[] = [
                    'name'  => $c['shortName'] ?? $c['name'],
                    'yes_pct' => round((float)$c['lastTradePrice'] * 100),
                ];
            }
            if (!$contracts) continue;
            // sort contracts by price desc, keep top 4
            usort($contracts, fn($a, $b) => $b['yes_pct'] <=> $a['yes_pct']);
            $out[] = [
                'source'    => 'PredictIt',
                'title'     => $m['shortName'] ?? $m['name'],
                'contracts' => array_slice($contracts, 0, 4),
                'url'       => $m['url'] ?? null,
            ];
        }
        break;

    // ---------------- MANIFOLD ----------------
    case 'manifold':
        $raw = fetch_json('https://api.manifold.markets/v0/markets?limit=100&sort=24-hour-vol');
        foreach (($raw ?? []) as $m) {
            if (($m['outcomeType'] ?? '') !== 'BINARY') continue;
            if (!isset($m['probability'])) continue;
            $vol24 = (float)($m['volume24Hours'] ?? 0);
            if ($vol24 <= 0) continue;
            $out[] = [
                'source'   => 'Manifold',
                'title'    => $m['question'] ?? '',
                'yes_pct'  => round((float)$m['probability'] * 100),
                'volume'   => $vol24,
                'bettors'  => $m['uniqueBettorCount'] ?? null,
                'close'    => isset($m['closeTime']) ? gmdate('c', intdiv($m['closeTime'], 1000)) : null,
                'url'      => $m['url'] ?? null,
            ];
        }
        break;

    // ---------------- SX BET ----------------
    // Note: /markets/active has no live odds; odds require a separate /orders call per marketHash.
    case 'sxbet':
        $raw = fetch_json('https://api.sx.bet/markets/active');
        foreach (($raw['data']['markets'] ?? []) as $m) {
            $out[] = [
                'source'    => 'SX Bet',
                'title'     => ($m['teamOneName'] ?? '') . ' vs ' . ($m['teamTwoName'] ?? ''),
                'line'      => $m['outcomeOneName'] ?? null,
                'league'    => $m['leagueLabel'] ?? null,
                'sport'     => $m['sportLabel'] ?? null,
                'gameTime'  => isset($m['gameTime']) ? gmdate('c', $m['gameTime']) : null,
                'url'       => null,
            ];
            if (count($out) >= 60) break;
        }
        break;

    // ---------------- LIMITLESS ----------------
    case 'limitless':
        $raw = fetch_json('https://api.limitless.exchange/markets/active?limit=60');
        foreach (($raw['data'] ?? []) as $m) {
            $prices = $m['prices'] ?? null;
            if (!$prices) continue;
            $vol = (float)($m['volumeFormatted'] ?? 0);
            $out[] = [
                'source'  => 'Limitless',
                'title'   => $m['title'] ?? '',
                'yes_pct' => round((float)$prices[0] * 100),
                'volume'  => $vol,
                'close'   => $m['expirationDate'] ?? null,
                'url'     => isset($m['slug']) ? "https://limitless.exchange/markets/{$m['slug']}" : null,
            ];
        }
        // sort by volume desc
        usort($out, fn($a, $b) => $b['volume'] <=> $a['volume']);
        $out = array_slice($out, 0, 40);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown src, use kalshi|polymarket|predictit|manifold|sxbet|limitless']);
        exit;
}

// default sort by volume where present (kalshi/polymarket/manifold/limitless)
if ($src !== 'predictit' && $src !== 'sxbet') {
    usort($out, fn($a, $b) => ($b['volume'] ?? 0) <=> ($a['volume'] ?? 0));
}

$json = json_encode(array_slice($out, 0, 40), JSON_UNESCAPED_SLASHES);
if ($out) {
    file_put_contents($cache, $json);
}
echo $json ?: '[]';