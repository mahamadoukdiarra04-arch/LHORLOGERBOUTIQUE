<?php $pageTitle = $pageTitle ?? 'L’Horloger'; ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Montres sélectionnées à Bamako."><title><?= e($pageTitle) ?></title><link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>"></head><body>
<div class="notice">Livraison offerte à Bamako · Paiement à la réception</div>
<header class="store-header"><a class="brand" href="<?= e(url('/')) ?>">L’HORLOGER</a><nav><a href="<?= e(url('/catalog.php')) ?>">Montres</a><a href="<?= e(url('/cart.php')) ?>">Panier <span data-cart-count>0</span></a></nav></header>
