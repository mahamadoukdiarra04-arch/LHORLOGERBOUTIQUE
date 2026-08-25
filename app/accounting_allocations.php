<?php
declare(strict_types=1);

/**
 * Returns quotient and remainder for ($left * $right) / $modulus without a
 * floating point operation. The binary accumulation keeps every intermediary
 * below PHP_INT_MAX; this matters when a payment is distributed over large
 * order totals.
 *
 * @return array{0:int,1:int}
 */
function accounting_product_divmod(int $left, int $right, int $modulus): array {
    if ($left < 0 || $right < 0 || $modulus < 1) throw new InvalidArgumentException('Répartition entière invalide.');
    if ($left === 0 || $right === 0) return [0, 0];

    $whole = intdiv($left, $modulus);
    $remainder = $left % $modulus;
    $quotient = 0;
    $resultRemainder = 0;
    $factor = $right;

    while ($factor > 0) {
        if (($factor % 2) === 1) {
            $carry = $resultRemainder >= $modulus - $remainder ? 1 : 0;
            $resultRemainder = $carry === 1
                ? $resultRemainder - ($modulus - $remainder)
                : $resultRemainder + $remainder;
            $quotient += $whole + $carry;
        }
        $factor = intdiv($factor, 2);
        if ($factor === 0) break;

        $carry = $remainder >= $modulus - $remainder ? 1 : 0;
        $remainder = $carry === 1
            ? $remainder - ($modulus - $remainder)
            : $remainder + $remainder;
        $whole = ($whole * 2) + $carry;
    }

    return [$quotient, $resultRemainder];
}

/**
 * Allocates a positive integer over weighted entries with the largest
 * remainder method. Ties remain in the submitted order, which keeps a retry
 * deterministic. Each entry is ['key' => scalar, 'weight' => integer].
 *
 * @return array<string|int,int>
 */
function accounting_allocate_largest_remainder(int $amount, array $entries): array {
    if ($amount < 0) throw new RuntimeException('Le montant à répartir ne peut pas être négatif.');
    if ($entries === []) throw new RuntimeException('Aucune ligne de répartition n’a été fournie.');

    $normalized = [];
    $weightTotal = 0;
    foreach (array_values($entries) as $index => $entry) {
        if (!is_array($entry) || !array_key_exists('key', $entry) || !array_key_exists('weight', $entry)) {
            throw new RuntimeException('Une ligne de répartition est invalide.');
        }
        $key = $entry['key'];
        if (!is_int($key) && !is_string($key)) throw new RuntimeException('La clé d’une ligne de répartition est invalide.');
        $weight = accounting_integer($entry['weight'], 'Le poids de répartition', 0);
        if ($weight === 0) throw new RuntimeException('Chaque poids de répartition doit être strictement positif.');
        if ($weight > PHP_INT_MAX - $weightTotal) throw new RuntimeException('Le total de répartition est trop élevé.');
        $weightTotal += $weight;
        $normalized[] = ['key' => $key, 'weight' => $weight, 'index' => $index, 'floor' => 0, 'remainder' => 0];
    }
    if ($weightTotal < 1) throw new RuntimeException('Le total de répartition est nul.');

    $allocated = 0;
    foreach ($normalized as &$entry) {
        [$floor, $remainder] = accounting_product_divmod($amount, $entry['weight'], $weightTotal);
        $entry['floor'] = $floor;
        $entry['remainder'] = $remainder;
        if ($floor > PHP_INT_MAX - $allocated) throw new RuntimeException('La répartition est trop élevée.');
        $allocated += $floor;
    }
    unset($entry);

    $remaining = $amount - $allocated;
    usort($normalized, static function (array $left, array $right): int {
        $byRemainder = $right['remainder'] <=> $left['remainder'];
        return $byRemainder !== 0 ? $byRemainder : ($left['index'] <=> $right['index']);
    });
    for ($index = 0; $index < $remaining; $index++) $normalized[$index]['floor']++;

    usort($normalized, static fn (array $left, array $right): int => $left['index'] <=> $right['index']);
    $result = [];
    foreach ($normalized as $entry) {
        if (array_key_exists($entry['key'], $result)) throw new RuntimeException('Chaque clé de répartition doit être unique.');
        $result[$entry['key']] = $entry['floor'];
    }
    return $result;
}

