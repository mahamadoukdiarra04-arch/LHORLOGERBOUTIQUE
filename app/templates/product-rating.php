<?php
$ratingProduct = is_array($ratingProduct ?? null) ? $ratingProduct : [];
$ratingValue = max(0.0, min(5.0, (float) ($ratingProduct['rating'] ?? 0)));
$ratingPercent = (string) round(($ratingValue / 5) * 100, 2);
$ratingLabel = rtrim(rtrim(number_format($ratingValue, 1, ',', ''), '0'), ',');
$reviewCount = max(0, (int) ($ratingProduct['review_count'] ?? 0));
$ratingClass = trim('product-rating ' . (string) ($ratingModifier ?? ''));
?>
<div class="<?= e($ratingClass) ?>" aria-label="Note <?= e($ratingLabel) ?> sur 5 d’après <?= e(number_format($reviewCount, 0, ',', ' ')) ?> avis">
  <span class="rating-stars" style="--rating-percent: <?= e($ratingPercent) ?>%" aria-hidden="true">★★★★★</span>
  <span class="rating-count">(<?= e(number_format($reviewCount, 0, ',', ' ')) ?> avis)</span>
</div>
