<?php
/** Read side of the market data: load cache, filter/sort/paginate, cards. Used by /api/*.php. */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';

function mk_load(): ?array
{
    static $data = null;
    if ($data !== null) return $data;
    $file = MK_CACHE_DIR . '/markets.json';
    if (!is_file($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function mk_cache_age(): ?int
{
    $file = MK_CACHE_DIR . '/markets.json';
    return is_file($file) ? time() - filemtime($file) : null;
}

/**
 * If cron has not refreshed the data for a while, refresh it after this response is sent
 * (PHP-FPM only). Cron stays the main way; this just keeps the site alive without it.
 */
function mk_refresh_if_stale(): void
{
    $age = mk_cache_age();
    if ($age !== null && $age < MK_STALE_AFTER) return;
    if (!function_exists('fastcgi_finish_request')) return;
    register_shutdown_function(function () {
        fastcgi_finish_request();
        ignore_user_abort(true);
        set_time_limit(600);
        require_once __DIR__ . '/aggregate.php';
        try { mk_refresh_locked(); } catch (Throwable $e) { error_log('markets refresh: ' . $e->getMessage()); }
    });
}

function mk_query(array $events, array $q): array
{
    $now = time();
    $tab = $q['tab'] ?? 'all';
    $cat = $q['category'] ?? '';
    $plats = array_filter(explode(',', (string)($q['platform'] ?? '')));
    $search = trim(mb_strtolower((string)($q['q'] ?? '')));
    $ends = $q['ends'] ?? '';
    $ids = array_filter(explode(',', (string)($q['ids'] ?? '')));

    $endsWithin = ['24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400, '1y' => 365 * 86400][$ends] ?? null;

    $events = array_values(array_filter($events, function ($e) use ($tab, $cat, $plats, $search, $endsWithin, $ids, $now) {
        if ($ids && !in_array($e['id'], $ids, true)) return false;
        if ($cat !== '' && $e['category'] !== $cat) return false;
        if ($plats && !array_intersect($plats, array_keys($e['platforms']))) return false;
        $end = $e['end'] ? strtotime($e['end']) : null;
        if ($endsWithin !== null && (!$end || $end < $now || $end - $now > $endsWithin)) return false;
        if ($search !== '') {
            $hay = mb_strtolower($e['title'] . ' ' . ($e['lead'] ?? '') . ' ' . implode(' ', array_column($e['outcomes'], 'name')));
            if (mb_strpos($hay, $search) === false) return false;
        }
        switch ($tab) {
            case 'matched':  return count($e['platforms']) > 1;
            case 'trending': return $e['volume24'] > 0;
            case 'breaking': return $e['change'] !== null && abs($e['change']) >= 3 && $e['volume24'] >= 10000;
            case 'new':      return $e['created'] && strtotime($e['created']) > $now - 14 * 86400;
            case 'ending':   return $end && $end > $now && $end - $now < 14 * 86400;
        }
        return true;
    }));

    $defaultSort = ['trending' => 'volume24', 'breaking' => 'move', 'new' => 'new', 'ending' => 'ends'][$tab] ?? 'volume';
    $sort = $q['sort'] ?? $defaultSort;
    $dir = ($q['dir'] ?? '') === 'asc' ? 1 : (($q['dir'] ?? '') === 'desc' ? -1 : ($sort === 'ends' ? 1 : -1));
    $key = [
        'volume'    => fn($e) => $e['volume'],
        'volume24'  => fn($e) => $e['volume24'],
        'liquidity' => fn($e) => $e['liquidity'],
        'consensus' => fn($e) => $e['consensus'],
        'change'    => fn($e) => $e['change'] ?? 0,
        'move'      => fn($e) => abs($e['change'] ?? 0),
        'ends'      => fn($e) => $e['end'] ? strtotime($e['end']) : PHP_INT_MAX,
        'new'       => fn($e) => $e['created'] ? strtotime($e['created']) : 0,
        'platforms' => fn($e) => count($e['platforms']) * 1e15 + $e['volume'],
    ][$sort] ?? fn($e) => $e['volume'];
    usort($events, fn($a, $b) => $dir * ($key($a) <=> $key($b)));

    $per = max(1, min(100, (int)($q['per'] ?? 50)));
    $page = max(1, (int)($q['page'] ?? 1));
    return [
        'total'  => count($events),
        'page'   => $page,
        'per'    => $per,
        'sort'   => $sort,
        'dir'    => $dir === 1 ? 'asc' : 'desc',
        'events' => array_slice($events, ($page - 1) * $per, $per),
    ];
}

/** The four summary cards at the top of the dashboard. */
function mk_cards(array $events): array
{
    $byVol24 = $events;
    usort($byVol24, fn($a, $b) => $b['volume24'] <=> $a['volume24']);
    $trending = array_map(fn($e) => mk_card_row($e), array_slice($byVol24, 0, 3));

    $movers = array_filter($events, fn($e) => $e['change'] !== null && $e['volume24'] >= 25000);
    usort($movers, fn($a, $b) => abs($b['change']) <=> abs($a['change']));
    $breaking = $movers ? mk_card_row(reset($movers)) : null;

    // biggest price gap between two platforms on the same outcome
    $gap = null;
    foreach ($events as $e) {
        if (count($e['platforms']) < 2 || $e['volume'] < 50000) continue;
        foreach ($e['outcomes'] as $o) {
            $q = $o['quotes'] ?? [];
            if (count($q) < 2) continue;
            $hi = max($q); $lo = min($q);
            if ($hi - $lo > ($gap['spread'] ?? 0) && $hi - $lo < 0.25 && $hi >= 0.05) {
                $gap = ['id' => $e['id'], 'title' => $e['title'], 'outcome' => $e['binary'] ? null : $o['name'],
                    'spread' => round($hi - $lo, 4), 'high' => [array_search($hi, $q), $hi], 'low' => [array_search($lo, $q), $lo]];
            }
        }
    }
    return ['trending' => $trending, 'breaking' => $breaking, 'gap' => $gap];
}

function mk_card_row(array $e): array
{
    return ['id' => $e['id'], 'title' => $e['title'], 'lead' => $e['lead'], 'category' => $e['category'],
        'consensus' => $e['consensus'], 'change' => $e['change'], 'spark' => $e['spark'], 'volume24' => $e['volume24']];
}

function mk_json_out($data, int $maxAge = 60): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
