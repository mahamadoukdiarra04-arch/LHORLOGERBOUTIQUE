<?php
declare(strict_types=1);

function accounting_period_bounds(mixed $start, mixed $end): array {
    $startAt = accounting_effective_at((string) $start, 'La date de début');
    $endAt = accounting_effective_at((string) $end, 'La date de fin');
    if ($endAt < $startAt) throw new RuntimeException('La date de fin doit être postérieure à la date de début.');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $end))) $endAt = substr($endAt, 0, 10) . ' 23:59:59';
    return [$startAt, $endAt];
}

function accounting_account_balances(PDO $pdo, ?string $asOf = null, bool $activeOnly = false): array {
    $asOf = $asOf === null
        ? (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d H:i:s')
        : accounting_effective_at($asOf, 'La date de consultation');
    $sql =
        'SELECT a.id, a.code, a.name, a.account_type, a.opening_balance_fcfa, a.opening_at, a.description, a.is_active,
                COALESCE(SUM(CASE
                    WHEN o.nature = "receipt" AND o.account_id = a.id THEN o.amount_fcfa
                    WHEN o.nature = "disbursement" AND o.account_id = a.id THEN -o.amount_fcfa
                    WHEN o.nature = "transfer" AND o.account_id = a.id THEN -o.amount_fcfa
                    WHEN o.nature = "transfer" AND o.destination_account_id = a.id THEN o.amount_fcfa
                    ELSE 0
                END), 0) AS movement_balance_fcfa
         FROM accounting_accounts a
         LEFT JOIN accounting_operations o ON o.status = "confirmed"
             AND o.effective_at <= ?
             AND o.effective_at >= a.opening_at
             AND (o.account_id = a.id OR o.destination_account_id = a.id)';
    if ($activeOnly) $sql .= ' WHERE a.is_active = 1';
    $sql .= ' GROUP BY a.id ORDER BY a.is_active DESC, a.account_type ASC, a.name ASC, a.id ASC';
    $statement = $pdo->prepare($sql);
    $statement->execute([$asOf]);

    $accounts = [];
    foreach ($statement->fetchAll() as $account) {
        $opening = accounting_integer((string) $account['opening_balance_fcfa'], 'Le solde d’ouverture', PHP_INT_MIN);
        $movement = accounting_integer((string) $account['movement_balance_fcfa'], 'Le solde des mouvements', PHP_INT_MIN);
        $opened = $account['opening_at'] <= $asOf;
        if ($opened && (($movement > 0 && $opening > PHP_INT_MAX - $movement) || ($movement < 0 && $opening < PHP_INT_MIN - $movement))) {
            throw new RuntimeException('Le solde du compte est hors limite.');
        }
        $balance = $opened ? $opening + $movement : 0;
        $account['opening_balance_fcfa'] = $opening;
        $account['movement_balance_fcfa'] = $movement;
        $account['balance_fcfa'] = $balance;
        $account['is_opened_at_date'] = $opened;
        $accounts[] = $account;
    }
    return $accounts;
}

function accounting_account_balance(PDO $pdo, int $accountId, ?string $asOf = null): int {
    foreach (accounting_account_balances($pdo, $asOf) as $account) {
        if ((int) $account['id'] === $accountId) return (int) $account['balance_fcfa'];
    }
    throw new RuntimeException('Compte introuvable.');
}

function accounting_treasury_total(PDO $pdo, ?string $asOf = null): int {
    $total = 0;
    foreach (accounting_account_balances($pdo, $asOf) as $account) {
        $balance = (int) $account['balance_fcfa'];
        if (($balance > 0 && $total > PHP_INT_MAX - $balance) || ($balance < 0 && $total < PHP_INT_MIN - $balance)) {
            throw new RuntimeException('Le solde de trésorerie est hors limite.');
        }
        $total += $balance;
    }
    return $total;
}

function accounting_order_payment_summary(PDO $pdo, string $orderRef): array {
    $orderRef = accounting_non_empty_text($orderRef, 'La référence de commande', 32);
    $totalStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(quantity * unit_price_fcfa), 0) FROM orders WHERE order_ref = ?'
    );
    $totalStatement->execute([$orderRef]);
    $total = accounting_integer((string) $totalStatement->fetchColumn(), 'Le total de commande', 0);
    if ($total === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM orders WHERE order_ref = ? LIMIT 1');
        $exists->execute([$orderRef]);
        if (!$exists->fetchColumn()) throw new RuntimeException('Référence de commande introuvable.');
    }

    $payments = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN o.nature = "receipt" THEN o.amount_fcfa ELSE 0 END), 0) AS received_fcfa,
            COALESCE(SUM(CASE WHEN o.nature = "disbursement" THEN o.amount_fcfa ELSE 0 END), 0) AS refunded_fcfa
         FROM accounting_operation_groups g
         INNER JOIN accounting_operations o ON o.group_id = g.id AND o.status = "confirmed"
         WHERE g.order_ref = ?'
    );
    $payments->execute([$orderRef]);
    $row = $payments->fetch() ?: ['received_fcfa' => 0, 'refunded_fcfa' => 0];
    $received = accounting_integer((string) $row['received_fcfa'], 'Les encaissements', 0);
    $refunded = accounting_integer((string) $row['refunded_fcfa'], 'Les remboursements', 0);
    $net = $received - $refunded;
    $remaining = $net >= $total ? 0 : $total - $net;
    return [
        'order_ref' => $orderRef,
        'sale_total_fcfa' => $total,
        'received_fcfa' => $received,
        'refunded_fcfa' => $refunded,
        'net_paid_fcfa' => $net,
        'remaining_fcfa' => $remaining,
        'payment_state' => accounting_payment_state($total, $received, $refunded),
    ];
}

