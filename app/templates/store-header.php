<?php $pageTitle = $pageTitle ?? 'L’Horloger'; ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Montres sélectionnées à Bamako.">
  <title><?= e($pageTitle) ?></title>
  <link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="any">
  <link rel="icon" type="image/png" sizes="48x48" href="<?= e(url('/favicon-48.png')) ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= e(url('/favicon-192.png')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(url('/apple-touch-icon.png')) ?>">
  <meta name="theme-color" content="#11100f">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/brand.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/store-responsive.css')) ?>">
  <!-- Meta Pixel Code -->
  <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '1719750622625367');
    fbq('track', 'PageView');
  </script>
  <!-- End Meta Pixel Code -->
</head>
<body>
  <noscript>
    <img height="1" width="1" style="display:none"
      src="https://www.facebook.com/tr?id=1719750622625367&ev=PageView&noscript=1"
      alt="">
  </noscript>
  <div class="notice">Livraison offerte à Bamako · Paiement à la réception</div>
  <header class="store-header">
    <a class="brand brand-logo" href="<?= e(url('/')) ?>" aria-label="L’Horloger — accueil">
      <img src="<?= e(url('/assets/brand/logo-mark.png')) ?>" alt="">
      <span class="brand-logo__wordmark">L’HORLOGER</span>
    </a>
    <nav>
      <a href="<?= e(url('/catalog.php')) ?>">Montres</a>
      <a href="<?= e(url('/cart.php')) ?>">Panier <span data-cart-count>0</span></a>
    </nav>
  </header>
