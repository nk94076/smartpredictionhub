<?php
/**
 * Merge platform events into one list: match the same question across platforms,
 * build a volume-weighted consensus, a plain-English summary, sparklines and site stats.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/sources.php';

/* ------------------------------------------------------------ text matching */

const MK_STOP = ['will', 'the', 'a', 'an', 'of', 'in', 'on', 'by', 'be', 'who', 'what', 'which', 'is', 'to',
    'for', 'and', 'or', 'at', 'with', 'next', 'before', 'after', 'end', 'how', 'many', 'much', 'does', 'do',
    'than', 'any', 'this', 'that', 'there', 'us', 'vs', 'v', 'out', 'happen', 'yes', 'no'];

function mk_tokens(string $s): array
{
    $s = strtolower($s);
    $s = str_replace(['u.s.', '&', '’', "'s"], ['us', ' and ', '', ''], $s);
    $s = preg_replace('/(?<=\d)\.(?=\d)/', 'p', $s); // keep decimals together: 0.25 -> 0p25
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $syn = ['presidential' => 'president', 'nomination' => 'nominee', 'winner' => 'win', 'wins' => 'win',
        'won' => 'win', 'champions' => 'champion', 'championship' => 'champion', 'elections' => 'election',
        'democrat' => 'democratic', 'democrats' => 'democratic', 'republicans' => 'republican',
        'gop' => 'republican', 'presidency' => 'president', 'ncaa' => 'college', 'ncaaf' => 'college', 'btc' => 'bitcoin', 'eth' => 'ethereum', 'fed' => 'fed', 'federal' => 'fed'];
    $out = [];
    foreach (explode(' ', trim($s)) as $w) {
        if ($w === '' || in_array($w, MK_STOP, true)) continue;
        $out[$syn[$w] ?? $w] = true;
    }
    return array_keys($out);
}

function mk_years(array $tokens): array
{
    return array_values(array_filter($tokens, fn($t) => preg_match('/^20\d\d$/', $t)));
}

function mk_dice(array $a, array $b): float
{
    if (!$a || !$b) return 0.0;
    return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
}

/** Rarity weight of a word across all titles in this run (set by mk_merge). */
function mk_idf(string $t): float
{
    return $GLOBALS['MK_IDF'][$t] ?? 3.0;
}

/** Dice similarity where rare words ("ethereum", "basketball") count more than common ones ("2026", "win"). */
function mk_wdice(array $a, array $b): float
{
    $sum = fn(array $x) => array_sum(array_map('mk_idf', $x));
    $den = $sum($a) + $sum($b);
    return $den > 0 ? 2 * $sum(array_intersect($a, $b)) / $den : 0.0;
}

function mk_name_key(string $n): string
{
    return implode(' ', mk_tokens($n));
}

