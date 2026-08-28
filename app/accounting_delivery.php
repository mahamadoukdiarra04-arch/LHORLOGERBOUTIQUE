<?php
declare(strict_types=1);

function accounting_delivery_preview(PDO $pdo, int $orderId): array {
    ensure_accounting_schema();
    if ($orderId < 1) throw new RuntimeException('Commande invalide.');
    $selected = $pdo->prepare('SELECT id, order_ref FROM orders WHERE id = ?');
    $selected->execute([$orderId]);
    $order = $selected->fetch();
    if (!$order) throw new RuntimeException('Commande introuvable.');
    $lines = accounting_delivery_order_lines($pdo, (string) $order['order_ref']);
    return [
        'order_ref' => (string) $order['order_ref'],
        'selected_order_id' => $orderId,
        'lines' => $lines,
        'total_fcfa' => accounting_delivery_order_total($lines),
        'accounts' => accounting_list_accounts($pdo, true),
    ];
}

function accounting_delivery_order_lines(PDO $pdo, string $orderRef, bool $lock = false): array {
    $statement = $pdo->prepare(
        'SELECT * FROM orders WHERE order_ref = ? ORDER BY id ASC' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$orderRef]);
    $lines = $statement->fetchAll();
    if ($lines === []) throw new RuntimeException('Référence de commande introuvable.');
    return $lines;
}

function accounting_delivery_order_total(array $lines): int {
    $total = 0;
    foreach ($lines as $line) {
        $quantity = accounting_integer($line['quantity'] ?? null, 'La quantité commandée', 1);
        $unitPrice = accounting_integer($line['unit_price_fcfa'] ?? null, 'Le prix de vente', 1);
        $lineTotal = accounting_historical_cogs_fcfa($quantity, $unitPrice);
        if ($lineTotal > PHP_INT_MAX - $total) throw new RuntimeException('Le total de la commande est trop élevé.');
        $total += $lineTotal;
    }
    return $total;
}

function accounting_normalize_delivery_payments(PDO $pdo, mixed $input): array {
    if (!is_array($input)) throw new RuntimeException('Ajoutez au moins un encaissement.');
    $payments = [];
    foreach (array_values($input) as $payment) {
        if (!is_array($payment)) throw new RuntimeException('Une ligne d’encaissement est invalide.');
        $accountRaw = trim((string) ($payment['account_id'] ?? ''));
        $amountRaw = trim((string) ($payment['amount_fcfa'] ?? ''));
        $reference = accounting_optional_text($payment['payment_reference'] ?? null, 'La référence de paiement', 120);
        if ($accountRaw === '' && $amountRaw === '' && $reference === null) continue;
        if ($accountRaw === '' || $amountRaw === '') throw new RuntimeException('Chaque encaissement doit avoir un compte et un montant.');
        $accountId = accounting_integer($accountRaw, 'Le compte d’encaissement', 1);
        $amount = accounting_integer($amountRaw, 'Le montant encaissé', 1);
        if (isset($payments[$accountId])) throw new RuntimeException('Fusionnez les montants versés sur le même compte avant de confirmer.');
        $payments[$accountId] = ['account_id' => $accountId, 'amount_fcfa' => $amount, 'payment_reference' => $reference];
    }
    if ($payments === []) throw new RuntimeException('Ajoutez au moins un encaissement.');
    $accountIds = array_keys($payments);
    sort($accountIds, SORT_NUMERIC);
    foreach ($accountIds as $accountId) accounting_require_active_account($pdo, $accountId, true);
    return array_values($payments);
}

/**
 * Each payment is allocated against the remaining unpaid lines. This prevents
 * rounding from over-crediting the first product when an order uses several
 * payment accounts.
 */
function accounting_allocate_delivery_payments(array $lines, array $payments): array {
    $remaining = [];
    foreach ($lines as $line) {
        $id = accounting_integer($line['id'] ?? null, 'La ligne de commande', 1);
        if (isset($remaining[$id])) throw new RuntimeException('Une ligne de commande est dupliquée.');
        $quantity = accounting_integer($line['quantity'] ?? null, 'La quantité commandée', 1);
        $unitPrice = accounting_integer($line['unit_price_fcfa'] ?? null, 'Le prix de vente', 1);
        $remaining[$id] = accounting_historical_cogs_fcfa($quantity, $unitPrice);
    }
    $allocations = [];
    foreach (array_values($payments) as $paymentIndex => $payment) {
        $amount = accounting_integer($payment['amount_fcfa'] ?? null, 'Le montant encaissé', 1);
        $outstanding = 0;
        foreach ($remaining as $lineRemaining) {
            if ($lineRemaining > PHP_INT_MAX - $outstanding) throw new RuntimeException('Le reliquat de commande est trop élevé.');
            $outstanding += $lineRemaining;
        }
        if ($amount > $outstanding) throw new RuntimeException('Les encaissements dépassent le total de la commande.');
        $weights = [];
        foreach ($remaining as $orderId => $lineRemaining) {
            if ($lineRemaining > 0) $weights[] = ['key' => $orderId, 'weight' => $lineRemaining];
        }
        $paymentAllocation = accounting_allocate_largest_remainder($amount, $weights);
        foreach ($paymentAllocation as $orderId => $allocated) $remaining[$orderId] -= $allocated;
        $allocations[$paymentIndex] = $paymentAllocation;
    }
    return $allocations;
}

