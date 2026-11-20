<?php
/**
 * Refresh live market data from all platforms into app/cache/.
 * Run from cron every 5 minutes (see DEPLOY.md):
 *   php /path/to/site/app/cron/refresh-markets.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('SPH_APP', true);
if (is_file(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../markets/aggregate.php';
set_time_limit(600);

$verbose = in_array('-v', $argv, true);
try {
    $stats = mk_refresh_locked($verbose);
    if ($stats === null) { fwrite(STDERR, "Another refresh is running.\n"); exit(0); }
    if ($verbose) echo json_encode($stats, JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Refresh failed: ' . $e->getMessage() . "\n");
    exit(1);
}
