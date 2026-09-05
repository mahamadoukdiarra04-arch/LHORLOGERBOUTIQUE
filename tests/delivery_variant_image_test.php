<?php
declare(strict_types=1);

require __DIR__ . '/../app/catalog.php';
require __DIR__ . '/../app/delivery-pdf.php';

function delivery_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$catalog = catalog();
delivery_test_assert(
    catalog_variant_image($catalog, 'nocturne-chrono', 'Noir & brun') === 'products/variants/nocturne-noir-brun.jpg',
    'La Nocturne Noir & brun doit utiliser sa photo exacte.'
);
delivery_test_assert(
    catalog_variant_image($catalog, 'nocturne-chrono', '  noir et brun  ') === 'products/variants/nocturne-noir-brun.jpg',
    'Les anciens libellés équivalents doivent retrouver la bonne photo.'
);
delivery_test_assert(
    catalog_variant_image($catalog, 'azur-squelette', 'Noir squelette') === 'products/variants/azur-noir-squelette.jpg',
    'L’Azur Noir squelette doit utiliser sa photo exacte.'
);
delivery_test_assert(
    catalog_variant_image($catalog, 'azur-squelette', 'Coloris inconnu') === 'products/azur-squelette.jpg',
    'Un coloris inconnu doit conserver un repli sûr sur le modèle.'
);

$orders = [
    [
        'order_ref' => 'HOR-TEST-001',
        'customer' => 'Client Nocturne',
        'phone' => '+223 70 00 00 01',
        'district' => 'Bamako',
        'product' => 'Nocturne Chrono',
        'variant' => 'Noir & brun',
        'quantity' => 1,
        'amount' => '25 000 FCFA',
        'image' => catalog_variant_image($catalog, 'nocturne-chrono', 'Noir & brun'),
    ],
    [
        'order_ref' => 'HOR-TEST-002',
        'customer' => 'Client Azur',
        'phone' => '+223 70 00 00 02',
        'district' => 'Bamako',
        'product' => 'Azur Squelette',
        'variant' => 'Noir squelette',
        'quantity' => 1,
        'amount' => '20 000 FCFA',
        'image' => catalog_variant_image($catalog, 'azur-squelette', 'Noir squelette'),
    ],
];
$pdf = delivery_sheet_pdf($orders, '2026-09-05', __DIR__ . '/../public');
delivery_test_assert(str_starts_with($pdf, '%PDF-1.4'), 'Le bordereau doit rester un PDF valide.');
delivery_test_assert(substr_count($pdf, '/Subtype /Image') === 2, 'Chaque coloris distinct doit être embarqué dans le PDF.');
if (!empty($argv[1])) file_put_contents((string) $argv[1], $pdf);

echo "delivery_variant_image_test: OK\n";
