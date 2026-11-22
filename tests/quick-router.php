<?php
// php -S 127.0.0.1:8091 -t public tests/quick-router.php
if (PHP_SAPI !== 'cli-server') exit;
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = realpath(__DIR__ . '/../public');
$file = realpath($root . $path);
if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) return false;
if (str_ends_with($path, '/')) {
    $index = realpath($root . $path . 'index.php');
    if ($index && str_starts_with($index, $root . DIRECTORY_SEPARATOR)) { require $index; return true; }
}
http_response_code(404);
echo 'Page introuvable';
