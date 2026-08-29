<?php
declare(strict_types=1);

function accounting_order_lines_by_ref(PDO $pdo, string $orderRef, bool $lock = false): array {
    $orderRef = accounting_non_empty_text($orderRef, 'La référence de commande', 32);
    return accounting_delivery_order_lines($pdo, $orderRef, $lock);
}

/** @return array<int,int> amount already received for each order line */
function accounting_order_line_receipts(PDO $pdo, string $orderRef): array {
    $statement = $pdo->prepare(
        'SELECT a.order_id, COALESCE(SUM(a.amount_fcfa), 0) AS received_fcfa
         FROM accounting_allocations a
         INNER JOIN accounting_operations o ON o.id = a.operation_id AND o.status = "confirmed" AND o.nature = "receipt"
         INNER JOIN accounting_operation_groups g ON g.id = o.group_id
         WHERE g.order_ref = ? AND a.order_id IS NOT NULL AND a.amount_fcfa > 0
         GROUP BY a.order_id'
    );
    $statement->execute([$orderRef]);
    $receipts = [];
    foreach ($statement->fetchAll() as $row) $receipts[(int) $row['order_id']] = accounting_integer((string) $row['received_fcfa'], 'Les encaissements de commande', 0);
    return $receipts;
}

function accounting_allocate_remaining_payments(array $remainingByLine, array $payments): array {
    $remaining = [];
    foreach ($remainingByLine as $lineId => $amount) {
        $id = accounting_integer($lineId, 'La ligne de vente', 1);
        $value = accounting_integer($amount, 'Le reliquat de ligne', 0);
        if ($value > 0) $remaining[$id] = $value;
    }
    $result = [];
    foreach (array_values($payments) as $paymentIndex => $payment) {
        $amount = accounting_integer($payment['amount_fcfa'] ?? null, 'Le montant encaissé', 1);
        $outstanding = 0;
        foreach ($remaining as $value) {
            if ($value > PHP_INT_MAX - $outstanding) throw new RuntimeException('Le reliquat de la vente est trop élevé.');
            $outstanding += $value;
        }
        if ($amount > $outstanding) throw new RuntimeException('Les encaissements dépassent le reliquat de la vente.');
        $weights = [];
        foreach ($remaining as $lineId => $value) if ($value > 0) $weights[] = ['key' => $lineId, 'weight' => $value];
        $allocation = accounting_allocate_largest_remainder($amount, $weights);
        foreach ($allocation as $lineId => $allocated) $remaining[$lineId] -= $allocated;
        $result[$paymentIndex] = $allocation;
    }
    return $result;
}

