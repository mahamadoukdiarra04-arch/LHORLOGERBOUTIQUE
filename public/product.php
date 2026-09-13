<?php
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/catalog.php';
$slug = (string) ($_GET['watch'] ?? '');
$product = product_by_slug($slug);
if (!$product) { http_response_code(404); exit('Montre introuvable.'); }
$gallery = array_values(array_filter(
    (array) ($product['gallery'] ?? []),
    static fn (mixed $image): bool => is_string($image) && trim($image) !== ''
));
if ($gallery === []) {
    $gallery = [(string) ($product['image'] ?? '')];
}
$variantGalleryUrls = [];
foreach ((array) ($product['variants'] ?? []) as $variantName => $variantImage) {
    $variantGallery = array_values(array_filter(
        (array) (($product['variant_galleries'] ?? [])[$variantName] ?? [$variantImage]),
        static fn (mixed $image): bool => is_string($image) && trim($image) !== ''
    ));
    if ($variantGallery === []) {
        $variantGallery = [(string) $variantImage];
    }
    $variantGalleryUrls[$variantName] = array_map(
        static fn (string $image): string => url('/' . ltrim($image, '/')),
        $variantGallery
    );
}
$pageTitle = $product['name'] . ' · L’Horloger';
require APP_ROOT . '/templates/store-header.php';
?>
<main class="container product-page">
  <section class="product-layout">
    <div class="product-gallery">
      <div class="gallery-variant-rail" aria-label="Choisir la couleur">
        <?php foreach ($product['variants'] as $name => $image): ?>
          <button type="button" data-variant-switch="<?= e($name) ?>" aria-label="Choisir la couleur <?= e($name) ?>" aria-pressed="<?= $name === array_key_first($product['variants']) ? 'true' : 'false' ?>">
            <img src="<?= e(url('/' . $image)) ?>" alt="">
          </button>
        <?php endforeach; ?>
      </div>
      <div class="gallery-main">
        <img data-gallery-main src="<?= e(url('/' . $gallery[0])) ?>" alt="<?= e($product['name']) ?>">
      </div>
      <div class="gallery-thumbs" data-gallery-thumbs>
        <?php foreach ($gallery as $index => $image): ?>
          <button type="button" data-gallery-thumb="<?= e(url('/' . $image)) ?>" data-gallery-alt="<?= e($product['name']) ?>, vue <?= $index + 1 ?>" aria-label="Afficher la vue <?= $index + 1 ?> de <?= e($product['name']) ?>">
            <img src="<?= e(url('/' . $image)) ?>" alt="" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="product-info">
      <p class="eyebrow"><?= e($product['sku']) ?> · L’Horloger</p>
      <h1><?= e($product['name']) ?></h1>
      <?php $ratingProduct = $product; $ratingModifier = 'product-rating--hero'; require APP_ROOT . '/templates/product-rating.php'; ?>
      <span class="price"><?= money($product['price']) ?></span>
      <p class="product-description"><?= e($product['description']) ?></p>
      <dl class="product-specs">
        <div><dt>Bracelet</dt><dd><?= e($product['bracelet']) ?></dd></div>
        <div><dt>Finition</dt><dd><?= e($product['finish']) ?></dd></div>
        <div><dt>Diamètre</dt><dd><?= e($product['size']) ?></dd></div>
        <div><dt>Mouvement</dt><dd><?= e($product['movement']) ?></dd></div>
      </dl>
      <form action="<?= e(url('/checkout.php')) ?>" method="get">
        <input type="hidden" name="product" value="<?= e($slug) ?>">
        <div class="variant-list">
          <p class="eyebrow">Coloris</p>
          <?php foreach ($product['variants'] as $name => $image): ?>
            <label class="variant-choice">
              <input type="radio" name="variant" value="<?= e($name) ?>" data-variant-image="<?= e(url('/' . $image)) ?>" data-variant-gallery="<?= e((string) json_encode($variantGalleryUrls[$name] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>" data-variant-alt="<?= e($product['name'] . ' · ' . $name) ?>" <?= $name === array_key_first($product['variants']) ? 'checked' : '' ?>>
              <img src="<?= e(url('/' . $image)) ?>" alt="">
              <span><?= e($name) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="selected-variant">Couleur : <strong data-selected-variant-label><?= e((string) array_key_first($product['variants'])) ?></strong></p>
        <input type="hidden" name="quantity" value="1">
        <button class="button" type="submit">Commander cette montre</button>
      </form>
    </div>
  </section>
  <section class="benefit-row">
    <div><b><?= e($product['waterproof']) ?></b><span>Étanchéité annoncée</span></div>
    <div><b><?= e($product['movement']) ?></b><span>Mouvement</span></div>
    <div><b><?= e($product['size']) ?></b><span>Présence au poignet</span></div>
  </section>
  <section class="section" style="padding-top:15px">
    <div class="section-head"><div><p class="eyebrow">L’essentiel</p><h2><?= e($product['story']) ?></h2></div></div>
    <div class="feature-grid"><?php foreach ($product['features'] as [$title, $copy]): ?><article><h3><?= e($title) ?></h3><p><?= e($copy) ?></p></article><?php endforeach; ?></div>
  </section>
  <section class="section" style="padding-top:15px">
    <p class="eyebrow">Fiche technique</p>
    <div class="product-specs"><?php foreach ($product['specs'] as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?></div>
  </section>
</main>
<?php require APP_ROOT . '/templates/store-footer.php'; ?>
