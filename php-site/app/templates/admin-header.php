<?php
require_once APP_ROOT . '/bootstrap.php';
require_admin();
$adminPageTitle = $adminPageTitle ?? 'Administration';
$adminPath = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($adminPageTitle) ?> · L’Horloger</title><link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>"></head><body class="admin-body"><header class="admin-top"><a class="brand" href="<?= e(url('/admin/')) ?>">L’HORLOGER <small>ADMIN</small></a><div><span><?= e(admin_identity()) ?></span><a href="<?= e(url('/admin/logout.php')) ?>">Déconnexion</a></div></header><div class="admin-layout"><aside class="admin-nav"><a class="<?= str_ends_with($adminPath, '/admin/index.php') || str_ends_with($adminPath, '/admin/') ? 'active' : '' ?>" href="<?= e(url('/admin/')) ?>">Vue d’ensemble</a><a class="<?= str_contains($adminPath, '/orders.php') ? 'active' : '' ?>" href="<?= e(url('/admin/orders.php')) ?>">Commandes</a><a class="<?= str_contains($adminPath, '/stock.php') ? 'active' : '' ?>" href="<?= e(url('/admin/stock.php')) ?>">Stock & coûts</a><a class="<?= str_contains($adminPath, '/analysis.php') ? 'active' : '' ?>" href="<?= e(url('/admin/analysis.php')) ?>">Analyse produits</a></aside><main class="admin-main">
<?php if ($message = flash('success')): ?><p class="flash flash-success"><?= e($message) ?></p><?php endif; ?><?php if ($message = flash('error')): ?><p class="flash flash-error"><?= e($message) ?></p><?php endif; ?>
