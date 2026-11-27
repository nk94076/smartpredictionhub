<?php
/** GET /api/event.php?id=<event id> — one event with every outcome, per-platform quotes and price history. */
define('SPH_APP', true);
if (is_file(__DIR__ . '/../app/config.php')) require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/markets/store.php';

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,200}$/', $id)) {
    http_response_code(400);
    mk_json_out(['error' => 'Bad id'], 10);
    exit;
}
$file = MK_CACHE_DIR . '/events/' . $id . '.json';
$event = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
if ($event) $event = mk_override($event);
if (!$event) {
    http_response_code(404);
    mk_json_out(['error' => 'Event not found'], 30);
    exit;
}

// 30-day history for the top outcomes, cached 15 minutes
$histFile = MK_CACHE_DIR . '/history/' . $id . '.json';
$history = null;
if (is_file($histFile) && filemtime($histFile) > time() - 900) {
    $history = json_decode((string)file_get_contents($histFile), true);
}
if ($history === null) {
    require_once __DIR__ . '/../app/markets/aggregate.php';
    $refs = [];
    foreach (array_slice($event['outcomes'], 0, 4) as $i => $o) $refs[$i] = $o['hist'];
    $history = [];
    foreach (mk_fetch_history($refs, '1m', 90) as $i => $pts) {
        $history[] = ['name' => $event['binary'] ? 'Yes' : $event['outcomes'][$i]['name'], 'points' => $pts];
    }
    mk_write($histFile, $history);
}

foreach ($event['outcomes'] as &$o) unset($o['hist']);
unset($o);
$event['history'] = $history;
$event['updated'] = gmdate('c', filemtime($file));
mk_json_out($event, 60);
