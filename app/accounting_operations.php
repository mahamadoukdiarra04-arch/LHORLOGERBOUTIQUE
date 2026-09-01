<?php
declare(strict_types=1);

function accounting_group_types(): array {
    return ['delivery', 'balance_collection', 'direct_sale', 'manual', 'transfer', 'refund', 'reversal'];
}

function accounting_operation_natures(): array {
    return ['receipt', 'disbursement', 'transfer'];
}

function accounting_source_types(): array {
    return ['order', 'direct_sale', 'manual', 'refund', 'transfer', 'reversal'];
}

function accounting_public_reference(string $prefix): string {
    return strtoupper($prefix) . '-' . (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function accounting_find_operation_group_by_key(PDO $pdo, string $idempotencyKey, bool $lock = false): ?array {
    $statement = $pdo->prepare(
        'SELECT id, public_reference, group_type, idempotency_key, order_ref, direct_sale_id, created_by_user_id, created_at
         FROM accounting_operation_groups WHERE idempotency_key = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$idempotencyKey]);
    return $statement->fetch() ?: null;
}

function accounting_find_operation_group(PDO $pdo, int $groupId, bool $lock = false): array {
    if ($groupId < 1) throw new RuntimeException('Groupe comptable invalide.');
    $statement = $pdo->prepare(
        'SELECT id, public_reference, group_type, idempotency_key, order_ref, direct_sale_id, created_by_user_id, created_at
         FROM accounting_operation_groups WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$groupId]);
    $group = $statement->fetch();
    if (!$group) throw new RuntimeException('Groupe comptable introuvable.');
    return $group;
}

/**
 * A group is the idempotent envelope of a business action. A retry returns the
 * existing group; a reused key for another target is deliberately rejected.
 */
function accounting_create_operation_group(PDO $pdo, array $data, ?int $userId = null): array {
    $groupType = (string) ($data['group_type'] ?? '');
    if (!in_array($groupType, accounting_group_types(), true)) throw new RuntimeException('Type de groupe comptable invalide.');
    $idempotencyKey = accounting_uuid($data['idempotency_key'] ?? null);
    $orderRef = accounting_optional_text($data['order_ref'] ?? null, 'La référence de commande', 32);
    $directSaleId = array_key_exists('direct_sale_id', $data) && $data['direct_sale_id'] !== ''
        ? accounting_integer($data['direct_sale_id'], 'La vente directe', 1)
        : null;

    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId, $groupType, $idempotencyKey, $orderRef, $directSaleId): array {
        $existing = accounting_find_operation_group_by_key($pdo, $idempotencyKey, true);
        if ($existing) {
            if ($existing['group_type'] !== $groupType
                || (string) ($existing['order_ref'] ?? '') !== (string) ($orderRef ?? '')
                || (int) ($existing['direct_sale_id'] ?? 0) !== (int) ($directSaleId ?? 0)) {
                throw new RuntimeException('Cette clé de confirmation a déjà été utilisée pour une autre opération. Rechargez la page.');
            }
            return ['group' => $existing, 'replayed' => true];
        }

        if ($directSaleId !== null) {
            $sale = $pdo->prepare('SELECT id FROM direct_sales WHERE id = ? FOR UPDATE');
            $sale->execute([$directSaleId]);
            if (!$sale->fetchColumn()) throw new RuntimeException('Vente directe introuvable.');
        }

        $reference = accounting_public_reference('COM');
        $insert = $pdo->prepare(
            'INSERT INTO accounting_operation_groups (public_reference, group_type, idempotency_key, order_ref, direct_sale_id, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        try {
            $insert->execute([$reference, $groupType, $idempotencyKey, $orderRef, $directSaleId, $userId ?? accounting_current_user_id()]);
        } catch (PDOException $exception) {
            $race = accounting_find_operation_group_by_key($pdo, $idempotencyKey, true);
            if ($race) return ['group' => $race, 'replayed' => true];
            throw $exception;
        }
        $group = accounting_find_operation_group($pdo, (int) $pdo->lastInsertId());
        accounting_audit($pdo, 'create', 'operation_group', (int) $group['id'], null, $group, $userId);
        return ['group' => $group, 'replayed' => false];
    });
}

