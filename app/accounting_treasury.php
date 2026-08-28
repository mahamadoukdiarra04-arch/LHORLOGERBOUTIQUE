<?php
declare(strict_types=1);

/**
 * Treasury operations stay server-side so the future UI cannot bypass
 * account, category or balance controls.
 */
function accounting_category_by_code(PDO $pdo, string $code, bool $lock = false): array {
    $statement = $pdo->prepare(
        'SELECT * FROM accounting_categories WHERE code = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$code]);
    $category = $statement->fetch();
    if (!$category || !(bool) $category['is_active']) {
        throw new RuntimeException('La catégorie comptable requise doit être active.');
    }
    return $category;
}

function accounting_sum_payment_amounts(array $payments): int {
    $total = 0;
    foreach ($payments as $payment) {
        $amount = accounting_integer($payment['amount_fcfa'] ?? null, 'Le montant', 1);
        if ($amount > PHP_INT_MAX - $total) throw new RuntimeException('Le total des encaissements est trop élevé.');
        $total += $amount;
    }
    return $total;
}

function accounting_signed_difference(int $left, int $right, string $label): int {
    if (($right > 0 && $left < PHP_INT_MIN + $right) || ($right < 0 && $left > PHP_INT_MAX + $right)) {
        throw new RuntimeException($label . ' est hors limite.');
    }
    return $left - $right;
}

function accounting_require_debit_authorization(PDO $pdo, int $accountId, int $debitFcfa, string $effectiveAt, mixed $allowNegative, mixed $acknowledgement, mixed $note): void {
    if ($debitFcfa < 1) throw new RuntimeException('Le débit doit être strictement positif.');
    accounting_require_active_account($pdo, $accountId, true);
    $balance = accounting_account_balance($pdo, $accountId, $effectiveAt);
    if ($balance >= $debitFcfa) return;

    if (accounting_flag($allowNegative, 'L’autorisation de solde négatif') !== 1) {
        throw new RuntimeException('Le compte ne couvre pas ce décaissement. Ajoutez une note et confirmez explicitement cette exception si elle est réelle.');
    }
    accounting_non_empty_text($acknowledgement, 'La confirmation de solde négatif', 500);
    accounting_non_empty_text($note, 'La note de solde négatif', 5000);
}

function accounting_insert_zero_cogs_allocation(PDO $pdo, int $operationId, array $category, int $productId, ?int $orderId, ?int $directSaleItemId, int $quantity, int $unitCost, int $effectSign): void {
    if (!in_array($effectSign, [-1, 1], true)) throw new RuntimeException('Le sens du coût historique est invalide.');
    $insert = $pdo->prepare(
        'INSERT INTO accounting_allocations
         (operation_id, category_id, treatment, scope, product_id, order_id, direct_sale_item_id, amount_fcfa, effect_sign, quantity_equivalent, unit_cost_snapshot_fcfa, cogs_amount_fcfa)
         VALUES (?, ?, ?, "product", ?, ?, ?, 0, ?, ?, ?, ?)'
    );
    $insert->execute([
        $operationId,
        $category['id'],
        $category['treatment'],
        $productId,
        $orderId,
        $directSaleItemId,
        $effectSign,
        accounting_quantity_equivalent((string) $quantity),
        $unitCost,
        accounting_historical_cogs_fcfa($quantity, $unitCost),
    ]);
}

function accounting_create_disbursement(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $status = (string) ($data['status'] ?? 'confirmed');
    if (!in_array($status, ['draft', 'confirmed'], true)) throw new RuntimeException('Statut de décaissement invalide.');
    $effectiveAt = accounting_effective_at($data['effective_at'] ?? null, 'La date de décaissement');
    $scope = (string) ($data['scope'] ?? '');
    if (!in_array($scope, ['product', 'shop'], true)) throw new RuntimeException('La portée du décaissement est invalide.');

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $idempotencyKey, $status, $effectiveAt, $scope): array {
        $accountId = accounting_integer($data['account_id'] ?? null, 'Le compte de décaissement', 1);
        $amount = accounting_integer($data['amount_fcfa'] ?? null, 'Le montant du décaissement', 1);
        $categoryId = accounting_integer($data['category_id'] ?? null, 'La catégorie', 1);
        $category = accounting_require_active_category($pdo, $categoryId, true);
        if (!in_array($category['direction'], ['disbursement', 'both'], true)) throw new RuntimeException('Cette catégorie ne peut pas être utilisée pour un décaissement.');
        $productId = array_key_exists('product_id', $data) && $data['product_id'] !== '' ? accounting_integer($data['product_id'], 'Le produit', 1) : null;
        if (!accounting_allocation_scope_is_valid($category, $scope, $productId)) {
            throw new RuntimeException('La portée et le produit ne correspondent pas à la catégorie choisie.');
        }
        accounting_assert_product_exists($pdo, $productId);
        $label = accounting_non_empty_text($data['label'] ?? null, 'Le libellé', 180);
        $note = accounting_optional_text($data['note'] ?? null, 'La note', 5000);

        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'manual',
            'idempotency_key' => $idempotencyKey,
        ], $userId);
        if ($groupResult['replayed']) {
            $existing = $pdo->prepare('SELECT id FROM accounting_operations WHERE group_id = ? ORDER BY id ASC LIMIT 1');
            $existing->execute([(int) $groupResult['group']['id']]);
            $operationId = (int) $existing->fetchColumn();
            if ($operationId < 1) throw new RuntimeException('Cette confirmation est incomplète. Rechargez la page avant de réessayer.');
            return ['group' => $groupResult['group'], 'operation' => accounting_find_operation($pdo, $operationId), 'replayed' => true];
        }

        if ($status === 'confirmed') {
            accounting_require_debit_authorization(
                $pdo, $accountId, $amount, $effectiveAt,
                $data['allow_negative_balance'] ?? '0',
                $data['negative_balance_acknowledgement'] ?? null,
                $note,
            );
        }
        $operation = accounting_create_draft_operation($pdo, [
            'group_id' => $groupResult['group']['id'],
            'nature' => 'disbursement',
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'source_type' => 'manual',
            'amount_fcfa' => $amount,
            'effective_at' => $effectiveAt,
            'label' => $label,
            'counterparty' => $data['counterparty'] ?? null,
            'payment_reference' => $data['payment_reference'] ?? null,
            'note' => $note,
        ], $userId);
        accounting_replace_draft_allocations($pdo, (int) $operation['id'], [[
            'category_id' => $categoryId,
            'scope' => $scope,
            'product_id' => $productId,
            'amount_fcfa' => $amount,
        ]], $userId);
        if ($status === 'confirmed') $operation = accounting_confirm_operation($pdo, (int) $operation['id'], $userId)['operation'];
        accounting_audit($pdo, 'create_disbursement', 'operation', (int) $operation['id'], null, ['status' => $status, 'amount_fcfa' => $amount], $userId);
        return ['group' => $groupResult['group'], 'operation' => $operation, 'replayed' => false];
    });
}

