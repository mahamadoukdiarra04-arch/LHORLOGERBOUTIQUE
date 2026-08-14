<?php
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/catalog.php';
$products = catalog();
$tone = (string) ($_GET['style'] ?? '');
$pageTitle = 'Collection · L’Horloger';
require APP_ROOT . '/templates/store-header.php';
?>
<main class="container"><header class="catalog-head"><p class="eyebrow">Catalogue</p><h1>La collection L’Horloger.</h1><p>Explorez nos modèles, leurs finitions et leurs détails.</p></header><nav class="catalog-filter"><a class="<?= $tone === '' ? 'active' : '' ?>" href="<?= e(url('/catalog.php')) ?>">Tous les modèles</a><a href="<?= e(url('/catalog.php?style=cuir')) ?>">Bracelet cuir</a><a href="<?= e(url('/catalog.php?style=acier')) ?>">Bracelet acier</a><a href="<?= e(url('/catalog.php?style=mecanique')) ?>">Mécanique</a></nav><section class="product-grid section" style="padding-top:10px"><?php foreach ($products as $slug => $product): $match = $tone === '' || ($tone === 'cuir' && str_contains(strtolower($product['bracelet']), 'cuir')) || ($tone === 'acier' && str_contains(strtolower($product['bracelet']), 'acier')) || ($tone === 'mecanique' && str_contains(strtolower($product['movement']), 'mécanique')); if (!$match) continue; ?><article class="product-card"><a href="<?= e(url('/product.php?watch=' . rawurlencode($slug))) ?>"><img src="<?= e(url('/' . $product['image'])) ?>" alt="<?= e($product['name']) ?>"></a><div class="product-card__copy"><p class="eyebrow"><?= e($product['sku']) ?></p><h3><?= e($product['name']) ?></h3><div class="product-card__meta"><span><?= e($product['finish']) ?> · <?= e($product['size']) ?></span><strong><?= money($product['price']) ?></strong></div><a class="text-link" href="<?= e(url('/product.php?watch=' . rawurlencode($slug))) ?>">Découvrir</a></div></article><?php endforeach; ?></section></main>
<?php require APP_ROOT . '/templates/store-footer.php'; ?>