/** Do two outcome names refer to the same thing? ("Gavin Newsom" / "Newsom", "J.D. Vance" / "JD Vance") */
function mk_same_outcome(string $a, string $b): bool
{
    $ka = mk_name_key($a); $kb = mk_name_key($b);
    if ($ka === '' || $kb === '') return false;
    if ($ka === $kb) return true;
    $ta = explode(' ', $ka); $tb = explode(' ', $kb);
    if (count($ta) >= 2 && count($tb) >= 2 && end($ta) === end($tb) && $ta[0][0] === $tb[0][0]) return true;
    // "Buffalo" / "Buffalo Bills", "Los Angeles R" / "Los Angeles Rams": every word of the
    // shorter name (no numbers) starts a word of the longer one, in order
    [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
    // "Vance" / "J.D. Vance": a lone surname
    if (count($short) === 1 && count($long) === 2 && strlen($short[0]) >= 4 && $short[0] === $long[1]) return true;
    if (!preg_match('/\d/', implode('', $short)) && strlen(implode('', $short)) >= 4) {
        $ok = true;
        foreach ($short as $i => $w) {
            if (!isset($long[$i]) || strpos($long[$i], $w) !== 0) { $ok = false; break; }
        }
        if ($ok) return true;
    }
    return mk_dice($ta, $tb) >= 0.8;
}

/** Words that change the question: if only one side has one, it is a different market. */
const MK_DISCRIMINATORS = ['vp', 'vice', 'president', 'senate', 'house', 'governor', 'mayor', 'decade', 'month',
    'week', 'primary', 'runoff', 'party', 'popular', 'margin', 'turnout', 'seats', 'speaker', 'nominee', 'mvp',
    'coach', 'rookie', 'spread', 'total', 'leader', 'minister', 'cabinet', 'cut', 'cuts', 'hike', 'high', 'low',
    'above', 'below', 'between', 'first', 'last', 'top', 'most', 'least', 'ipo', 'ceo', 'fdv', 'token', 'airdrop',
    'champion', 'draft', 'trade', 'debate', 'convention', 'impeach', 'impeached', 'removed', 'resign',
    'hit', 'reach', 'meeting', 'close', 'price', 'count', 'number', 'defense', 'prime', 'secretary', 'attorney',
    'general', 'chief', 'justice', 'chair', 'treasury', 'state', 'foreign', 'finance', 'interior',
    'football', 'basketball', 'baseball', 'hockey', 'soccer', 'tennis', 'golf', 'nfc', 'afc', 'nba', 'nfl', 'nhl',
    'mlb', 'wnba', 'women', 'womens', 'men', 'mens', 'east', 'west', 'eastern', 'western', 'conference', 'division'];

/** Score how likely two platform events are the same question (0 = no). */
function mk_match_score(array $u, array $e): float
{
    if ($u['binary'] !== $e['binary']) return 0;
    $ya = mk_years($u['tok']); $yb = mk_years($e['tok']);
    if ($ya && $yb && !array_intersect($ya, $yb)) return 0;
    $closeTogether = $u['end'] && $e['end'] && abs(strtotime($u['end']) - strtotime($e['end'])) <= 60 * 86400;
    if (($ya xor $yb) && !$closeTogether) return 0; // year on one side only: dates must agree
    $onlyOne = array_merge(array_diff($u['tok'], $e['tok']), array_diff($e['tok'], $u['tok']));
    if (array_intersect($onlyOne, MK_DISCRIMINATORS)) return 0;
    $score = mk_wdice($u['tok'], $e['tok']);
    if ($score < 0.62) return 0;
    // each side has its own rare subject word ("ethereum" vs "inflation"): only trust it
    // when most named outcomes (people/teams, not number thresholds) also agree
    $rare = fn(array $x) => (bool)array_filter($x, fn($t) => mk_idf($t) >= 4.5 && !preg_match('/^\d/', $t));
    $bothSides = $rare(array_diff($u['tok'], $e['tok'])) && $rare(array_diff($e['tok'], $u['tok']));
    if ($bothSides && $e['binary']) return 0;
    if ($e['binary']) {
        // binary questions must also close at about the same time
        if ($u['end'] && $e['end'] && abs(strtotime($u['end']) - strtotime($e['end'])) > 45 * 86400) return 0;
        return $score >= 0.7 ? $score : 0;
    }
    // multi-outcome: the leading outcomes on one side must mostly exist on the other
    $top = $e['outcomes'];
    usort($top, fn($x, $y) => $y['prob'] <=> $x['prob']);
    $top = array_slice($top, 0, 20);
    $shared = 0;
    foreach ($top as $o) {
        if ($bothSides && !preg_match('/[a-z]{3,}/', str_replace(['above', 'below', 'over', 'under', 'more', 'less', 'between', 'than'], '', mk_name_key($o['name'])))) continue;
        foreach ($u['outcomes'] as $uo) {
            if (mk_same_outcome($o['name'], $uo['name'])) { $shared++; break; }
        }
    }
    $need = max(min(2, count($top), count($u['outcomes'])), (int)ceil(count($top) * ($bothSides ? 0.6 : 0.3)));
    if ($shared < $need) return 0;
    return $score + min(0.3, $shared / 20);
}

/* ------------------------------------------------------------------- merge */

function mk_merge(array $platformEvents): array
{
    // biggest first, so the best-known listing becomes the anchor
    usort($platformEvents, fn($a, $b) => $b['volume'] <=> $a['volume']);

    // word rarity across every title, for weighted matching
    $df = [];
    foreach ($platformEvents as $e) foreach (mk_tokens($e['title']) as $t) $df[$t] = ($df[$t] ?? 0) + 1;
    $n = max(1, count($platformEvents));
    $GLOBALS['MK_IDF'] = array_map(fn($c) => log(($n + 1) / $c) + 0.5, $df);

    $unified = [];
    $index = []; // token => [unified idx]
    foreach ($platformEvents as $e) {
        if ($e['title'] === '') continue;
        $e['tok'] = mk_tokens($e['title']);
        $best = null; $bestScore = 0;
        $cands = [];
        foreach ($e['tok'] as $t) foreach ($index[$t] ?? [] as $i) $cands[$i] = true;
        foreach (array_keys($cands) as $i) {
            if (isset($unified[$i]['src'][$e['platform']])) continue;
            $s = mk_match_score($unified[$i]['anchor'], $e);
            if ($s > $bestScore) { $bestScore = $s; $best = $i; }
        }
        if ($best === null) {
            $best = count($unified);
            $unified[] = ['anchor' => $e, 'src' => []];
            foreach ($e['tok'] as $t) {
                if (count($index[$t] ?? []) < 400) $index[$t][] = $best;
            }
        }
        $unified[$best]['src'][$e['platform']] = $e;
    }
    return array_map('mk_build_event', $unified);
}

function mk_slugify(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

function mk_build_event(array $u): array
{
    $a = $u['anchor'];
    $src = $u['src'];
    $poly = $src['polymarket'] ?? null;

    // merge outcomes across platforms
    $outs = [];
    foreach ($src as $plat => $e) {
        $w = max(1.0, sqrt(max($e['volume'], 1)));
        foreach ($e['outcomes'] as $o) {
            // exact name first, then a fuzzy match — never two outcomes from the same platform
            $k = null;
            if ($a['binary'] && $outs) $k = 0;
            $key = mk_name_key($o['name']);
            foreach ($k === null ? $outs : [] as $i => $x) {
                if (!isset($x['quotes'][$plat]) && mk_name_key($x['name']) === $key) { $k = $i; break; }
            }
            foreach ($k === null ? $outs : [] as $i => $x) {
                if (!isset($x['quotes'][$plat]) && mk_same_outcome($x['name'], $o['name'])) { $k = $i; break; }
            }
            if ($k === null) {
                $k = count($outs);
                $outs[] = ['name' => $o['name'], 'quotes' => [], 'w' => 0, 'sum' => 0, 'd1' => null, 'd7' => null, 'hist' => null, 'volume' => 0];
            }
            $outs[$k]['quotes'][$plat] = ['p' => $o['prob'], 'ask' => $o['ask']];
            $outs[$k]['sum'] += $o['prob'] * $w;
            $outs[$k]['w'] += $w;
            $outs[$k]['volume'] += $o['volume'];
            $outs[$k]['d1'] = $outs[$k]['d1'] ?? $o['d1'];
            $outs[$k]['d7'] = $outs[$k]['d7'] ?? $o['d7'];
            $outs[$k]['hist'] = $outs[$k]['hist'] ?? $o['hist'];
        }
    }
    foreach ($outs as &$o) {
        $o['prob'] = round($o['sum'] / max($o['w'], 1e-9), 4);
        $best = null;
        foreach ($o['quotes'] as $plat => $q) {
            $price = $q['ask'] ?? $q['p'];
            if ($price > 0 && ($best === null || $price < $best['price'])) $best = ['platform' => $plat, 'price' => $price];
        }
        $o['best'] = $best;
        unset($o['sum'], $o['w']);
    }
    unset($o);
    usort($outs, fn($x, $y) => $y['prob'] <=> $x['prob']);

    $platforms = [];
    $vol = $vol24 = $liq = 0;
    $created = null;
    foreach ($src as $plat => $e) {
        $platforms[$plat] = ['url' => $e['url'], 'volume' => round($e['volume']), 'volume24' => round($e['volume24']),
            'liquidity' => round($e['liquidity']), 'title' => $e['title'], 'markets' => count($e['outcomes'])];
        $vol += $e['volume']; $vol24 += $e['volume24']; $liq += $e['liquidity'];
        if ($e['created'] && (!$created || $e['created'] < $created)) $created = $e['created'];
    }
    // order platforms by volume
    uasort($platforms, fn($x, $y) => $y['volume'] <=> $x['volume']);

    $id = $poly && $poly['slug'] ? $poly['slug'] : $a['platform'] . '-' . mk_slugify($a['pid']);
    $lead = $outs[0];
    return [
        'id'        => $id,
        'title'     => $poly['title'] ?? $a['title'],
        'category'  => $poly['category'] ?? $a['category'],
        'image'     => $poly['image'] ?? $a['image'],
        'end'       => $a['end'],
        'created'   => $created,
        'binary'    => $a['binary'],
        'volume'    => round($vol),
        'volume24'  => round($vol24),
        'liquidity' => round($liq),
        'platforms' => $platforms,
        'consensus' => $lead['prob'],
        'change'    => $lead['d1'],
        'change7'   => $lead['d7'],
        'lead'      => $a['binary'] ? null : $lead['name'],
        'best'      => $lead['best'],
        'summary'   => mk_summary($a['binary'], $outs),
        'markets'   => count($outs),
        'outcomes'  => $outs,
        'spark'     => [],
    ];
}

function mk_pct(float $p): string
{
    $v = $p * 100;
    if ($v > 0 && $v < 1) return '<1%';
    if ($v > 99 && $v < 100) return '>99%';
    return round($v) . '%';
}

function mk_summary(bool $binary, array $outs): string
{
    $lead = $outs[0];
    $p = $lead['prob'];
    $best = '';
    if ($lead['best']) {
        $c = $lead['best']['price'] * 100;
        $best = ' · best ' . rtrim(rtrim(number_format($c, 1), '0'), '.') . '¢ on ' . MK_PLATFORMS[$lead['best']['platform']][0];
    }
    if ($binary) return mk_pct($p) . ' chance this happens' . $best;
    $second = $outs[1]['prob'] ?? 0;
    $n = $lead['name'];
    if ($p >= 0.85) $txt = "$n is very likely at " . mk_pct($p);
    elseif ($p >= 0.5 && $p - $second < 0.1) $txt = "Close call — $n leads at " . mk_pct($p);
    elseif ($p >= 0.6) $txt = "$n is likely at " . mk_pct($p);
    elseif ($p >= 0.45) $txt = "$n is slightly favoured at " . mk_pct($p);
    else $txt = "No clear favourite — $n leads at " . mk_pct($p);
    return $txt . $best;
}

/* ---------------------------------------------------------------- history */

/** Fetch price history for a list of [key => hist-ref]. Returns [key => [[t, p], ...]]. */
function mk_fetch_history(array $refs, string $range = '1w', int $points = 42): array
{
    $now = time();
    $seconds = $range === '1m' ? 30 * 86400 : 7 * 86400;
    $polyUrls = [];
    $out = [];
    foreach ($refs as $k => $h) {
        if (!$h) continue;
        if ($h[0] === 'poly') {
            $polyUrls[$k] = 'https://clob.polymarket.com/prices-history?market=' . rawurlencode($h[1])
                . '&interval=' . ($range === '1m' ? '1m' : '1w') . '&fidelity=' . ($range === '1m' ? 720 : 180);
        }
    }
    foreach (mk_get_json_multi($polyUrls, 8, 15) as $k => $r) {
        $pts = [];
        foreach (($r['history'] ?? []) as $x) $pts[] = [(int)$x['t'], round((float)$x['p'], 4)];
        if ($pts) $out[$k] = mk_downsample($pts, $points);
    }
    $base = null;
    foreach ($refs as $k => $h) {
        if (!$h || $h[0] !== 'kalshi' || !$h[1] || !$h[2]) continue;
        $base = $base ?? mk_kalshi_base();
        if (!$base) break;
        $url = $base . '/trade-api/v2/series/' . rawurlencode($h[1]) . '/markets/' . rawurlencode($h[2])
            . '/candlesticks?start_ts=' . ($now - $seconds) . '&end_ts=' . $now . '&period_interval=' . ($range === '1m' ? 1440 : 60);
        $r = mk_get_json($url, 3, 15);
        $pts = [];
        foreach (($r['candlesticks'] ?? []) as $c) {
            $p = $c['price']['close_dollars'] ?? $c['price']['previous_dollars'] ?? null;
            if ($p === null && isset($c['yes_bid']['close_dollars'], $c['yes_ask']['close_dollars'])) {
                $p = ((float)$c['yes_bid']['close_dollars'] + (float)$c['yes_ask']['close_dollars']) / 2;
            }
            if ($p !== null && $p !== '') $pts[] = [(int)$c['end_period_ts'], round((float)$p, 4)];
        }
        if ($pts) $out[$k] = mk_downsample($pts, $points);
        usleep(150000);
    }
    return $out;
}

function mk_downsample(array $pts, int $n): array
{
    $c = count($pts);
    if ($c <= $n) return $pts;
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = $pts[(int)round($i * ($c - 1) / ($n - 1))];
    return $out;
}

/* ------------------------------------------------------------------ build */

function mk_stats(array $events, array $platformCounts): array
{
    $vol = $vol24 = $liq = $markets = $matched = 0;
    $byPlat = array_fill_keys(array_keys(MK_PLATFORMS), 0);
    $cats = array_fill_keys(array_keys(MK_CATEGORIES), 0);
    foreach ($events as $e) {
        $vol += $e['volume']; $vol24 += $e['volume24']; $liq += $e['liquidity'];
        $markets += $e['markets'];
        if (count($e['platforms']) > 1) $matched++;
        foreach ($e['platforms'] as $p => $x) $byPlat[$p] += $x['volume24'];
        $cats[$e['category']] = ($cats[$e['category']] ?? 0) + 1;
    }
    $pk = $byPlat['polymarket'] + $byPlat['kalshi'];
    return [
        'volume'    => $vol,
        'volume24'  => $vol24,
        'liquidity' => $liq,
        'events'    => count($events),
        'markets'   => $markets,
        'matched'   => $matched,
        'platforms' => count(array_filter($platformCounts)),
        'platform_counts' => $platformCounts,
        'share'     => $pk > 0 ? ['polymarket' => round($byPlat['polymarket'] / $pk * 100), 'kalshi' => round($byPlat['kalshi'] / $pk * 100)] : null,
        'categories' => $cats,
    ];
}

/** Full refresh: fetch every platform, merge, add sparklines, write cache files. */
function mk_refresh(bool $verbose = false): array
{
    $t0 = microtime(true);
    $log = function ($m) use ($verbose, $t0) { if ($verbose) printf("[%5.1fs] %s\n", microtime(true) - $t0, $m); };

    $all = [];
    $counts = [];
    require_once dirname(__DIR__) . '/site.php';
    $off = sph_market_overrides()['disabled_platforms'];
    foreach (['polymarket', 'kalshi', 'limitless', 'predictit'] as $p) {
        if (in_array($p, $off, true)) { $log("$p: switched off in admin"); continue; }
        try {
            $list = ('mk_fetch_' . $p)();
        } catch (Throwable $ex) {
            error_log("markets: $p failed: " . $ex->getMessage());
            $list = [];
        }
        if ($p === 'kalshi') { // Kalshi lists thousands of tiny events; keep the meaningful ones
            usort($list, fn($a, $b) => $b['volume'] <=> $a['volume']);
            $list = array_slice($list, 0, 1500);
        }
        $counts[$p] = count($list);
        $log("$p: " . count($list) . ' events');
        $all = array_merge($all, $list);
    }
    if (!$all) throw new RuntimeException('No platform returned data');

    $events = mk_merge($all);
    usort($events, fn($a, $b) => $b['volume'] <=> $a['volume']);
    $events = array_slice($events, 0, MK_KEEP_EVENTS);
    $log('merged: ' . count($events) . ' events, ' . count(array_filter($events, fn($e) => count($e['platforms']) > 1)) . ' matched');

    // sparklines: biggest events + today's movers
    $want = [];
    foreach (array_slice($events, 0, MK_HISTORY_EVENTS) as $i => $e) $want[$i] = true;
    $byVol24 = $events;
    uasort($byVol24, fn($a, $b) => $b['volume24'] <=> $a['volume24']);
    foreach (array_slice(array_keys($byVol24), 0, 40) as $i) $want[$i] = true;
    $refs = [];
    foreach (array_keys($want) as $i) $refs[$i] = $events[$i]['outcomes'][0]['hist'];
    foreach (mk_fetch_history($refs) as $i => $pts) {
        $events[$i]['spark'] = array_column($pts, 1);
        if ($events[$i]['change7'] === null && count($pts) > 1) {
            $events[$i]['change7'] = round(($pts[count($pts) - 1][1] - $pts[0][1]) * 100, 1);
        }
    }
    $log('sparklines: ' . count(array_filter(array_column($events, 'spark'))));

    $stats = mk_stats($events, $counts);
    $stats['updated'] = gmdate('c');
    $stats['took'] = round(microtime(true) - $t0, 1);

    // list file: everything the tables need, outcomes trimmed
    $list = [];
    foreach ($events as $e) {
        $row = $e;
        $row['outcomes'] = array_map(fn($o) => ['name' => $o['name'], 'prob' => $o['prob'], 'd1' => $o['d1'],
            'best' => $o['best'], 'quotes' => array_map(fn($q) => $q['p'], $o['quotes'])], array_slice($e['outcomes'], 0, 6));
        $list[] = $row;
    }
    mk_write(MK_CACHE_DIR . '/markets.json', ['stats' => $stats, 'events' => $list]);

    // one detail file per event
    $dir = MK_CACHE_DIR . '/events';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $keep = [];
    foreach ($events as $e) {
        $file = mk_slugify($e['id']) . '.json';
        $keep[$file] = true;
        mk_write("$dir/$file", $e);
    }
    foreach (glob("$dir/*.json") ?: [] as $f) {
        if (!isset($keep[basename($f)]) && filemtime($f) < time() - 7 * 86400) @unlink($f);
    }
    $log('done');
    return $stats;
}

function mk_write(string $file, $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    rename($tmp, $file);
}

/** Run a refresh unless another process is already doing it. */
function mk_refresh_locked(bool $verbose = false): ?array
{
    if (!is_dir(MK_CACHE_DIR)) mkdir(MK_CACHE_DIR, 0755, true);
    $fh = fopen(MK_CACHE_DIR . '/refresh.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) return null;
    try {
        return mk_refresh($verbose);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
