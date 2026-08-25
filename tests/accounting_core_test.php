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
accounting_test_throws(static fn () => accounting_allocate_largest_remainder(1, [['key' => 'x', 'weight' => 0]]), 'Un poids nul doit être refusé.');
accounting_test_throws(static fn () => accounting_integer('55 000', 'Montant', 0), 'Un montant formaté doit être refusé au lieu d’être arrondi.');
accounting_test_same(61000, accounting_historical_cogs_fcfa(2, 30500), 'Le coût figé de la sortie est calculé en FCFA entiers.');
accounting_test_same(61000, accounting_historical_cogs_fcfa(2, 30500), 'Un réassort ultérieur ne modifie pas le snapshot de coût déjà utilisé.');

accounting_test_same('non encaissée', accounting_payment_state(55000, 0, 0), 'Un paiement absent est non encaissé.');
accounting_test_same('partiellement encaissée', accounting_payment_state(55000, 30000, 0), 'Un paiement incomplet est partiel.');
accounting_test_same('encaissée', accounting_payment_state(55000, 55000, 0), 'Un paiement exact est encaissé.');
accounting_test_same('remboursée', accounting_payment_state(55000, 55000, 55000), 'Un paiement entièrement remboursé est identifié.');
accounting_test_same('sur-encaissée à régulariser', accounting_payment_state(55000, 56000, 0), 'Un surplus de paiement doit être signalé.');

$transfer = ['nature' => 'transfer', 'account_id' => 3, 'destination_account_id' => 4, 'amount_fcfa' => 10000];
accounting_test_same(-10000, accounting_operation_effect_fcfa($transfer, 3), 'Le compte source d’un transfert est débité.');
accounting_test_same(10000, accounting_operation_effect_fcfa($transfer, 4), 'Le compte destinataire d’un transfert est crédité.');
accounting_test_same(0, accounting_operation_effect_fcfa($transfer, 5), 'Un tiers n’est pas affecté par le transfert.');

echo "OK — tests du noyau comptable réussis\n";
