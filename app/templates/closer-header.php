<?php
require_once APP_ROOT . '/bootstrap.php';
require_closer();
$closerPageTitle = $closerPageTitle ?? 'Mon suivi';
$closerPath = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($closerPageTitle) ?> · L’Horloger</title>
  <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="any">
  <link rel="icon" type="image/png" sizes="48x48" href="<?= e(url('/favicon-48.png')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/brand.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/closer.css')) ?>">
</head>
<body class="closer-body">
  <header class="closer-top">
    <a class="brand brand-logo" href="<?= e(url('/closer/')) ?>" aria-label="L’Horloger - suivi closeuse">
      <img src="<?= e(url('/assets/brand/logo-mark.png')) ?>" alt="">
      <span class="brand-logo__wordmark">L’HORLOGER</span>
    </a>
    <div><span><?= e(admin_identity()) ?></span><a href="<?= e(url('/admin/logout.php')) ?>">Déconnexion</a></div>
  </header>
  <main class="closer-main">
    <?php if ($message = flash('success')): ?><p class="flash flash-success"><?= e($message) ?></p><?php endif; ?>
    <?php if ($message = flash('error')): ?><p class="flash flash-error"><?= e($message) ?></p><?php endif; ?>
