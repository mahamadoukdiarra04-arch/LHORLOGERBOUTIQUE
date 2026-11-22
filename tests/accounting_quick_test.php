<?php
declare(strict_types=1);
require __DIR__ . '/../app/accounting.php';
function quick_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function quick_throws(callable $callback): void {
    try { $callback(); } catch (RuntimeException|InvalidArgumentException $error) { return; }
    throw new RuntimeException('Une saisie invalide a été acceptée.');
}
quick_assert(accounting_quick_ids(['2', '1', '2']) === [1, 2], 'Déduplication et ordre stable des IDs.');
foreach ([[], ['-1'], ['1.2'], [[1]], array_fill(0, 51, '1')] as $bad) quick_throws(fn() => accounting_quick_ids($bad));
$key = accounting_quick_child_key('8e10a538-a557-4a0c-8201-7aad5220eacd', 'HOR-TEST');
quick_assert(accounting_uuid($key) === $key, 'UUID enfant stable et valide.');
quick_assert($key !== accounting_quick_child_key('8e10a538-a557-4a0c-8201-7aad5220eacd', 'HOR-OTHER'), 'Une clé par référence.');
$line = ['id' => 1, 'status' => 'Confirmée', 'stock_processed' => 0, 'unit_price_fcfa' => 25000, 'quantity' => 1, 'created_at' => '2026-11-20 10:00:00'];
$changed = $line; $changed['unit_price_fcfa'] = 20000;
quick_assert(accounting_quick_fingerprint([$line]) !== accounting_quick_fingerprint([$changed]), 'Une remise concurrente invalide le récapitulatif.');
accounting_quick_validate_lines([$line], 'deliver');
foreach (['Annulée', 'Livrée'] as $status) { $bad = $line; $bad['status'] = $status; quick_throws(fn() => accounting_quick_validate_lines([$bad], 'deliver')); }
$bad = $line; $bad['stock_processed'] = 1; quick_throws(fn() => accounting_quick_validate_lines([$bad], 'confirm'));
quick_throws(fn() => accounting_quick_add(PHP_INT_MAX, 1));
$plan = ['action' => 'deliver', 'account_id' => 1, 'channel' => 'Meta', 'entries' => [['order_id' => 1, 'order_ref' => 'HOR-TEST', 'fingerprint' => 'test', 'amount_fcfa' => 25000, 'effective_at' => '2026-11-21 12:00:00', 'lines' => [$line]]]];
quick_throws(fn() => accounting_quick_apply_dates($plan, [1 => '2026-11-19T12:00']));
quick_throws(fn() => accounting_quick_apply_dates($plan, []));
quick_assert(strlen(accounting_quick_plan_hash($plan)) === 64, 'Empreinte complète du lot.');
$orders = file_get_contents(__DIR__ . '/../public/admin/orders.php');
quick_assert(!str_contains($orders, 'LIMIT 150') && str_contains($orders, 'OFFSET'), 'Pagination SQL, pas une limite silencieuse.');
quick_assert(str_contains($orders, 'filters[') && str_contains($orders, 'Pages de l’historique'), 'Les filtres sont conservés.');
quick_assert(!str_contains($orders, 'archive_order') && !str_contains($orders, 'Commandes masquées') && !str_contains($orders, 'order_archives'), 'Le masquage est retiré de la gestion.');
echo "OK — saisie rapide : sélection, statuts, dates, empreintes, pagination\n";
