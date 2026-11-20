<?php
/**
 * Shared helpers: escaping, IP, user-agent parse, geo lookup, traffic source, formatting.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (TRUST_CLOUDFLARE && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cf, FILTER_VALIDATE_IP)) { $ip = $cf; }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function send_security_headers(string $csp): void
{
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: ' . $csp);
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/* ---------------- CSRF (session based) ---------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/* ---------------- User-Agent parsing ---------------- */
function parse_ua(?string $ua): array
{
    $ua = (string)$ua;
    $device = 'Desktop';
    if (preg_match('/bot|crawl|spider|slurp|curl|wget|python|headless/i', $ua)) {
        $device = 'Bot';
    } elseif (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua)) {
        $device = 'Mobile';
    }

    $browser = 'Other';
    $map = [
        'Edge' => '/Edg(e|A|iOS)?\//', 'Opera' => '/OPR\/|Opera/', 'Samsung Internet' => '/SamsungBrowser/',
        'UC Browser' => '/UCBrowser/', 'Firefox' => '/Firefox|FxiOS/', 'Chrome' => '/Chrome|CriOS/', 'Safari' => '/Safari/',
    ];
    foreach ($map as $name => $re) {
        if (preg_match($re, $ua)) { $browser = $name; break; }
    }

    $os = 'Other';
    $osMap = [
        'Android' => '/Android/', 'iOS' => '/iPhone|iPad|iPod/', 'Windows' => '/Windows/',
        'macOS' => '/Mac OS X|Macintosh/', 'Linux' => '/Linux/',
    ];
    foreach ($osMap as $name => $re) {
        if (preg_match($re, $ua)) { $os = $name; break; }
    }

    return ['device' => $device, 'browser' => $browser, 'os' => $os, 'ua' => mb_substr($ua, 0, 255)];
}

/* ---------------- IP -> Location ---------------- */
function http_get_json(string $url, int $timeout = 2): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'SPH/1.0']);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
    }
    if (!$body) { return null; }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

function geo_lookup(string $ip): array
{
    $out = ['country' => null, 'country_code' => null, 'region' => null, 'city' => null];

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $out['country'] = 'Local network';
        return $out;
    }

    $cached = q('SELECT country, country_code, region, city FROM ip_geo_cache WHERE ip = ? AND fetched_at > NOW() - INTERVAL 30 DAY', [$ip])->fetch();
    if ($cached) { return $cached; }

    if (GEO_LOOKUP_ENABLED) {
        $j = http_get_json('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,country_code,region,city');
        if ($j && !empty($j['success'])) {
            $out = ['country' => $j['country'] ?? null, 'country_code' => $j['country_code'] ?? null,
                    'region' => $j['region'] ?? null, 'city' => $j['city'] ?? null];
        } else {
            $j = http_get_json('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,regionName,city');
            if ($j && ($j['status'] ?? '') === 'success') {
                $out = ['country' => $j['country'] ?? null, 'country_code' => $j['countryCode'] ?? null,
                        'region' => $j['regionName'] ?? null, 'city' => $j['city'] ?? null];
            }
        }
    }

    // Cloudflare country header fallback
    if (!$out['country_code'] && !empty($_SERVER['HTTP_CF_IPCOUNTRY']) && preg_match('/^[A-Z]{2}$/', $_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $out['country_code'] = $_SERVER['HTTP_CF_IPCOUNTRY'];
        $out['country'] = $out['country'] ?: $_SERVER['HTTP_CF_IPCOUNTRY'];
    }

    if ($out['country_code']) {
        q('REPLACE INTO ip_geo_cache (ip, country, country_code, region, city, fetched_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$ip, $out['country'], $out['country_code'], $out['region'], $out['city']]);
    }
    return $out;
}

/* ---------------- Traffic source (where the visitor came from) ---------------- */
const FIRST_TOUCH_COOKIE = 'sph_ft';

/** Save the first-visit source in a cookie (if not already set). track.js writes the same cookie. */
function capture_first_touch(): void
{
    if (!empty($_COOKIE[FIRST_TOUCH_COOKIE])) { return; }
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref && parse_url($ref, PHP_URL_HOST) === $host) { $ref = ''; } // ignore internal referrers
    $data = [
        'us' => mb_substr((string)($_GET['utm_source'] ?? ''), 0, 100),
        'um' => mb_substr((string)($_GET['utm_medium'] ?? ''), 0, 100),
        'uc' => mb_substr((string)($_GET['utm_campaign'] ?? ''), 0, 150),
        'gc' => isset($_GET['gclid']) ? 1 : 0,
        'fc' => isset($_GET['fbclid']) ? 1 : 0,
        'ref' => mb_substr($ref, 0, 500),
        'land' => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
        'ts' => time(),
    ];
    $val = json_encode($data, JSON_UNESCAPED_SLASHES);
    setcookie(FIRST_TOUCH_COOKIE, $val, ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => is_https(), 'httponly' => false, 'samesite' => 'Lax']);
    $_COOKIE[FIRST_TOUCH_COOKIE] = $val;
}