function accounting_collect_order_balance(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $orderRef = accounting_non_empty_text($data['order_ref'] ?? null, 'La référence de commande', 32);
    $effectiveAt = accounting_effective_at($data['effective_at'] ?? null, 'La date d’encaissement');

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $idempotencyKey, $orderRef, $effectiveAt): array {
        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'balance_collection', 'idempotency_key' => $idempotencyKey, 'order_ref' => $orderRef,
        ], $userId);
        if ($groupResult['replayed']) return ['group' => $groupResult['group'], 'order_ref' => $orderRef, 'replayed' => true];

        $lines = accounting_order_lines_by_ref($pdo, $orderRef, true);
        foreach ($lines as $line) if ($line['status'] !== 'Livrée') throw new RuntimeException('Seule une référence entièrement livrée peut être régularisée.');
        $exception = $pdo->prepare('SELECT id FROM accounting_payment_exceptions WHERE order_ref = ? AND status = "open" FOR UPDATE');
        $exception->execute([$orderRef]);
        if (!$exception->fetchColumn()) {
            throw new RuntimeException('Cette référence ne présente pas de reliquat de livraison à régulariser.');
        }
        $receipts = accounting_order_line_receipts($pdo, $orderRef);
        $remaining = [];
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $total = accounting_historical_cogs_fcfa($line['quantity'], $line['unit_price_fcfa']);
            $received = $receipts[$lineId] ?? 0;
            if ($received > $total) throw new RuntimeException('Cette référence est sur-encaissée et doit être vérifiée avant toute régularisation.');
            $remaining[$lineId] = $total - $received;
        }
        $remainder = 0;
        foreach ($remaining as $lineRemainder) {
            if ($lineRemainder > PHP_INT_MAX - $remainder) throw new RuntimeException('Le reliquat de la référence est trop élevé.');
            $remainder += $lineRemainder;
        }
        if ($remainder < 1) throw new RuntimeException('Cette référence ne présente plus de reliquat à encaisser.');
        $payments = accounting_normalize_delivery_payments($pdo, $data['payments'] ?? null);
        $paid = accounting_sum_payment_amounts($payments);
        if ($paid > $remainder) throw new RuntimeException('La référence ' . $orderRef . ' présente un reliquat de ' . $remainder . ' FCFA ; ajustez les encaissements.');
        $category = accounting_delivery_sale_category($pdo);
        $paymentAllocations = accounting_allocate_remaining_payments($remaining, $payments);
        foreach ($payments as $paymentIndex => $payment) {
            $operation = accounting_create_draft_operation($pdo, [
                'group_id' => $groupResult['group']['id'], 'nature' => 'receipt', 'account_id' => $payment['account_id'],
                'category_id' => $category['id'], 'source_type' => 'order', 'amount_fcfa' => $payment['amount_fcfa'],
                'effective_at' => $effectiveAt, 'label' => 'Régularisation ' . $orderRef,
                'counterparty' => trim($lines[0]['customer_first_name'] . ' ' . $lines[0]['customer_last_name']),
                'payment_reference' => $payment['payment_reference'], 'note' => $data['note'] ?? null,
            ], $userId);
            $allocations = [];
            foreach ($lines as $line) {
                $amount = (int) ($paymentAllocations[$paymentIndex][(int) $line['id']] ?? 0);
                if ($amount > 0) $allocations[] = [
                    'category_id' => $category['id'], 'scope' => 'product', 'product_id' => $line['product_id'],
                    'order_id' => $line['id'], 'amount_fcfa' => $amount,
                ];
            }
            accounting_replace_draft_allocations($pdo, (int) $operation['id'], $allocations, $userId);
            accounting_confirm_operation($pdo, (int) $operation['id'], $userId);
        }
        if ($paid === $remainder) {
            $update = $pdo->prepare(
                'UPDATE accounting_payment_exceptions SET status = "resolved", resolved_by_user_id = ?, resolved_at = ?
                 WHERE order_ref = ? AND status = "open"'
            );
            $update->execute([$userId ?? accounting_current_user_id(), $effectiveAt, $orderRef]);
        }
        accounting_audit($pdo, 'collect_order_balance', 'order_reference', null, ['order_ref' => $orderRef], ['group_id' => $groupResult['group']['id'], 'paid_fcfa' => $paid], $userId);
        return ['group' => $groupResult['group'], 'order_ref' => $orderRef, 'paid_fcfa' => $paid, 'remaining_fcfa' => $remainder - $paid, 'replayed' => false];
    });
}

function accounting_normalize_direct_sale_items(mixed $input): array {
    if (!is_array($input)) throw new RuntimeException('Ajoutez au moins une montre à la vente directe.');
    $items = [];
    foreach (array_values($input) as $item) {
        if (!is_array($item)) throw new RuntimeException('Une ligne de vente directe est invalide.');
        $productRaw = trim((string) ($item['product_id'] ?? ''));
        $variantRaw = trim((string) ($item['variant_id'] ?? ''));
        $quantityRaw = trim((string) ($item['quantity'] ?? ''));
        $priceRaw = trim((string) ($item['unit_price_fcfa'] ?? ''));
        $discountRaw = trim((string) ($item['discount_fcfa'] ?? '0'));
        if ($productRaw === '' && $variantRaw === '' && $quantityRaw === '' && $priceRaw === '') continue;
        if ($productRaw === '' || $variantRaw === '' || $quantityRaw === '' || $priceRaw === '') {
            throw new RuntimeException('Chaque montre doit avoir un produit, un coloris, une quantité et un prix.');
        }
        $quantity = accounting_integer($quantityRaw, 'La quantité vendue', 1);
        if ($quantity > 32767) throw new RuntimeException('La quantité vendue est trop élevée.');
        $price = accounting_integer($priceRaw, 'Le prix unitaire', 1);
        $discount = accounting_integer($discountRaw, 'La remise', 0);
        $subtotal = accounting_historical_cogs_fcfa($quantity, $price);
        if ($discount >= $subtotal) throw new RuntimeException('La remise doit rester inférieure au sous-total de la ligne.');
        $items[] = [
            'product_id' => accounting_integer($productRaw, 'Le produit', 1), 'quantity' => $quantity,
            'variant_id' => accounting_integer($variantRaw, 'Le coloris', 1),
            'unit_price_fcfa' => $price, 'discount_fcfa' => $discount, 'line_total_fcfa' => $subtotal - $discount,
        ];
    }
    if ($items === []) throw new RuntimeException('Ajoutez au moins une montre à la vente directe.');
    return $items;
}

