<?php
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/catalog.php';
$products = catalog();
$pageTitle = 'L’Horloger · Montres sélectionnées à Bamako';
require APP_ROOT . '/templates/store-header.php';
$first = reset($products);
?>
<main>
  <section class="hero"><div class="hero-copy"><p class="eyebrow">L’Horloger · Bamako</p><h1>Le temps vous va si bien.</h1><p>Des montres de caractère, sélectionnées pour accompagner les jours qui comptent. Livraison offerte à Bamako, paiement à la réception.</p><p><a class="button light" href="<?= e(url('/catalog.php')) ?>">Découvrir la collection</a></p></div><img src="<?= e(url('/' . $first['image'])) ?>" alt="<?= e($first['name']) ?> portée au poignet"></section>
  <section class="proofs"><div><strong>3 modèles</strong><span>Une sélection courte, choisie pour son allure.</span></div><div><strong>Livraison offerte</strong><span>Partout à Bamako, sans frais supplémentaires.</span></div><div><strong>Paiement à réception</strong><span>Votre commande est validée avec vous avant la livraison.</span></div></section>
  <section class="section container"><div class="section-head"><div><p class="eyebrow">La collection</p><h2>Choisir une montre qui vous ressemble.</h2></div><p>Finitions, cadrans et matériaux se répondent pour composer une présence juste, au quotidien comme aux grandes occasions.</p></div><div class="product-grid"><?php foreach ($products as $slug => $product): ?><article class="product-card"><a href="<?= e(url('/product.php?watch=' . rawurlencode($slug))) ?>"><img src="<?= e(url('/' . $product['image'])) ?>" alt="<?= e($product['name']) ?>"></a><div class="product-card__copy"><p class="eyebrow"><?= e($product['sku']) ?></p><h3><?= e($product['name']) ?></h3><div class="product-card__meta"><span><?= e($product['short'] ?? $product['bracelet']) ?></span><strong><?= money($product['price']) ?></strong></div><a class="text-link" href="<?= e(url('/product.php?watch=' . rawurlencode($slug))) ?>">Voir la montre</a></div></article><?php endforeach; ?></div></section>
</main>
<?php require APP_ROOT . '/templates/store-footer.php'; ?>
