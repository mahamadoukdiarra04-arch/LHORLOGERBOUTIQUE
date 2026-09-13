<?php
declare(strict_types=1);

require __DIR__ . '/../app/catalog.php';

$catalog = catalog();
$expected = [
    'nocturne-chrono' => [5.0, 1876],
    'azur-squelette' => [4.5, 1248],
    'eclipse-lunaire' => [5.0, 782],
];

foreach ($expected as $slug => [$rating, $reviewCount]) {
    if ((float) ($catalog[$slug]['rating'] ?? 0) !== $rating) {
        throw new RuntimeException("Note incorrecte pour {$slug}.");
    }
    if ((int) ($catalog[$slug]['review_count'] ?? 0) !== $reviewCount) {
        throw new RuntimeException("Nombre d’avis incorrect pour {$slug}.");
    }
    if ($reviewCount < 750 || $reviewCount > 2000) {
        throw new RuntimeException("Le nombre d’avis de {$slug} doit rester compris entre 750 et 2 000.");
    }
}

echo "catalog_rating_test: OK\n";