/**
 * Records money put into a real treasury account by an owner or associate.
 * This is deliberately separate from sales: it affects the account balance,
 * but never revenue, product profitability, or turnover.
 */
function accounting_create_owner_contribution(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $status = (string) ($data['status'] ?? 'confirmed');
    if (!in_array($status, ['draft', 'confirmed'], true)) throw new RuntimeException('Statut d’apport invalide.');
    $effectiveAt = accounting_effective_at($data['effective_at'] ?? null, 'La date de l’apport');

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $idempotencyKey, $status, $effectiveAt): array {
        $accountId = accounting_integer($data['account_id'] ?? null, 'Le compte à alimenter', 1);
        accounting_require_active_account($pdo, $accountId, true);
        $amount = accounting_integer($data['amount_fcfa'] ?? null, 'Le montant de l’apport', 1);
        $contributor = accounting_non_empty_text($data['counterparty'] ?? null, 'Le nom de l’associé', 180);
        $note = accounting_optional_text($data['note'] ?? null, 'La note', 5000);
        $category = accounting_category_by_code($pdo, 'owner_contribution', true);

        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'manual',
            'idempotency_key' => $idempotencyKey,
        ], $userId);
        if ($groupResult['replayed']) {
            $existing = $pdo->prepare('SELECT id FROM accounting_operations WHERE group_id = ? ORDER BY id ASC LIMIT 1');
            $existing->execute([(int) $groupResult['group']['id']]);
            $operationId = (int) $existing->fetchColumn();
            if ($operationId < 1) throw new RuntimeException('Cette confirmation est incomplète. Rechargez la page avant de réessayer.');
            return ['group' => $groupResult['group'], 'operation' => accounting_find_operation($pdo, $operationId), 'replayed' => true];
        }

        $operation = accounting_create_draft_operation($pdo, [
            'group_id' => $groupResult['group']['id'],
            'nature' => 'receipt',
            'account_id' => $accountId,
            'category_id' => $category['id'],
            'source_type' => 'manual',
            'amount_fcfa' => $amount,
            'effective_at' => $effectiveAt,
            'label' => 'Apport associé · ' . $contributor,
            'counterparty' => $contributor,
            'payment_reference' => $data['payment_reference'] ?? null,
            'note' => $note,
        ], $userId);
        accounting_replace_draft_allocations($pdo, (int) $operation['id'], [[
            'category_id' => $category['id'],
            'scope' => 'shop',
            'amount_fcfa' => $amount,
        ]], $userId);
        if ($status === 'confirmed') $operation = accounting_confirm_operation($pdo, (int) $operation['id'], $userId)['operation'];
        accounting_audit($pdo, 'create_owner_contribution', 'operation', (int) $operation['id'], null, ['status' => $status, 'amount_fcfa' => $amount], $userId);
        return ['group' => $groupResult['group'], 'operation' => $operation, 'replayed' => false];
    });
}

