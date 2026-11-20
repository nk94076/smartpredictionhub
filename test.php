<?php
header('Content-Type: application/json');

function try_url($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $code, 'error' => $err ?: null, 'got_bytes' => $res ? strlen($res) : 0];
}

echo json_encode([
    'php_version'        => PHP_VERSION,
    'curl_version'       => curl_version()['version'] ?? 'unknown',
    'ssl_version'        => curl_version()['ssl_version'] ?? 'unknown',
    'allow_url_fopen'    => ini_get('allow_url_fopen') ? 'on' : 'off',
    'test_google'        => try_url('https://www.google.com'),
    'test_httpbin'       => try_url('https://httpbin.org/get'),
    'test_kalshi'        => try_url('https://api.elections.kalshi.com/trade-api/v2/markets?limit=1'),
    'test_polymarket'    => try_url('https://gamma-api.polymarket.com/markets?limit=1'),
], JSON_PRETTY_PRINT);