<?php
/** POST from the visual editor: p, changes (JSON list of {i, html?, attrs?, op?}), csrf. */
require __DIR__ . '/includes/ve.php';
require_json_perm('pages.edit');

$t = ve_target((string)($_POST['p'] ?? ''));
if (!$t) json_out(['error' => 'Page not found'], 404);
$changes = json_decode((string)($_POST['changes'] ?? ''), true);
if (!is_array($changes) || !$changes) json_out(['error' => 'Nothing to save'], 400);
if (count($changes) > 2000) json_out(['error' => 'Too many changes at once'], 400);

[$src, $applied, $skipped] = ve_apply($t['source'], $changes);
if (!$applied) json_out(['error' => 'Your changes could not be matched to the page source. Reload the editor and try again.'], 409);
if ($err = ve_save($t, $src)) json_out(['error' => $err], 400);
json_out(['ok' => true, 'applied' => $applied, 'skipped' => $skipped]);