/** Convert cookie data into source / medium / campaign */
function resolve_source(): array
{
    $raw = json_decode((string)($_COOKIE[FIRST_TOUCH_COOKIE] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $clean = fn($v, $n) => mb_substr(trim(strip_tags((string)$v)), 0, $n);

    $us = strtolower($clean($raw['us'] ?? '', 100));
    $um = strtolower($clean($raw['um'] ?? '', 100));
    $uc = $clean($raw['uc'] ?? '', 150);
    $ref = $clean($raw['ref'] ?? '', 500);
    $land = $clean($raw['land'] ?? '', 500);
    $refHost = strtolower((string)parse_url($ref, PHP_URL_HOST));
    $refHost = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $refHost);

    if ($us !== '') {
        $source = $us; $medium = $um ?: 'campaign';
    } elseif (!empty($raw['gc'])) {
        $source = 'google'; $medium = 'cpc';
    } elseif (!empty($raw['fc'])) {
        $source = 'facebook'; $medium = 'social';
    } elseif ($refHost !== '') {
        $known = [
            'google' => ['google', 'organic'], 'bing' => ['bing', 'organic'], 'yahoo' => ['yahoo', 'organic'],
            'duckduckgo' => ['duckduckgo', 'organic'], 'facebook' => ['facebook', 'social'], 'instagram' => ['instagram', 'social'],
            't.co' => ['twitter', 'social'], 'twitter' => ['twitter', 'social'], 'x.com' => ['twitter', 'social'],
            'youtube' => ['youtube', 'social'], 'linkedin' => ['linkedin', 'social'], 'reddit' => ['reddit', 'social'],
            'whatsapp' => ['whatsapp', 'social'], 'telegram' => ['telegram', 'social'], 't.me' => ['telegram', 'social'],
        ];
        $source = $refHost; $medium = 'referral';
        foreach ($known as $needle => [$s, $m]) {
            if (str_contains($refHost, $needle)) { $source = $s; $medium = $m; break; }
        }
    } else {
        $source = 'direct'; $medium = 'none';
    }
    return ['source' => $source, 'medium' => $medium, 'campaign' => $uc ?: null, 'referrer' => $ref ?: null, 'landing' => $land ?: null];
}

/* ---------------- Formatting ---------------- */
function fmt_dt(?string $dt, string $format = 'd M Y, h:i A'): string
{
    return $dt ? date($format, strtotime($dt)) : '—';
}

function time_ago(?string $dt): string
{
    if (!$dt) { return 'Never'; }
    $d = time() - strtotime($dt);
    if ($d < 60) { return 'Just now'; }
    if ($d < 3600) { return floor($d / 60) . ' min ago'; }
    if ($d < 86400) { return floor($d / 3600) . ' hr ago'; }
    if ($d < 86400 * 30) { return floor($d / 86400) . ' days ago'; }
    return date('d M Y', strtotime($dt));
}

function fmt_duration(?int $sec): string
{
    if ($sec === null) { return '—'; }
    if ($sec < 60) { return $sec . 's'; }
    if ($sec < 3600) { return floor($sec / 60) . 'm ' . ($sec % 60) . 's'; }
    if ($sec < 86400) { return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm'; }
    return floor($sec / 86400) . 'd ' . floor(($sec % 86400) / 3600) . 'h';
}

function flag(?string $cc): string
{
    if (!$cc || !preg_match('/^[A-Za-z]{2}$/', $cc)) { return '🌐'; }
    $cc = strtoupper($cc);
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
}

function initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string)$name)) ?: [];
    $i = '';
    foreach (array_slice($parts, 0, 2) as $p) { $i .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $i ?: '?';
}

function location_str(?string $city, ?string $country): string
{
    $parts = array_filter([$city, $country]);
    return $parts ? implode(', ', $parts) : 'Unknown';
}