function accounting_quantity_equivalent(mixed $value): string {
    $quantity = trim((string) $value);
    if ($quantity === '') return '0.000000';
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/', $quantity)) {
        throw new RuntimeException('La quantité équivalente est invalide.');
    }
    [$whole, $decimal] = array_pad(explode('.', $quantity, 2), 2, '');
    if (strlen($whole) > 10) throw new RuntimeException('La quantité équivalente est trop élevée.');
    return $whole . '.' . str_pad($decimal, 6, '0');
}

function accounting_allocation_scope_is_valid(array $category, string $scope, ?int $productId): bool {
    if ($scope !== $category['default_scope']) return false;
    if ($scope === 'product') return $productId !== null && $productId > 0;
    return $productId === null;
}

function accounting_assert_product_exists(PDO $pdo, ?int $productId): void {
    if ($productId === null) return;
    if ($productId < 1) throw new RuntimeException('Produit invalide.');
    $statement = $pdo->prepare('SELECT id FROM products WHERE id = ?');
    $statement->execute([$productId]);
    if (!$statement->fetchColumn()) throw new RuntimeException('Produit introuvable.');
}

function accounting_assert_source_line_exists(PDO $pdo, ?int $orderId, ?int $directSaleItemId): void {
    if ($orderId !== null && $directSaleItemId !== null) {
        throw new RuntimeException('Une ventilation ne peut pas viser à la fois une commande et une vente directe.');
    }
    if ($orderId !== null) {
        $statement = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
        $statement->execute([$orderId]);
        if (!$statement->fetchColumn()) throw new RuntimeException('Ligne de commande introuvable.');
    }
    if ($directSaleItemId !== null) {
        $statement = $pdo->prepare('SELECT id FROM direct_sale_items WHERE id = ?');
        $statement->execute([$directSaleItemId]);
        if (!$statement->fetchColumn()) throw new RuntimeException('Ligne de vente directe introuvable.');
    }
}

/**
 * Replaces only the allocation lines of a draft. The caller may be inside a
 * broader transaction; confirmed operations never pass this boundary.
 */