function accounting_direct_sale_totals(array $items): array {
    $subtotal = 0;
    $discountTotal = 0;
    foreach ($items as $item) {
        $gross = accounting_historical_cogs_fcfa((int) $item['quantity'], (int) $item['unit_price_fcfa']);
        $discount = (int) $item['discount_fcfa'];
        if ($gross > PHP_INT_MAX - $subtotal || $discount > PHP_INT_MAX - $discountTotal) {
            throw new RuntimeException('Le total de vente est trop élevé.');
        }
        $subtotal += $gross;
        $discountTotal += $discount;
    }
    return [
        'subtotal_fcfa' => $subtotal,
        'discount_total_fcfa' => $discountTotal,
        'total_fcfa' => $subtotal - $discountTotal,
    ];
}

function accounting_create_direct_sale(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $effectiveAt = accounting_effective_at($data['effective_at'] ?? null, 'La date de vente');
    $deductStock = accounting_flag($data['deduct_stock'] ?? '1', 'La déduction de stock') === 1;
    $skipReason = accounting_optional_text($data['stock_skip_reason'] ?? null, 'Le motif sans sortie de stock', 500);
    if (!$deductStock && $skipReason === null) throw new RuntimeException('Indiquez le motif si la vente directe ne diminue pas le stock.');

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $idempotencyKey, $effectiveAt, $deductStock, $skipReason): array {
        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'direct_sale', 'idempotency_key' => $idempotencyKey,
        ], $userId);
        if ($groupResult['replayed']) {
            $saleId = (int) ($groupResult['group']['direct_sale_id'] ?? 0);
            if ($saleId < 1) throw new RuntimeException('Cette confirmation est incomplète. Rechargez la page avant de réessayer.');
            $sale = $pdo->prepare('SELECT * FROM direct_sales WHERE id = ?');
            $sale->execute([$saleId]);
            return ['group' => $groupResult['group'], 'sale' => $sale->fetch(), 'replayed' => true];
        }
        $items = accounting_normalize_direct_sale_items($data['items'] ?? null);
        $products = accounting_stock_lock_products($pdo, array_column($items, 'product_id'));
        $needed = [];
        $variantLabels = [];
        foreach ($items as $index => $item) {
            $variant = accounting_stock_lock_variant($pdo, $item['product_id'], $item['variant_id']);
            $items[$index]['variant_snapshot'] = (string) $variant['name'];
            $key = $item['product_id'] . ':' . $item['variant_id'];
            $needed[$key] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'quantity' => ($needed[$key]['quantity'] ?? 0) + $item['quantity'],
            ];
            $variantLabels[$key] = (string) $variant['name'];
        }
        $unitCosts = [];
        if ($deductStock) {
            foreach ($needed as $key => $requirement) {
                $productId = (int) $requirement['product_id'];
                $variantId = (int) $requirement['variant_id'];
                if (accounting_stock_variant_available($pdo, $productId, $variantId) < (int) $requirement['quantity']) {
                    throw new RuntimeException('Le stock est insuffisant pour ' . $products[$productId]['name'] . ' · ' . $variantLabels[$key] . '.');
                }
                $cost = accounting_stock_unit_cost_snapshot($pdo, $productId, $variantId);
                if ($cost === null) {
                    throw new RuntimeException('Renseignez un réassort avec coût pour ' . $products[$productId]['name'] . ' · ' . $variantLabels[$key] . '.');
                }
                $unitCosts[$key] = $cost;
            }
        }
        $totals = accounting_direct_sale_totals($items);
        $subtotal = $totals['subtotal_fcfa'];
        $discountTotal = $totals['discount_total_fcfa'];
        $total = $totals['total_fcfa'];
        $payments = accounting_normalize_delivery_payments($pdo, $data['payments'] ?? null);
        if (accounting_sum_payment_amounts($payments) !== $total) throw new RuntimeException('Les encaissements doivent être exactement égaux au total de la vente directe.');
        $insertSale = $pdo->prepare(
            'INSERT INTO direct_sales
             (sale_ref, customer_name, customer_phone, channel, subtotal_fcfa, discount_total_fcfa, total_fcfa, deduct_stock, stock_skip_reason, effective_at, status, note, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "confirmed", ?, ?)'
        );
        $saleRef = accounting_public_reference('VDR');
        $insertSale->execute([
            $saleRef, accounting_optional_text($data['customer_name'] ?? null, 'Le client', 200),
            accounting_optional_text($data['customer_phone'] ?? null, 'Le téléphone', 32),
            accounting_optional_text($data['channel'] ?? null, 'Le canal', 60), $subtotal, $discountTotal, $total,
            $deductStock ? 1 : 0, $skipReason, $effectiveAt,
            accounting_optional_text($data['note'] ?? null, 'La note', 5000), $userId ?? accounting_current_user_id(),
        ]);
        $saleId = (int) $pdo->lastInsertId();
        $link = $pdo->prepare('UPDATE accounting_operation_groups SET direct_sale_id = ? WHERE id = ? AND direct_sale_id IS NULL');
        $link->execute([$saleId, $groupResult['group']['id']]);
        if ($link->rowCount() !== 1) throw new RuntimeException('Le groupe comptable ne peut pas être relié à la vente directe.');
        $itemInsert = $pdo->prepare(
            'INSERT INTO direct_sale_items
             (direct_sale_id, product_id, variant_id, product_name_snapshot, variant_snapshot, quantity, unit_price_fcfa, discount_fcfa, line_total_fcfa, unit_cost_snapshot_fcfa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $index => $item) {
            $variantKey = $item['product_id'] . ':' . $item['variant_id'];
            $itemInsert->execute([$saleId, $item['product_id'], $item['variant_id'], $products[$item['product_id']]['name'], $item['variant_snapshot'], $item['quantity'], $item['unit_price_fcfa'], $item['discount_fcfa'], $item['line_total_fcfa'], $unitCosts[$variantKey] ?? 0]);
            $items[$index]['id'] = (int) $pdo->lastInsertId();
        }
        $category = accounting_delivery_sale_category($pdo);
        $paymentAllocations = accounting_allocate_remaining_payments(array_column($items, 'line_total_fcfa', 'id'), $payments);
        foreach ($payments as $paymentIndex => $payment) {
            $operation = accounting_create_draft_operation($pdo, [
                'group_id' => $groupResult['group']['id'], 'nature' => 'receipt', 'account_id' => $payment['account_id'],
                'category_id' => $category['id'], 'source_type' => 'direct_sale', 'amount_fcfa' => $payment['amount_fcfa'],
                'effective_at' => $effectiveAt, 'label' => 'Vente directe ' . $saleRef,
                'counterparty' => $data['customer_name'] ?? null, 'payment_reference' => $payment['payment_reference'], 'note' => $data['note'] ?? null,
            ], $userId);
            $allocations = [];
            foreach ($items as $item) {
                $amount = (int) ($paymentAllocations[$paymentIndex][$item['id']] ?? 0);
                if ($amount > 0) $allocations[] = [
                    'category_id' => $category['id'], 'scope' => 'product', 'product_id' => $item['product_id'],
                    'direct_sale_item_id' => $item['id'], 'amount_fcfa' => $amount,
                ];
            }
            accounting_replace_draft_allocations($pdo, (int) $operation['id'], $allocations, $userId);
            if ($deductStock && $paymentIndex === 0) {
                foreach ($items as $item) {
                    $variantKey = $item['product_id'] . ':' . $item['variant_id'];
                    accounting_insert_zero_cogs_allocation(
                        $pdo, (int) $operation['id'], $category, $item['product_id'], null, $item['id'],
                        $item['quantity'], $unitCosts[$variantKey], 1,
                    );
                }
            }
            accounting_confirm_operation($pdo, (int) $operation['id'], $userId);
        }
        if ($deductStock) foreach ($items as $item) {
            $variantKey = $item['product_id'] . ':' . $item['variant_id'];
            accounting_stock_record_movement($pdo, [
                'product_id' => $item['product_id'], 'variant_id' => $item['variant_id'], 'movement_type' => 'Sortie', 'quantity' => $item['quantity'],
                'direct_sale_item_id' => $item['id'], 'operation_group_id' => $groupResult['group']['id'],
                'unit_cost_snapshot_fcfa' => $unitCosts[$variantKey], 'sale_unit_price_fcfa' => $item['unit_price_fcfa'],
                'note' => 'Vente directe ' . $saleRef, 'actor' => admin_identity(),
            ]);
        }
        $sale = $pdo->prepare('SELECT * FROM direct_sales WHERE id = ?');
        $sale->execute([$saleId]);
        $createdSale = $sale->fetch();
        accounting_audit($pdo, 'create_direct_sale', 'direct_sale', $saleId, null, ['sale_ref' => $saleRef, 'total_fcfa' => $total, 'deduct_stock' => $deductStock], $userId);
        return ['group' => accounting_find_operation_group($pdo, (int) $groupResult['group']['id']), 'sale' => $createdSale, 'replayed' => false];
    });
}

