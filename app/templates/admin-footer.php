<?php $adminOverridesVersion = (string) (@filemtime(dirname(APP_ROOT) . '/public/assets/css/admin-overrides.css') ?: '1'); ?>
</main></div><link rel="stylesheet" href="<?= e(url('/assets/css/admin-overrides.css?v=' . $adminOverridesVersion)) ?>"></body></html>