function accounting_replace_draft_allocations(PDO $pdo, int $operationId, array $entries, ?int $userId = null): array {
    return accounting_with_transaction($pdo, function () use ($pdo, $operationId, $entries, $userId): array {
        $operationStatement = $pdo->prepare('SELECT * FROM accounting_operations WHERE id = ? FOR UPDATE');
        $operationStatement->execute([$operationId]);
        $operation = $operationStatement->fetch();
        if (!$operation) throw new RuntimeException('Opération introuvable.');
        if ($operation['status'] !== 'draft') throw new RuntimeException('Les ventilations d’une opération confirmée ne peuvent pas être modifiées.');
        if ($operation['nature'] === 'transfer') {
            if ($entries !== []) throw new RuntimeException('Un transfert ne reçoit pas de ventilation analytique.');
            return [];
        }
        if ($entries === []) throw new RuntimeException('Ajoutez au moins une ventilation avant confirmation.');

        $validated = [];
        $total = 0;
        foreach (array_values($entries) as $entry) {
            if (!is_array($entry)) throw new RuntimeException('Une ventilation est invalide.');
            $categoryId = accounting_integer($entry['category_id'] ?? null, 'La catégorie', 1);
            $category = accounting_require_active_category($pdo, $categoryId, true);
            if ($operation['category_id'] !== null && (int) $operation['category_id'] !== $categoryId) {
                throw new RuntimeException('Chaque ventilation doit utiliser la catégorie de l’opération.');
            }
            $scope = (string) ($entry['scope'] ?? $category['default_scope']);
            $productId = array_key_exists('product_id', $entry) && $entry['product_id'] !== '' ? accounting_integer($entry['product_id'], 'Le produit', 1) : null;
            if (!accounting_allocation_scope_is_valid($category, $scope, $productId)) {
                throw new RuntimeException('La portée de cette ventilation ne correspond pas à sa catégorie.');
            }
            accounting_assert_product_exists($pdo, $productId);
            $orderId = array_key_exists('order_id', $entry) && $entry['order_id'] !== '' ? accounting_integer($entry['order_id'], 'La ligne de commande', 1) : null;
            $directSaleItemId = array_key_exists('direct_sale_item_id', $entry) && $entry['direct_sale_item_id'] !== '' ? accounting_integer($entry['direct_sale_item_id'], 'La ligne de vente directe', 1) : null;
            accounting_assert_source_line_exists($pdo, $orderId, $directSaleItemId);
            $amount = accounting_integer($entry['amount_fcfa'] ?? null, 'Le montant ventilé', 1);
            if ($amount > PHP_INT_MAX - $total) throw new RuntimeException('Le total des ventilations est trop élevé.');
            $total += $amount;
            $validated[] = [
                'category_id' => $categoryId,
                'treatment' => $category['treatment'],
                'scope' => $scope,
                'product_id' => $productId,
                'order_id' => $orderId,
                'direct_sale_item_id' => $directSaleItemId,
                'amount_fcfa' => $amount,
                'effect_sign' => 1,
                'quantity_equivalent' => accounting_quantity_equivalent($entry['quantity_equivalent'] ?? ''),
                'unit_cost_snapshot_fcfa' => accounting_integer($entry['unit_cost_snapshot_fcfa'] ?? 0, 'Le coût unitaire historique', 0),
                'cogs_amount_fcfa' => accounting_integer($entry['cogs_amount_fcfa'] ?? 0, 'Le coût des marchandises vendues', 0),
            ];
        }
        if ($total !== (int) $operation['amount_fcfa']) {
            throw new RuntimeException('La somme des ventilations doit être exactement égale au montant de l’opération.');
        }

        $previous = $pdo->prepare('SELECT * FROM accounting_allocations WHERE operation_id = ? ORDER BY id');
        $previous->execute([$operationId]);
        $before = $previous->fetchAll();
        $delete = $pdo->prepare('DELETE FROM accounting_allocations WHERE operation_id = ?');
        $delete->execute([$operationId]);
        $insert = $pdo->prepare(
            'INSERT INTO accounting_allocations
             (operation_id, category_id, treatment, scope, product_id, order_id, direct_sale_item_id, amount_fcfa, effect_sign, quantity_equivalent, unit_cost_snapshot_fcfa, cogs_amount_fcfa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($validated as $allocation) {
            $insert->execute([
                $operationId,
                $allocation['category_id'],
                $allocation['treatment'],
                $allocation['scope'],
                $allocation['product_id'],
                $allocation['order_id'],
                $allocation['direct_sale_item_id'],
                $allocation['amount_fcfa'],
                $allocation['effect_sign'],
                $allocation['quantity_equivalent'],
                $allocation['unit_cost_snapshot_fcfa'],
                $allocation['cogs_amount_fcfa'],
            ]);
        }
        accounting_audit($pdo, 'replace_allocations', 'operation', $operationId, ['allocations' => $before], ['allocations' => $validated], $userId);
        return $validated;
    });
}

function accounting_operation_allocation_total(PDO $pdo, int $operationId): int {
    $statement = $pdo->prepare('SELECT COALESCE(SUM(amount_fcfa), 0) FROM accounting_allocations WHERE operation_id = ?');
    $statement->execute([$operationId]);
    return accounting_integer((string) $statement->fetchColumn(), 'Le total des ventilations', 0);
}