function accounting_confirm_draft_disbursement(PDO $pdo, int $operationId, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    return accounting_with_transaction($pdo, function () use ($pdo, $operationId, $data, $userId): array {
        $operation = accounting_find_operation($pdo, $operationId, true);
        if ($operation['status'] !== 'draft' || $operation['nature'] !== 'disbursement' || $operation['source_type'] !== 'manual') {
            throw new RuntimeException('Seul un brouillon de décaissement peut être confirmé par ce parcours.');
        }
        accounting_require_debit_authorization(
            $pdo,
            (int) $operation['account_id'],
            (int) $operation['amount_fcfa'],
            (string) $operation['effective_at'],
            $data['allow_negative_balance'] ?? '0',
            $data['negative_balance_acknowledgement'] ?? null,
            $operation['note'],
        );
        $confirmed = accounting_confirm_operation($pdo, $operationId, $userId);
        accounting_audit($pdo, 'confirm_draft_disbursement', 'operation', $operationId, $operation, $confirmed['operation'], $userId);
        return $confirmed;
    });
}

function accounting_create_transfer(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $effectiveAt = accounting_effective_at($data['effective_at'] ?? null, 'La date du transfert');

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $idempotencyKey, $effectiveAt): array {
        $sourceAccountId = accounting_integer($data['source_account_id'] ?? null, 'Le compte source', 1);
        $destinationAccountId = accounting_integer($data['destination_account_id'] ?? null, 'Le compte destinataire', 1);
        if ($sourceAccountId === $destinationAccountId) throw new RuntimeException('Les comptes de transfert doivent être différents.');
        $amount = accounting_integer($data['amount_fcfa'] ?? null, 'Le montant du transfert', 1);
        $feeAmount = accounting_integer($data['fee_amount_fcfa'] ?? 0, 'Les frais de transfert', 0);
        if ($feeAmount > PHP_INT_MAX - $amount) throw new RuntimeException('Le débit total du transfert est trop élevé.');
        $accountIds = [$sourceAccountId, $destinationAccountId];
        sort($accountIds, SORT_NUMERIC);
        foreach ($accountIds as $accountId) accounting_require_active_account($pdo, $accountId, true);

        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'transfer',
            'idempotency_key' => $idempotencyKey,
        ], $userId);
        if ($groupResult['replayed']) {
            $operations = $pdo->prepare('SELECT id FROM accounting_operations WHERE group_id = ? ORDER BY id ASC');
            $operations->execute([(int) $groupResult['group']['id']]);
            $ids = array_map('intval', $operations->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) throw new RuntimeException('Cette confirmation est incomplète. Rechargez la page avant de réessayer.');
            return ['group' => $groupResult['group'], 'operation_ids' => $ids, 'replayed' => true];
        }

        $note = accounting_optional_text($data['note'] ?? null, 'La note', 5000);
        accounting_require_debit_authorization(
            $pdo, $sourceAccountId, $amount + $feeAmount, $effectiveAt,
            $data['allow_negative_balance'] ?? '0',
            $data['negative_balance_acknowledgement'] ?? null,
            $note,
        );
        $main = accounting_create_draft_operation($pdo, [
            'group_id' => $groupResult['group']['id'],
            'nature' => 'transfer',
            'account_id' => $sourceAccountId,
            'destination_account_id' => $destinationAccountId,
            'source_type' => 'transfer',
            'amount_fcfa' => $amount,
            'effective_at' => $effectiveAt,
            'label' => 'Transfert entre comptes',
            'payment_reference' => $data['payment_reference'] ?? null,
            'note' => $note,
        ], $userId);
        $main = accounting_confirm_operation($pdo, (int) $main['id'], $userId)['operation'];
        $operationIds = [(int) $main['id']];
        if ($feeAmount > 0) {
            $feeCategory = accounting_category_by_code($pdo, 'bank_fee', true);
            $fee = accounting_create_draft_operation($pdo, [
                'group_id' => $groupResult['group']['id'],
                'nature' => 'disbursement',
                'account_id' => $sourceAccountId,
                'category_id' => $feeCategory['id'],
                'source_type' => 'manual',
                'amount_fcfa' => $feeAmount,
                'effective_at' => $effectiveAt,
                'label' => 'Frais de transfert',
                'payment_reference' => $data['payment_reference'] ?? null,
                'note' => $note,
            ], $userId);
            accounting_replace_draft_allocations($pdo, (int) $fee['id'], [[
                'category_id' => $feeCategory['id'], 'scope' => 'shop', 'amount_fcfa' => $feeAmount,
            ]], $userId);
            $fee = accounting_confirm_operation($pdo, (int) $fee['id'], $userId)['operation'];
            $operationIds[] = (int) $fee['id'];
        }
        accounting_audit($pdo, 'create_transfer', 'operation_group', (int) $groupResult['group']['id'], null, ['operation_ids' => $operationIds], $userId);
        return ['group' => $groupResult['group'], 'operation_ids' => $operationIds, 'replayed' => false];
    });
}

