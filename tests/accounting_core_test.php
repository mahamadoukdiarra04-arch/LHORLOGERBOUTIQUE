<?php
declare(strict_types=1);

require __DIR__ . '/../app/accounting.php';

function accounting_test_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nAttendu : ' . var_export($expected, true) . '\nObtenu : ' . var_export($actual, true));
    }
}

function accounting_test_true(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function accounting_test_throws(callable $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException|InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$deliveryWeights = [
    ['key' => 'nocturne', 'weight' => 52000],
    ['key' => 'azur', 'weight' => 62000],
];
accounting_test_same(
    ['nocturne' => 20070, 'azur' => 23930],
    accounting_allocate_largest_remainder(44000, $deliveryWeights),
    'La première répartition multi-comptes doit garder chaque FCFA.'
);
accounting_test_same(
    ['nocturne' => 31930, 'azur' => 38070],
    accounting_allocate_largest_remainder(70000, $deliveryWeights),
    'La seconde répartition multi-comptes doit garder chaque FCFA.'
);

$tie = accounting_allocate_largest_remainder(2, [
    ['key' => 'première', 'weight' => 1],
    ['key' => 'deuxième', 'weight' => 1],
    ['key' => 'troisième', 'weight' => 1],
]);
accounting_test_same(['première' => 1, 'deuxième' => 1, 'troisième' => 0], $tie, 'Les égalités de reste doivent rester déterministes.');
accounting_test_same(['a' => 0, 'b' => 0], accounting_allocate_largest_remainder(0, [
    ['key' => 'a', 'weight' => 1], ['key' => 'b', 'weight' => 5],
]), 'Une répartition nulle ne crée aucun FCFA.');

$large = accounting_allocate_largest_remainder(4000000000000000000, [
    ['key' => 'a', 'weight' => 2000000000000000000],
    ['key' => 'b', 'weight' => 2000000000000000000],
]);
accounting_test_same(4000000000000000000, array_sum($large), 'Les grands montants restent exacts sans calcul flottant.');
accounting_test_same(2000000000000000000, $large['a'], 'La répartition exacte des grands montants est conservée.');

accounting_test_same('1.250000', accounting_quantity_equivalent('1.25'), 'La quantité analytique conserve six décimales.');
accounting_test_same('2026-08-26 14:30:00', accounting_effective_at('2026-08-26T14:30'), 'Une date issue d’un champ datetime-local est acceptée.');
accounting_test_same('2026-08-26 14:30:00', accounting_effective_at('2026-08-26 14:30'), 'Une date comptable avec espace reste acceptée.');
accounting_test_throws(static fn () => accounting_allocate_largest_remainder(1, [['key' => 'x', 'weight' => 0]]), 'Un poids nul doit être refusé.');
accounting_test_throws(static fn () => accounting_integer('55 000', 'Montant', 0), 'Un montant formaté doit être refusé au lieu d’être arrondi.');
accounting_test_same(61000, accounting_historical_cogs_fcfa(2, 30500), 'Le coût figé de la sortie est calculé en FCFA entiers.');
accounting_test_same(61000, accounting_historical_cogs_fcfa(2, 30500), 'Un réassort ultérieur ne modifie pas le snapshot de coût déjà utilisé.');

accounting_test_same('non encaissée', accounting_payment_state(55000, 0, 0), 'Un paiement absent est non encaissé.');
accounting_test_same('partiellement encaissée', accounting_payment_state(55000, 30000, 0), 'Un paiement incomplet est partiel.');
accounting_test_same('encaissée', accounting_payment_state(55000, 55000, 0), 'Un paiement exact est encaissé.');
accounting_test_same('remboursée', accounting_payment_state(55000, 55000, 55000), 'Un paiement entièrement remboursé est identifié.');
accounting_test_same('sur-encaissée à régulariser', accounting_payment_state(55000, 56000, 0), 'Un surplus de paiement doit être signalé.');

$deliveryLines = [
    ['id' => 11, 'quantity' => 1, 'unit_price_fcfa' => 52000],
    ['id' => 12, 'quantity' => 1, 'unit_price_fcfa' => 62000],
];
$deliveryPayments = [
    ['account_id' => 1, 'amount_fcfa' => 44000],
    ['account_id' => 2, 'amount_fcfa' => 70000],
];
accounting_test_same([
    0 => [11 => 20070, 12 => 23930],
    1 => [11 => 31930, 12 => 38070],
], accounting_allocate_delivery_payments($deliveryLines, $deliveryPayments), 'Les encaissements de livraison ventilent toutes les lignes sans perte.');
accounting_test_same([
    0 => [1 => 1, 2 => 0, 3 => 0],
    1 => [2 => 1, 3 => 1],
], accounting_allocate_delivery_payments([
    ['id' => 1, 'quantity' => 1, 'unit_price_fcfa' => 1],
    ['id' => 2, 'quantity' => 1, 'unit_price_fcfa' => 1],
    ['id' => 3, 'quantity' => 1, 'unit_price_fcfa' => 1],
], [
    ['account_id' => 1, 'amount_fcfa' => 1],
    ['account_id' => 2, 'amount_fcfa' => 2],
]), 'Les paiements successifs respectent le reliquat de chaque ligne.');
accounting_test_throws(static fn () => accounting_allocate_delivery_payments($deliveryLines, [['account_id' => 1, 'amount_fcfa' => 114001]]), 'Un encaissement supérieur au total doit être refusé.');

accounting_test_same([
    0 => [1 => 20070, 2 => 23930],
    1 => [1 => 31930, 2 => 38070],
], accounting_allocate_remaining_payments([1 => 52000, 2 => 62000], $deliveryPayments), 'La régularisation respecte le reliquat de chaque ligne sans perdre de FCFA.');
accounting_test_same([
    0 => [1 => 1, 2 => 0, 3 => 0],
    1 => [2 => 1, 3 => 1],
], accounting_allocate_remaining_payments([1 => 1, 2 => 1, 3 => 1], [
    ['account_id' => 1, 'amount_fcfa' => 1],
    ['account_id' => 2, 'amount_fcfa' => 2],
]), 'La régularisation séquentielle ne crédite jamais deux fois une ligne.');
accounting_test_throws(static fn () => accounting_allocate_remaining_payments([1 => 1000], [['account_id' => 1, 'amount_fcfa' => 1001]]), 'Une régularisation supérieure au reliquat doit être refusée.');
accounting_test_same(25000, accounting_signed_difference(55000, 30000, 'Écart'), 'Un écart de rapprochement positif est calculé en entier.');
accounting_test_same(-25000, accounting_signed_difference(30000, 55000, 'Écart'), 'Un écart de rapprochement négatif est calculé en entier.');
accounting_test_throws(static fn () => accounting_normalize_direct_sale_items([]), 'Une vente directe vide doit être refusée.');
$deliveryExpenseCategory = array_values(array_filter(
    accounting_system_categories(),
    static fn (array $category): bool => $category[0] === 'delivery_cost',
));
accounting_test_same(
    [['delivery_cost', 'Livraison', 'disbursement', 'direct_expense', 'product', 65]],
    $deliveryExpenseCategory,
    'La livraison doit rester une charge directe affectable à un produit.'
);
accounting_test_throws(static fn () => accounting_normalize_direct_sale_items([[
    'product_id' => '1', 'variant_id' => '7', 'quantity' => '1', 'unit_price_fcfa' => '1000', 'discount_fcfa' => '1000',
]]), 'Une remise ne peut pas annuler intégralement une montre.');
accounting_test_throws(static fn () => accounting_normalize_direct_sale_items([[
    'product_id' => '1', 'quantity' => '1', 'unit_price_fcfa' => '25000', 'discount_fcfa' => '0',
]]), 'Une vente directe sans coloris du catalogue doit être refusée.');
$directSaleItems = accounting_normalize_direct_sale_items([[
    'product_id' => '1', 'variant_id' => '7', 'quantity' => '2', 'unit_price_fcfa' => '25000', 'discount_fcfa' => '1000',
]]);
accounting_test_same(7, $directSaleItems[0]['variant_id'], 'Le coloris de la vente directe est conservé par son identifiant de stock.');
accounting_test_same(49000, $directSaleItems[0]['line_total_fcfa'], 'Le total de la ligne directe reste exact après sélection du coloris.');

$transfer = ['nature' => 'transfer', 'account_id' => 3, 'destination_account_id' => 4, 'amount_fcfa' => 10000];
accounting_test_same(-10000, accounting_operation_effect_fcfa($transfer, 3), 'Le compte source d’un transfert est débité.');
accounting_test_same(10000, accounting_operation_effect_fcfa($transfer, 4), 'Le compte destinataire d’un transfert est crédité.');
accounting_test_same(0, accounting_operation_effect_fcfa($transfer, 5), 'Un tiers n’est pas affecté par le transfert.');

echo "OK — tests du noyau comptable réussis\n";