function accounting_find_operation(PDO $pdo, int $operationId, bool $lock = false): array {
    if ($operationId < 1) throw new RuntimeException('Opération invalide.');
    $statement = $pdo->prepare(
        'SELECT o.*, g.public_reference AS group_reference, g.group_type, g.order_ref
         FROM accounting_operations o
         INNER JOIN accounting_operation_groups g ON g.id = o.group_id
         WHERE o.id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$operationId]);
    $operation = $statement->fetch();
    if (!$operation) throw new RuntimeException('Opération introuvable.');
    return $operation;
}

function accounting_operation_reference(): string {
    return accounting_public_reference('OPE');
}

function accounting_validate_operation_payload(PDO $pdo, array $data, bool $lock = false): array {
    $groupId = accounting_integer($data['group_id'] ?? null, 'Le groupe comptable', 1);
    $group = accounting_find_operation_group($pdo, $groupId, $lock);
    $nature = (string) ($data['nature'] ?? '');
    if (!in_array($nature, accounting_operation_natures(), true)) {
        throw new RuntimeException('Nature d’opération invalide.');
    }
    $sourceType = (string) ($data['source_type'] ?? '');
    if (!in_array($sourceType, accounting_source_types(), true)) throw new RuntimeException('Source d’opération invalide.');
    $accountId = accounting_integer($data['account_id'] ?? null, 'Le compte', 1);
    $destinationAccountId = array_key_exists('destination_account_id', $data) && $data['destination_account_id'] !== ''
        ? accounting_integer($data['destination_account_id'], 'Le compte destinataire', 1)
        : null;
    $categoryId = array_key_exists('category_id', $data) && $data['category_id'] !== ''
        ? accounting_integer($data['category_id'], 'La catégorie', 1)
        : null;
    $category = $categoryId !== null ? accounting_require_active_category($pdo, $categoryId, $lock) : null;
    $amount = accounting_integer($data['amount_fcfa'] ?? null, 'Le montant', 1);

    if ($nature === 'transfer') {
        if ($sourceType !== 'transfer') throw new RuntimeException('Un transfert doit utiliser la source transfert.');
        if ($destinationAccountId === null || $destinationAccountId === $accountId) throw new RuntimeException('Choisissez un compte destinataire différent.');
        $accountIds = [$accountId, $destinationAccountId];
        sort($accountIds, SORT_NUMERIC);
        $accounts = [];
        foreach ($accountIds as $id) $accounts[$id] = accounting_require_active_account($pdo, $id, $lock);
        $account = $accounts[$accountId];
        if ($categoryId !== null) throw new RuntimeException('Un transfert principal ne reçoit pas de catégorie analytique.');
    } else {
        $account = accounting_require_active_account($pdo, $accountId, $lock);
        if ($sourceType === 'transfer') throw new RuntimeException('La source transfert est réservée aux transferts.');
        if ($destinationAccountId !== null) throw new RuntimeException('Seul un transfert peut avoir un compte destinataire.');
        if ($category === null) throw new RuntimeException('Choisissez une catégorie comptable.');
        if ($nature === 'receipt' && !in_array($category['direction'], ['receipt', 'both'], true)) {
            throw new RuntimeException('Cette catégorie ne peut pas être utilisée pour un encaissement.');
        }
        if ($nature === 'disbursement' && !in_array($category['direction'], ['disbursement', 'both'], true)) {
            throw new RuntimeException('Cette catégorie ne peut pas être utilisée pour un décaissement.');
        }
    }

    return [
        'group' => $group,
        'nature' => $nature,
        'source_type' => $sourceType,
        'account' => $account,
        'account_id' => $accountId,
        'destination_account_id' => $destinationAccountId,
        'category' => $category,
        'category_id' => $categoryId,
        'amount_fcfa' => $amount,
        'effective_at' => accounting_effective_at($data['effective_at'] ?? '', 'La date d’effet'),
        'label' => accounting_non_empty_text($data['label'] ?? '', 'Le libellé', 180),
        'counterparty' => accounting_optional_text($data['counterparty'] ?? null, 'La contrepartie', 180),
        'payment_reference' => accounting_optional_text($data['payment_reference'] ?? null, 'La référence de paiement', 120),
        'note' => accounting_optional_text($data['note'] ?? null, 'La note', 5000),
    ];
}

