<?php
/** Shared bootstrap: config + db + helpers */
if (!defined('SPH_APP')) { define('SPH_APP', true); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
