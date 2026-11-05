<?php
// Hostinger cron: /usr/bin/php /home/ACCOUNT/domains/lhorloger.shop/public_html/app/push-worker.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/push.php';
try { echo 'Notifications accepted: ' . push_dispatch(db(), 30) . PHP_EOL; }
catch (Throwable) { fwrite(STDERR, "Push worker failed.\n"); exit(1); }