function accounting_refund_source_lines(PDO $pdo, string $sourceKind, string $reference): array {
    if ($sourceKind === 'order') {
        $lines = accounting_order_lines_by_ref($pdo, $reference, true);
        foreach ($lines as $line) if ($line['status'] !== 'Livrée') throw new RuntimeException('Seule une commande livrée peut être remboursée.');
        $stock = $pdo->prepare('SELECT order_id, quantity, unit_cost_snapshot_fcfa FROM stock_movements WHERE order_id IS NOT NULL AND movement_type = "Sortie"');
        $stock->execute();
        $costs = [];
        foreach ($stock->fetchAll() as $movement) $costs[(int) $movement['order_id']] = $movement;
        foreach ($lines as &$line) {
            $line['source_line_id'] = (int) $line['id'];
            $line['line_total_fcfa'] = accounting_historical_cogs_fcfa($line['quantity'], $line['unit_price_fcfa']);
            $line['unit_cost_snapshot_fcfa'] = isset($costs[(int) $line['id']]) ? (int) $costs[(int) $line['id']]['unit_cost_snapshot_fcfa'] : null;
        }
        unset($line);
        return $lines;
    }
    $sale = $pdo->prepare('SELECT id, sale_ref, status FROM direct_sales WHERE sale_ref = ? FOR UPDATE');
    $sale->execute([$reference]);
    $directSale = $sale->fetch();
    if (!$directSale || $directSale['status'] !== 'confirmed') throw new RuntimeException('Vente directe introuvable ou non remboursable.');
    $items = $pdo->prepare('SELECT * FROM direct_sale_items WHERE direct_sale_id = ? ORDER BY id ASC FOR UPDATE');
    $items->execute([(int) $directSale['id']]);
    $lines = $items->fetchAll();
    foreach ($lines as &$line) $line['source_line_id'] = (int) $line['id'];
    unset($line);
    return ['sale' => $directSale, 'lines' => $lines];
}

