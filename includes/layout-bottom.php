<?php if (!defined('SPH_APP')) { http_response_code(404); exit; } ?>
<?php /* Shared page bottom, generated from index.html by deploy/build-layout.py. */ ?>
<?= sph_chrome('footer') ?>
    <script src="/assets/markets.js?v=<?= @filemtime(__DIR__ . "/../assets/markets.js") ?>" defer></script>
<?= $extraScripts ?? '' ?>
<?= sph_body_end() ?>
</body>
</html>