function accounting_ted_report(PDO $pdo, mixed $start, mixed $end): array {
    [$startAt, $endAt] = accounting_period_bounds($start, $end);
    $statement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN a.treatment = "product_revenue" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS product_revenue_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "product_refund" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS product_refund_fcfa,
            COALESCE(SUM(a.effect_sign * a.cogs_amount_fcfa), 0) AS cogs_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "direct_expense" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS direct_expense_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "shop_revenue" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS shop_revenue_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "shop_refund" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS shop_refund_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "common_expense" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS common_expense_fcfa
         FROM accounting_allocations a
         INNER JOIN accounting_operations o ON o.id = a.operation_id AND o.status = "confirmed"
         WHERE o.effective_at BETWEEN ? AND ?'
    );
    $statement->execute([$startAt, $endAt]);
    $row = $statement->fetch() ?: [];
    $metrics = [];
    foreach ([
        'product_revenue_fcfa', 'product_refund_fcfa', 'cogs_fcfa', 'direct_expense_fcfa',
        'shop_revenue_fcfa', 'shop_refund_fcfa', 'common_expense_fcfa',
    ] as $key) {
        $metrics[$key] = accounting_integer((string) ($row[$key] ?? '0'), 'Un agrégat comptable', PHP_INT_MIN);
    }
    $metrics['net_product_revenue_fcfa'] = $metrics['product_revenue_fcfa'] - $metrics['product_refund_fcfa'];
    $metrics['gross_margin_fcfa'] = $metrics['net_product_revenue_fcfa'] - $metrics['cogs_fcfa'];
    $metrics['product_contribution_fcfa'] = $metrics['gross_margin_fcfa'] - $metrics['direct_expense_fcfa'];
    $metrics['net_shop_revenue_fcfa'] = $metrics['shop_revenue_fcfa'] - $metrics['shop_refund_fcfa'];
    $metrics['shop_result_fcfa'] = $metrics['product_contribution_fcfa'] + $metrics['net_shop_revenue_fcfa'] - $metrics['common_expense_fcfa'];

    $incomplete = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_operations o
         WHERE o.status = "confirmed" AND o.nature <> "transfer" AND o.effective_at BETWEEN ? AND ?
           AND NOT EXISTS (SELECT 1 FROM accounting_allocations a WHERE a.operation_id = o.id)'
    );
    $incomplete->execute([$startAt, $endAt]);
    $metrics['unallocated_operation_count'] = (int) $incomplete->fetchColumn();
    $metrics['is_complete'] = $metrics['unallocated_operation_count'] === 0;
    $metrics['start_at'] = $startAt;
    $metrics['end_at'] = $endAt;
    return $metrics;
}

function accounting_product_results(PDO $pdo, mixed $start, mixed $end): array {
    [$startAt, $endAt] = accounting_period_bounds($start, $end);
    $statement = $pdo->prepare(
        'SELECT a.product_id, p.name AS product_name,
            COALESCE(SUM(CASE WHEN a.treatment = "product_revenue" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS revenue_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "product_refund" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS refund_fcfa,
            COALESCE(SUM(a.effect_sign * a.cogs_amount_fcfa), 0) AS cogs_fcfa,
            COALESCE(SUM(CASE WHEN a.treatment = "direct_expense" THEN a.effect_sign * a.amount_fcfa ELSE 0 END), 0) AS direct_expense_fcfa
         FROM accounting_allocations a
         INNER JOIN accounting_operations o ON o.id = a.operation_id AND o.status = "confirmed"
         LEFT JOIN products p ON p.id = a.product_id
         WHERE a.scope = "product" AND o.effective_at BETWEEN ? AND ?
         GROUP BY a.product_id, p.name
         ORDER BY revenue_fcfa DESC, a.product_id ASC'
    );
    $statement->execute([$startAt, $endAt]);
    $results = [];
    foreach ($statement->fetchAll() as $row) {
        foreach (['revenue_fcfa', 'refund_fcfa', 'cogs_fcfa', 'direct_expense_fcfa'] as $key) {
            $row[$key] = accounting_integer((string) $row[$key], 'Un agrégat produit', PHP_INT_MIN);
        }
        $row['net_revenue_fcfa'] = $row['revenue_fcfa'] - $row['refund_fcfa'];
        $row['gross_margin_fcfa'] = $row['net_revenue_fcfa'] - $row['cogs_fcfa'];
        $row['contribution_fcfa'] = $row['gross_margin_fcfa'] - $row['direct_expense_fcfa'];
        $results[] = $row;
    }
    return $results;
}
