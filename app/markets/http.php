<?php
/** Small HTTP helpers for the market aggregator (curl, gzip, retries, parallel). */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

function mk_curl_handle(string $url, int $timeout = 20)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '', // accept gzip — Polymarket requires it for large pages
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SmartPredictionHub/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    return $ch;
}

/** GET one URL as JSON. Retries on 429/5xx with backoff. Returns null on failure. */
function mk_get_json(string $url, int $tries = 3, int $timeout = 20)
{
    for ($i = 0; $i < $tries; $i++) {
        $ch = mk_curl_handle($url, $timeout);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }
        if ($code !== 429 && $code < 500 && $code !== 0) return null; // 4xx: no point retrying
        usleep((int)(($code === 429 ? 1500000 : 800000) * ($i + 1)));
    }
    return null;
}

/** GET many URLs at once (max $concurrency in flight). Returns [key => decoded json|null]. */
function mk_get_json_multi(array $urls, int $concurrency = 8, int $timeout = 20): array
{
    $out = array_fill_keys(array_keys($urls), null);
    $queue = $urls;
    $mh = curl_multi_init();
    $active = [];
    $add = function () use (&$queue, &$active, $mh, $timeout) {
        $key = array_key_first($queue);
        $ch = mk_curl_handle($queue[$key], $timeout);
        unset($queue[$key]);
        curl_multi_add_handle($mh, $ch);
        $active[(int)$ch] = [$key, $ch];
    };
    while ($queue && count($active) < $concurrency) $add();
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            [$key] = $active[(int)$ch];
            if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $data = json_decode((string)curl_multi_getcontent($ch), true);
                $out[$key] = is_array($data) ? $data : null;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[(int)$ch]);
            if ($queue) $add();
        }
    } while ($running || $active);
    curl_multi_close($mh);
    return $out;
}
