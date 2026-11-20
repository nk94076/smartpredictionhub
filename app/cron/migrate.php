<?php
/**
 * Create / upgrade database tables (safe to run any time) and rewrite the CMS cache files.
 *   php app/cron/migrate.php
 * deploy/deploy.sh runs this after every update.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('SPH_APP', true);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../schema.php';
foreach (run_migrations() as $line) {
    if (strpos($line, 'Table ready') !== 0) echo $line, "\n";
}
echo "Database up to date.\n";
