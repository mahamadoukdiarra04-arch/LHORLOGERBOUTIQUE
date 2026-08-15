<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

$cleared = function_exists('opcache_reset') && opcache_reset();
header('Content-Type: text/plain; charset=utf-8');
echo $cleared ? 'Runtime cache refreshed.' : 'Runtime cache reset is unavailable.';