function accounting_line_net_paid(PDO $pdo, string $sourceKind, int $sourceLineId): int {
    $field = $sourceKind === 'order' ? 'order_id' : 'direct_sale_item_id';
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN o.nature = "receipt" THEN a.amount_fcfa WHEN o.nature = "disbursement" THEN -a.amount_fcfa ELSE 0 END), 0)
         FROM accounting_allocations a INNER JOIN accounting_operations o ON o.id = a.operation_id AND o.status = "confirmed"
         WHERE a.' . $field . ' = ? AND a.amount_fcfa > 0'
    );
    $statement->execute([$sourceLineId]);
    return accounting_integer((string) $statement->fetchColumn(), 'Le net encaissé de la ligne', PHP_INT_MIN);
}

function accounting_create_refund(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $sourceKind = (string) ($data['source_kind'] ?? '');
    if (!in_array($sourceKind, ['order', 'direct_sale'], true)) throw new RuntimeException('La source du remboursement est invalide.');
    $reference = accounting_non_empty_text($data['source_reference'] ?? null, 'La référence de vente', 50);
    $effectiveAt = accounting_effective_at($data['effective_at'] ?? null, 'La date de remboursement');
    $accountId = accounting_integer($data['account_id'] ?? null, 'Le compte de remboursement', 1);
    $reason = accounting_non_empty_text($data['reason'] ?? null, 'Le motif du remboursement', 1000);
    $amount = accounting_integer($data['amount_fcfa'] ?? null, 'Le montant du remboursement', 1);

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $idempotencyKey, $sourceKind, $reference, $effectiveAt, $accountId, $reason, $amount): array {
        $source = accounting_refund_source_lines($pdo, $sourceKind, $reference);
        $directSale = $sourceKind === 'direct_sale' ? $source['sale'] : null;
        $sourceLines = $sourceKind === 'direct_sale' ? $source['lines'] : $source;
        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'refund', 'idempotency_key' => $idempotencyKey,
            'order_ref' => $sourceKind === 'order' ? $reference : null,
            'direct_sale_id' => $sourceKind === 'direct_sale' ? $directSale['id'] : null,
        ], $userId);
        if ($groupResult['replayed']) return ['group' => $groupResult['group'], 'replayed' => true];
        accounting_require_debit_authorization($pdo, $accountId, $amount, $effectiveAt, '0', null, $reason);
        if (!is_array($data['lines'] ?? null)) throw new RuntimeException('Ajoutez au moins une ligne à rembourser.');
        $byId = [];
        foreach ($sourceLines as $line) $byId[(int) $line['source_line_id']] = $line;
        $entries = [];
        $inputTotal = 0;
        foreach (array_values($data['lines']) as $input) {
            if (!is_array($input)) throw new RuntimeException('Une ligne de remboursement est invalide.');
            $amountRaw = trim((string) ($input['amount_fcfa'] ?? ''));
            $quantityRaw = trim((string) ($input['quantity'] ?? '0'));
            $returnRaw = $input['return_to_stock'] ?? '0';
            if ($amountRaw === '' && ($quantityRaw === '' || $quantityRaw === '0') && accounting_flag($returnRaw, 'Le retour en stock') === 0) {
                continue;
            }
            $lineId = accounting_integer($input['source_line_id'] ?? null, 'La ligne de vente', 1);
            if (!isset($byId[$lineId]) || isset($entries[$lineId])) throw new RuntimeException('Une ligne de remboursement est inconnue ou dupliquée.');
            $lineAmount = accounting_integer($amountRaw, 'Le montant remboursé de la ligne', 1);
            $quantity = accounting_integer($quantityRaw === '' ? '0' : $quantityRaw, 'La quantité retournée', 0);
            $returnToStock = accounting_flag($returnRaw, 'Le retour en stock') === 1;
            $line = $byId[$lineId];
            if ($lineAmount > accounting_line_net_paid($pdo, $sourceKind, $lineId)) throw new RuntimeException('Le remboursement dépasse le net encaissé de cette ligne.');
            $maxQuantity = accounting_integer($line['quantity'], 'La quantité vendue', 1);
            if ($quantity > $maxQuantity) throw new RuntimeException('La quantité retournée dépasse la quantité vendue.');
            if ($returnToStock && $quantity < 1) throw new RuntimeException('Indiquez la quantité réellement retournée en stock.');
            if (!$returnToStock && $quantity !== 0) throw new RuntimeException('Une quantité retournée exige la confirmation du retour physique en stock.');
            if ($returnToStock && $line['unit_cost_snapshot_fcfa'] === null) throw new RuntimeException('Le coût historique de cette ligne est introuvable ; le retour physique ne peut pas être confirmé.');
            if ($lineAmount > PHP_INT_MAX - $inputTotal) throw new RuntimeException('Le remboursement est trop élevé.');
            $inputTotal += $lineAmount;
            $entries[$lineId] = ['line' => $line, 'amount_fcfa' => $lineAmount, 'quantity' => $quantity, 'return_to_stock' => $returnToStock];
        }
        if ($entries === [] || $inputTotal !== $amount) throw new RuntimeException('La somme des lignes doit être exactement égale au remboursement.');
        $category = accounting_category_by_code($pdo, 'refund_product', true);
        $operation = accounting_create_draft_operation($pdo, [
            'group_id' => $groupResult['group']['id'], 'nature' => 'disbursement', 'account_id' => $accountId,
            'category_id' => $category['id'], 'source_type' => 'refund', 'amount_fcfa' => $amount, 'effective_at' => $effectiveAt,
            'label' => 'Remboursement ' . $reference, 'payment_reference' => $data['payment_reference'] ?? null, 'note' => $reason,
        ], $userId);
        $allocations = [];
        foreach ($entries as $entry) {
            $line = $entry['line'];
            $allocations[] = [
                'category_id' => $category['id'], 'scope' => 'product', 'product_id' => $line['product_id'],
                $sourceKind === 'order' ? 'order_id' : 'direct_sale_item_id' => $line['source_line_id'],
                'amount_fcfa' => $entry['amount_fcfa'], 'quantity_equivalent' => $entry['quantity'] > 0 ? (string) $entry['quantity'] : '',
            ];
        }
        accounting_replace_draft_allocations($pdo, (int) $operation['id'], $allocations, $userId);
        $returnVariantIds = [];
        foreach ($entries as $lineId => $entry) if ($entry['return_to_stock']) {
            $line = $entry['line'];
            $returnVariantId = $sourceKind === 'direct_sale'
                ? ((int) ($line['variant_id'] ?? 0) ?: accounting_stock_variant_id_for_name($pdo, (int) $line['product_id'], (string) ($line['variant_snapshot'] ?? '')))
                : accounting_stock_variant_id_for_name($pdo, (int) $line['product_id'], (string) ($line['variant'] ?? ''));
            if ($returnVariantId === null) {
                throw new RuntimeException('Le coloris d’origine est introuvable ; le retour physique ne peut pas être confirmé.');
            }
            $returnVariantIds[(int) $lineId] = $returnVariantId;
            $unitCost = accounting_integer($line['unit_cost_snapshot_fcfa'], 'Le coût historique', 0);
            accounting_insert_zero_cogs_allocation(
                $pdo, (int) $operation['id'], $category, (int) $line['product_id'],
                $sourceKind === 'order' ? (int) $line['source_line_id'] : null,
                $sourceKind === 'direct_sale' ? (int) $line['source_line_id'] : null,
                $entry['quantity'], $unitCost, -1,
            );
        }
        accounting_confirm_operation($pdo, (int) $operation['id'], $userId);
        foreach ($entries as $lineId => $entry) if ($entry['return_to_stock']) {
            $line = $entry['line'];
            accounting_stock_record_movement($pdo, [
                'product_id' => $line['product_id'], 'variant_id' => $returnVariantIds[(int) $lineId], 'movement_type' => 'Ajustement', 'quantity' => $entry['quantity'], 'is_sale_return' => '1',
                $sourceKind === 'order' ? 'order_id' : 'direct_sale_item_id' => $line['source_line_id'],
                'operation_group_id' => $groupResult['group']['id'], 'unit_cost_snapshot_fcfa' => $line['unit_cost_snapshot_fcfa'],
                'sale_unit_price_fcfa' => $line['unit_price_fcfa'], 'note' => 'Retour physique · ' . $reference, 'actor' => admin_identity(),
            ]);
        }
        accounting_audit($pdo, 'create_refund', $sourceKind === 'order' ? 'order_reference' : 'direct_sale', null, ['reference' => $reference], ['group_id' => $groupResult['group']['id'], 'amount_fcfa' => $amount], $userId);
        return ['group' => $groupResult['group'], 'operation' => accounting_find_operation($pdo, (int) $operation['id']), 'replayed' => false];
    });
}