function accounting_create_reconciliation(PDO $pdo, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    $accountId = accounting_integer($data['account_id'] ?? null, 'Le compte à rapprocher', 1);
    $reconciledAt = accounting_effective_at($data['reconciled_at'] ?? null, 'La date de rapprochement');
    $statementBalance = accounting_integer($data['statement_balance_fcfa'] ?? null, 'Le solde du relevé', PHP_INT_MIN);
    $note = accounting_optional_text($data['note'] ?? null, 'La note de rapprochement', 1000);

    return accounting_with_transaction($pdo, function () use ($pdo, $accountId, $reconciledAt, $statementBalance, $note, $userId): array {
        accounting_require_active_account($pdo, $accountId, true);
        $calculated = accounting_account_balance($pdo, $accountId, $reconciledAt);
        $difference = accounting_signed_difference($statementBalance, $calculated, 'L’écart de rapprochement');
        $insert = $pdo->prepare(
            'INSERT INTO accounting_reconciliations
             (account_id, reconciled_at, calculated_balance_fcfa, statement_balance_fcfa, difference_fcfa, note, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$accountId, $reconciledAt, $calculated, $statementBalance, $difference, $note, $userId ?? accounting_current_user_id()]);
        $id = (int) $pdo->lastInsertId();
        $record = $pdo->prepare('SELECT * FROM accounting_reconciliations WHERE id = ?');
        $record->execute([$id]);
        $reconciliation = $record->fetch();
        accounting_audit($pdo, 'create_reconciliation', 'reconciliation', $id, null, $reconciliation ?: null, $userId);
        return $reconciliation ?: throw new RuntimeException('Le rapprochement n’a pas pu être enregistré.');
    });
}