function accounting_create_draft_operation(PDO $pdo, array $data, ?int $userId = null): array {
    return accounting_with_transaction($pdo, function () use ($pdo, $data, $userId): array {
        $validated = accounting_validate_operation_payload($pdo, $data, true);
        $insert = $pdo->prepare(
            'INSERT INTO accounting_operations
             (group_id, reference, nature, status, account_id, destination_account_id, category_id, source_type, amount_fcfa, effective_at, label, counterparty, payment_reference, note, created_by_user_id)
             VALUES (?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $validated['group']['id'],
            accounting_operation_reference(),
            $validated['nature'],
            $validated['account_id'],
            $validated['destination_account_id'],
            $validated['category_id'],
            $validated['source_type'],
            $validated['amount_fcfa'],
            $validated['effective_at'],
            $validated['label'],
            $validated['counterparty'],
            $validated['payment_reference'],
            $validated['note'],
            $userId ?? accounting_current_user_id(),
        ]);
        $operation = accounting_find_operation($pdo, (int) $pdo->lastInsertId());
        accounting_audit($pdo, 'create_draft', 'operation', (int) $operation['id'], null, $operation, $userId);
        return $operation;
    });
}

function accounting_confirm_operation(PDO $pdo, int $operationId, ?int $userId = null): array {
    return accounting_with_transaction($pdo, function () use ($pdo, $operationId, $userId): array {
        $operation = accounting_find_operation($pdo, $operationId, true);
        if ($operation['status'] === 'confirmed') return ['operation' => $operation, 'replayed' => true];
        $accountIds = [(int) $operation['account_id']];
        if ($operation['destination_account_id'] !== null) $accountIds[] = (int) $operation['destination_account_id'];
        sort($accountIds, SORT_NUMERIC);
        foreach ($accountIds as $accountId) accounting_require_active_account($pdo, $accountId, true);
        if ($operation['category_id'] !== null) accounting_require_active_category($pdo, (int) $operation['category_id'], true);

        $allocationTotal = accounting_operation_allocation_total($pdo, $operationId);
        if ($operation['nature'] === 'transfer') {
            if ($allocationTotal !== 0) throw new RuntimeException('Un transfert ne peut pas avoir de ventilation analytique.');
        } elseif ($allocationTotal !== (int) $operation['amount_fcfa']) {
            throw new RuntimeException('La somme des ventilations doit être exactement égale au montant avant confirmation.');
        }

        $update = $pdo->prepare(
            'UPDATE accounting_operations SET status = "confirmed", confirmed_by_user_id = ?, confirmed_at = ? WHERE id = ? AND status = "draft"'
        );
        $update->execute([$userId ?? accounting_current_user_id(), (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d H:i:s'), $operationId]);
        $next = accounting_find_operation($pdo, $operationId);
        accounting_audit($pdo, 'confirm', 'operation', $operationId, $operation, $next, $userId);
        return ['operation' => $next, 'replayed' => false];
    });
}

function accounting_reverse_operation(PDO $pdo, int $operationId, string $idempotencyKey, mixed $effectiveAt, mixed $reason, ?int $userId = null): array {
    $idempotencyKey = accounting_uuid($idempotencyKey);
    $effectiveAt = accounting_effective_at($effectiveAt, 'La date de contrepassation');
    $reason = accounting_non_empty_text($reason, 'Le motif de contrepassation', 1000);

    return accounting_with_transaction($pdo, function () use ($pdo, $operationId, $idempotencyKey, $effectiveAt, $reason, $userId): array {
        $original = accounting_find_operation($pdo, $operationId, true);
        if ($original['status'] !== 'confirmed') throw new RuntimeException('Seule une opération confirmée peut être contrepassée.');
        $existing = $pdo->prepare('SELECT id, group_id FROM accounting_operations WHERE reversal_of_id = ? FOR UPDATE');
        $existing->execute([$operationId]);
        $existingReversal = $existing->fetch();
        if ($existingReversal) {
            $group = accounting_find_operation_group($pdo, (int) $existingReversal['group_id']);
            if ($group['idempotency_key'] === $idempotencyKey) {
                return ['operation' => accounting_find_operation($pdo, (int) $existingReversal['id']), 'replayed' => true];
            }
            throw new RuntimeException('Cette opération a déjà été contrepassée.');
        }

        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'reversal',
            'idempotency_key' => $idempotencyKey,
            'order_ref' => $original['order_ref'],
        ], $userId);
        if ($groupResult['replayed']) {
            $byGroup = $pdo->prepare('SELECT id FROM accounting_operations WHERE group_id = ? AND reversal_of_id = ?');
            $byGroup->execute([(int) $groupResult['group']['id'], $operationId]);
            $replayedId = (int) $byGroup->fetchColumn();
            if ($replayedId > 0) return ['operation' => accounting_find_operation($pdo, $replayedId), 'replayed' => true];
            throw new RuntimeException('Cette clé de confirmation est incomplète. Rechargez la page.');
        }

        $nature = match ($original['nature']) {
            'receipt' => 'disbursement',
            'disbursement' => 'receipt',
            'transfer' => 'transfer',
            default => throw new RuntimeException('Cette nature ne peut pas être contrepassée automatiquement.'),
        };
        $accountId = $original['nature'] === 'transfer' ? (int) $original['destination_account_id'] : (int) $original['account_id'];
        $destinationAccountId = $original['nature'] === 'transfer' ? (int) $original['account_id'] : null;
        $label = mb_substr('Contrepassation · ' . $original['label'], 0, 180);
        $insert = $pdo->prepare(
            'INSERT INTO accounting_operations
             (group_id, reference, nature, status, account_id, destination_account_id, category_id, source_type, amount_fcfa, effective_at, label, counterparty, payment_reference, note, reversal_of_id, created_by_user_id, confirmed_by_user_id, confirmed_at)
             VALUES (?, ?, ?, "confirmed", ?, ?, ?, "reversal", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d H:i:s');
        $insert->execute([
            $groupResult['group']['id'], accounting_operation_reference(), $nature, $accountId, $destinationAccountId,
            $original['category_id'], $original['amount_fcfa'], $effectiveAt, $label, $original['counterparty'],
            $original['payment_reference'], $reason, $operationId, $userId ?? accounting_current_user_id(), $userId ?? accounting_current_user_id(), $now,
        ]);
        $reversalId = (int) $pdo->lastInsertId();
        $allocations = $pdo->prepare('SELECT * FROM accounting_allocations WHERE operation_id = ? ORDER BY id');
        $allocations->execute([$operationId]);
        $copy = $pdo->prepare(
            'INSERT INTO accounting_allocations
             (operation_id, category_id, treatment, scope, product_id, order_id, direct_sale_item_id, amount_fcfa, effect_sign, quantity_equivalent, unit_cost_snapshot_fcfa, cogs_amount_fcfa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($allocations->fetchAll() as $allocation) {
            $copy->execute([
                $reversalId, $allocation['category_id'], $allocation['treatment'], $allocation['scope'], $allocation['product_id'],
                $allocation['order_id'], $allocation['direct_sale_item_id'], $allocation['amount_fcfa'], -((int) $allocation['effect_sign']),
                $allocation['quantity_equivalent'], $allocation['unit_cost_snapshot_fcfa'], $allocation['cogs_amount_fcfa'],
            ]);
        }
        $reversal = accounting_find_operation($pdo, $reversalId);
        accounting_audit($pdo, 'reverse', 'operation', $operationId, $original, $reversal, $userId);
        accounting_audit($pdo, 'create_reversal', 'operation', $reversalId, null, $reversal, $userId);
        return ['operation' => $reversal, 'replayed' => false];
    });
}

/**
 * Correct the business date of a confirmed order receipt without rewriting it.
 * The original is reversed on its own date and an exact replacement (including
 * revenue and historical COGS allocations) is issued on the corrected date.
 */
function accounting_reissue_order_receipt_date(
    PDO $pdo,
    int $operationId,
    string $reversalIdempotencyKey,
    string $replacementIdempotencyKey,
    mixed $effectiveAt,
    mixed $reason,
    ?int $userId = null,
): array {
    ensure_accounting_schema();
    $reversalIdempotencyKey = accounting_uuid($reversalIdempotencyKey, 'La clé de contrepassation');
    $replacementIdempotencyKey = accounting_uuid($replacementIdempotencyKey, 'La clé de remplacement');
    if ($reversalIdempotencyKey === $replacementIdempotencyKey) {
        throw new RuntimeException('Les deux clés de correction doivent être différentes. Rechargez la page.');
    }
    $correctedAt = accounting_effective_at($effectiveAt, 'La date corrigée');
    $reason = accounting_non_empty_text($reason, 'Le motif de correction', 1000);

    return accounting_with_transaction($pdo, function () use (
        $pdo,
        $operationId,
        $reversalIdempotencyKey,
        $replacementIdempotencyKey,
        $correctedAt,
        $reason,
        $userId,
    ): array {
        $original = accounting_find_operation($pdo, $operationId, true);
        if ($original['status'] !== 'confirmed'
            || $original['nature'] !== 'receipt'
            || $original['source_type'] !== 'order'
            || empty($original['order_ref'])
            || $original['reversal_of_id'] !== null) {
            throw new RuntimeException('Seul un encaissement de commande confirmé peut être réémis à une autre date.');
        }
        if ($correctedAt === (string) $original['effective_at']) {
            throw new RuntimeException('La nouvelle date est identique à la date actuelle.');
        }

        $existingGroup = accounting_find_operation_group_by_key($pdo, $replacementIdempotencyKey, true);
        if ($existingGroup) {
            if ($existingGroup['group_type'] !== 'manual'
                || (string) ($existingGroup['order_ref'] ?? '') !== (string) $original['order_ref']) {
                throw new RuntimeException('Cette clé de remplacement est déjà utilisée pour une autre correction.');
            }
            $existingOperation = $pdo->prepare('SELECT id FROM accounting_operations WHERE group_id = ? ORDER BY id ASC LIMIT 1');
            $existingOperation->execute([(int) $existingGroup['id']]);
            $existingId = (int) $existingOperation->fetchColumn();
            if ($existingId < 1) throw new RuntimeException('Cette correction est incomplète. Rechargez la page.');
            return [
                'original' => $original,
                'reversal' => null,
                'replacement' => accounting_find_operation($pdo, $existingId),
                'replayed' => true,
            ];
        }

        $reversalResult = accounting_reverse_operation(
            $pdo,
            $operationId,
            $reversalIdempotencyKey,
            $original['effective_at'],
            'Correction de date · ' . $reason,
            $userId,
        );
        $groupResult = accounting_create_operation_group($pdo, [
            'group_type' => 'manual',
            'idempotency_key' => $replacementIdempotencyKey,
            'order_ref' => $original['order_ref'],
        ], $userId);
        if ($groupResult['replayed']) {
            throw new RuntimeException('Cette clé de remplacement est incomplète. Rechargez la page.');
        }

        $replacement = accounting_create_draft_operation($pdo, [
            'group_id' => $groupResult['group']['id'],
            'nature' => 'receipt',
            'account_id' => $original['account_id'],
            'category_id' => $original['category_id'],
            'source_type' => 'order',
            'amount_fcfa' => $original['amount_fcfa'],
            'effective_at' => $correctedAt,
            'label' => $original['label'],
            'counterparty' => $original['counterparty'],
            'payment_reference' => $original['payment_reference'],
            'note' => mb_substr('Date corrigée depuis le ' . date('d/m/Y H:i', strtotime((string) $original['effective_at'])) . ' · ' . $reason, 0, 5000),
        ], $userId);

        $allocations = $pdo->prepare('SELECT * FROM accounting_allocations WHERE operation_id = ? ORDER BY id ASC');
        $allocations->execute([$operationId]);
        $copy = $pdo->prepare(
            'INSERT INTO accounting_allocations
             (operation_id, category_id, treatment, scope, product_id, order_id, direct_sale_item_id, amount_fcfa, effect_sign, quantity_equivalent, unit_cost_snapshot_fcfa, cogs_amount_fcfa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($allocations->fetchAll() as $allocation) {
            $copy->execute([
                $replacement['id'], $allocation['category_id'], $allocation['treatment'], $allocation['scope'],
                $allocation['product_id'], $allocation['order_id'], $allocation['direct_sale_item_id'],
                $allocation['amount_fcfa'], $allocation['effect_sign'], $allocation['quantity_equivalent'],
                $allocation['unit_cost_snapshot_fcfa'], $allocation['cogs_amount_fcfa'],
            ]);
        }
        $replacement = accounting_confirm_operation($pdo, (int) $replacement['id'], $userId)['operation'];
        accounting_audit($pdo, 'reissue_order_receipt_date', 'operation', $operationId, $original, [
            'reversal_id' => (int) $reversalResult['operation']['id'],
            'replacement_id' => (int) $replacement['id'],
            'corrected_effective_at' => $correctedAt,
            'reason' => $reason,
        ], $userId);

        return [
            'original' => $original,
            'reversal' => $reversalResult['operation'],
            'replacement' => $replacement,
            'replayed' => false,
        ];
    });
}

function accounting_operation_effect_fcfa(array $operation, int $accountId): int {
    $amount = accounting_integer($operation['amount_fcfa'] ?? null, 'Le montant de l’opération', 1);
    $nature = (string) ($operation['nature'] ?? '');
    if ($nature === 'receipt' && (int) $operation['account_id'] === $accountId) return $amount;
    if ($nature === 'disbursement' && (int) $operation['account_id'] === $accountId) return -$amount;
    if ($nature === 'transfer') {
        if ((int) $operation['account_id'] === $accountId) return -$amount;
        if ((int) ($operation['destination_account_id'] ?? 0) === $accountId) return $amount;
    }
    return 0;
}

function accounting_payment_state(int $saleTotalFcfa, int $receivedFcfa, int $refundedFcfa): string {
    if ($saleTotalFcfa < 0 || $receivedFcfa < 0 || $refundedFcfa < 0) throw new RuntimeException('Les totaux de paiement sont invalides.');
    $net = $receivedFcfa - $refundedFcfa;
    if ($receivedFcfa > 0 && $net <= 0) return 'remboursée';
    if ($net <= 0) return 'non encaissée';
    if ($net < $saleTotalFcfa) return 'partiellement encaissée';
    if ($net === $saleTotalFcfa) return 'encaissée';
    return 'sur-encaissée à régulariser';
}