function accounting_delivery_effective_at(mixed $value): string {
    $effectiveAt = accounting_effective_at($value, 'La date de livraison');
    $now = new DateTimeImmutable('now', accounting_bamako_timezone());
    $date = new DateTimeImmutable($effectiveAt, accounting_bamako_timezone());
    if ($date > $now->modify('+1 day') || $date < $now->modify('-365 days')) {
        throw new RuntimeException('La date de livraison doit rester dans une période raisonnable.');
    }
    return $effectiveAt;
}

function accounting_delivery_sale_category(PDO $pdo): array {
    $statement = $pdo->query('SELECT * FROM accounting_categories WHERE code = "sale_product" FOR UPDATE');
    $category = $statement->fetch();
    if (!$category || !(bool) $category['is_active']) throw new RuntimeException('La catégorie Vente de montre doit être active pour livrer une commande.');
    return $category;
}

function accounting_add_delivery_cogs_allocations(PDO $pdo, int $operationId, array $lines, array $unitCostsByOrder, int $categoryId): void {
    $insert = $pdo->prepare(
        'INSERT INTO accounting_allocations
         (operation_id, category_id, treatment, scope, product_id, order_id, amount_fcfa, effect_sign, quantity_equivalent, unit_cost_snapshot_fcfa, cogs_amount_fcfa)
         VALUES (?, ?, "product_revenue", "product", ?, ?, 0, 1, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $productId = accounting_integer($line['product_id'], 'Le produit de commande', 1);
        $quantity = accounting_integer($line['quantity'], 'La quantité commandée', 1);
        $unitCost = $unitCostsByOrder[(int) $line['id']] ?? null;
        if ($unitCost === null) throw new RuntimeException('Le coût historique du produit est introuvable.');
        $insert->execute([
            $operationId,
            $categoryId,
            $productId,
            (int) $line['id'],
            $quantity . '.000000',
            $unitCost,
            accounting_historical_cogs_fcfa($quantity, $unitCost),
        ]);
    }
}

function accounting_confirm_delivery(PDO $pdo, int $orderId, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    ensure_closer_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $effectiveAt = accounting_delivery_effective_at($data['effective_at'] ?? null);
    $exceptionMode = accounting_flag($data['exception_mode'] ?? '0', 'Le mode d’exception');
    $exceptionReason = accounting_optional_text($data['exception_reason'] ?? null, 'Le motif de l’exception', 500);

    return accounting_with_transaction($pdo, function () use ($pdo, $orderId, $data, $userId, $idempotencyKey, $effectiveAt, $exceptionMode, $exceptionReason): array {
        $selected = $pdo->prepare('SELECT id, order_ref FROM orders WHERE id = ?');
        $selected->execute([$orderId]);
        $source = $selected->fetch();
        if (!$source) throw new RuntimeException('Commande introuvable.');
        $orderRef = (string) $source['order_ref'];
        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'delivery',
            'idempotency_key' => $idempotencyKey,
            'order_ref' => $orderRef,
        ], $userId);
        if ($groupResult['replayed']) return ['group' => $groupResult['group'], 'order_ref' => $orderRef, 'replayed' => true];

        $lines = accounting_delivery_order_lines($pdo, $orderRef, true);
        foreach ($lines as $line) {
            if ($line['status'] === 'Annulée') throw new RuntimeException('Une ligne de cette référence est annulée et ne peut pas être livrée.');
            if ($line['status'] === 'Livrée') throw new RuntimeException('Cette référence a déjà été livrée.');
            if ((int) ($line['stock_processed'] ?? 0) === 1) throw new RuntimeException('Le stock de cette référence a déjà été traité. Vérifiez son historique avant de la livrer.');
            if (!in_array($line['acquisition_channel'], ['Meta', 'Réachat'], true)) {
                throw new RuntimeException('Renseignez le canal Meta ou Réachat avant de livrer la commande.');
            }
        }
        $total = accounting_delivery_order_total($lines);
        $payments = accounting_normalize_delivery_payments($pdo, $data['payments'] ?? null);
        $paid = 0;
        foreach ($payments as $payment) {
            if ($payment['amount_fcfa'] > PHP_INT_MAX - $paid) throw new RuntimeException('Le total encaissé est trop élevé.');
            $paid += $payment['amount_fcfa'];
        }
        if ((!$exceptionMode && $paid !== $total) || ($exceptionMode && $paid > $total)) {
            throw new RuntimeException('La référence ' . $orderRef . ' présente un total de ' . $total . ' FCFA ; ajustez les encaissements.');
        }
        if ($exceptionMode && $paid < $total && $exceptionReason === null) {
            throw new RuntimeException('Indiquez le motif du reliquat de paiement.');
        }

        $productIds = array_map(static fn (array $line): int => (int) $line['product_id'], $lines);
        accounting_stock_lock_products($pdo, $productIds);
        $neededByProduct = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $neededByProduct[$productId] = ($neededByProduct[$productId] ?? 0) + (int) $line['quantity'];
        }
        $unitCostsByOrder = [];
        $variantIdsByOrder = [];
        foreach ($neededByProduct as $productId => $needed) {
            if (accounting_stock_available($pdo, $productId) < $needed) {
                throw new RuntimeException('Le stock est insuffisant pour livrer toute la référence ' . $orderRef . '.');
            }
        }
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $productId = (int) $line['product_id'];
            $variantId = accounting_stock_variant_id_for_name($pdo, $productId, (string) $line['variant']);
            $unitCost = $variantId !== null ? accounting_stock_unit_cost_snapshot($pdo, $productId, $variantId) : null;
            $unitCost ??= accounting_stock_unit_cost_snapshot($pdo, $productId);
            if ($unitCost === null) throw new RuntimeException('Renseignez un réassort avec coût avant de livrer cette commande.');
            $variantIdsByOrder[$lineId] = $variantId;
            $unitCostsByOrder[$lineId] = $unitCost;
        }

        $category = accounting_delivery_sale_category($pdo);
        $paymentAllocations = accounting_allocate_delivery_payments($lines, $payments);
        foreach ($payments as $paymentIndex => $payment) {
            $operation = accounting_create_draft_operation($pdo, [
                'group_id' => $groupResult['group']['id'],
                'nature' => 'receipt',
                'account_id' => $payment['account_id'],
                'category_id' => $category['id'],
                'source_type' => 'order',
                'amount_fcfa' => $payment['amount_fcfa'],
                'effective_at' => $effectiveAt,
                'label' => 'Livraison ' . $orderRef,
                'counterparty' => trim($lines[0]['customer_first_name'] . ' ' . $lines[0]['customer_last_name']),
                'payment_reference' => $payment['payment_reference'],
                'note' => $exceptionMode && $paid < $total ? $exceptionReason : null,
            ], $userId);
            $allocations = [];
            foreach ($lines as $line) {
                $allocated = (int) ($paymentAllocations[$paymentIndex][(int) $line['id']] ?? 0);
                if ($allocated < 1) continue;
                $allocations[] = [
                    'category_id' => $category['id'],
                    'scope' => 'product',
                    'product_id' => $line['product_id'],
                    'order_id' => $line['id'],
                    'amount_fcfa' => $allocated,
                ];
            }
            accounting_replace_draft_allocations($pdo, (int) $operation['id'], $allocations, $userId);
            if ($paymentIndex === 0) accounting_add_delivery_cogs_allocations($pdo, (int) $operation['id'], $lines, $unitCostsByOrder, (int) $category['id']);
            accounting_confirm_operation($pdo, (int) $operation['id'], $userId);
        }

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            accounting_stock_record_movement($pdo, [
                'product_id' => $productId,
                'movement_type' => 'Sortie',
                'quantity' => $line['quantity'],
                'order_id' => $line['id'],
                'operation_group_id' => $groupResult['group']['id'],
                'variant_id' => $variantIdsByOrder[(int) $line['id']] ?? null,
                'unit_cost_snapshot_fcfa' => $unitCostsByOrder[(int) $line['id']],
                'sale_unit_price_fcfa' => $line['unit_price_fcfa'],
                'note' => 'Livraison ' . $orderRef,
                'actor' => admin_identity(),
            ]);
        }
        $update = $pdo->prepare('UPDATE orders SET status = "Livrée", stock_processed = 1, delivered_at = ? WHERE order_ref = ?');
        $update->execute([$effectiveAt, $orderRef]);
        sync_closer_tracking_for_order_ref($pdo, $orderRef);
        if ($paid < $total) {
            $exception = $pdo->prepare(
                'INSERT INTO accounting_payment_exceptions (order_ref, reason, status, opened_by_user_id, opened_at)
                 VALUES (?, ?, "open", ?, ?)'
            );
            $exception->execute([$orderRef, $exceptionReason, $userId ?? accounting_current_user_id(), $effectiveAt]);
        }
        log_event('livraison', 'Commande ' . $orderRef . ' livrée · ' . $paid . ' FCFA encaissé(s)', (int) $lines[0]['product_id'], $orderId);
        accounting_audit($pdo, 'confirm_delivery', 'order_reference', null, ['order_ref' => $orderRef], [
            'group_id' => $groupResult['group']['id'], 'total_fcfa' => $total, 'paid_fcfa' => $paid, 'exception' => $paid < $total,
        ], $userId);
        return ['group' => $groupResult['group'], 'order_ref' => $orderRef, 'replayed' => false, 'total_fcfa' => $total, 'paid_fcfa' => $paid];
    });
}
