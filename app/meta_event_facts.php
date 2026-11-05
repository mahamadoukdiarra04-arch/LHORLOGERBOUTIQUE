<?php
declare(strict_types=1);

function meta_capi_timestamp(string $date): int {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date) throw new RuntimeException('Date métier invalide.');
    return $parsed->getTimestamp();
}

function meta_capi_is_recent(string $date, ?int $now = null): bool {
    $time = meta_capi_timestamp($date); $now ??= time();
    return $time <= $now && $time > $now - 7 * 86400;
}

/** Receipts are effective-date ordered and already exclude reversed operations. */
function meta_capi_purchase_from_rows(array $lines, array $receipts): ?array {
    if ($lines === []) return null;
    $total = 0; $due = []; $paid = []; $delivery = '';
    foreach ($lines as $line) {
        if ($line['status'] !== 'Livrée' || empty($line['delivered_at'])) return null;
        meta_capi_timestamp($line['delivered_at']);
        $delivery = max($delivery, $line['delivered_at']);
        $due[(int) $line['id']] = (int) $line['quantity'] * (int) $line['unit_price_fcfa'];
        $total += $due[(int) $line['id']];
    }
    $settledAt = null;
    foreach ($receipts as $receipt) {
        $id = (int) $receipt['order_id'];
        if (!isset($due[$id])) return null;
        $paid[$id] = ($paid[$id] ?? 0) + (int) $receipt['amount_fcfa'];
        $complete = true;
        foreach ($due as $lineId => $amount) if (($paid[$lineId] ?? 0) < $amount) $complete = false;
        if ($complete && $settledAt === null) $settledAt = $receipt['effective_at'];
    }
    // Ambiguous overpayments/partial payments are not turned into purchases.
    foreach ($due as $id => $amount) if (($paid[$id] ?? 0) !== $amount) return null;
    if (!$settledAt || $total < 1) return null;
    meta_capi_timestamp($settledAt);
    return ['occurred_at' => max($delivery, $settledAt), 'value_fcfa' => $total];
}

function meta_capi_event_facts(PDO $pdo, string $ref, string $key): ?array {
    $query = $pdo->prepare('SELECT id, status, created_at, delivered_at, quantity, unit_price_fcfa FROM orders WHERE order_ref = ? ORDER BY id');
    $query->execute([$ref]); $lines = $query->fetchAll();
    if ($lines === []) return null;
    if ($key === 'lead') return ['occurred_at' => min(array_column($lines, 'created_at')), 'value_fcfa' => null];
    if ($key === 'confirmed') {
        $query = $pdo->prepare("SELECT MIN(d.occurred_at) FROM (
            SELECT e.created_at AS occurred_at FROM closer_events e JOIN orders o ON o.id = e.order_id
            WHERE o.order_ref = ? AND e.event_type = 'Confirmée'
            UNION ALL SELECT e.created_at FROM admin_events e JOIN orders o ON o.id = e.order_id
            WHERE o.order_ref = ? AND e.event_type = 'commande'
            AND (e.message LIKE 'Confirmée par %' OR e.message LIKE '%mise à jour : Confirmée')
        ) d");
        $query->execute([$ref, $ref]); $date = $query->fetchColumn();
        return $date ? ['occurred_at' => $date, 'value_fcfa' => null] : null;
    }
    if ($key !== 'purchase') return null;
    // Resolve by order-line allocations, including corrected receipt groups.
    $query = $pdo->prepare("SELECT a.order_id, a.effect_sign * CAST(a.amount_fcfa AS SIGNED) AS amount_fcfa, op.effective_at
        FROM accounting_allocations a JOIN accounting_operations op ON op.id = a.operation_id
        JOIN orders o ON o.id = a.order_id
        WHERE o.order_ref = ? AND op.status = 'confirmed' AND op.nature = 'receipt'
        AND op.reversal_of_id IS NULL AND a.treatment = 'product_revenue' AND a.amount_fcfa > 0
        AND NOT EXISTS (SELECT 1 FROM accounting_operations r WHERE r.reversal_of_id = op.id AND r.status = 'confirmed')
        ORDER BY op.effective_at, op.id, a.id");
    $query->execute([$ref]); $receipts = $query->fetchAll();
    $refund = $pdo->prepare("SELECT COUNT(*) FROM accounting_allocations a JOIN accounting_operations op ON op.id = a.operation_id
        JOIN orders o ON o.id = a.order_id WHERE o.order_ref = ? AND op.status = 'confirmed'
        AND a.treatment = 'product_refund' AND a.amount_fcfa > 0 AND op.reversal_of_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM accounting_operations r WHERE r.reversal_of_id = op.id AND r.status = 'confirmed')");
    $refund->execute([$ref]);
    if ((int) $refund->fetchColumn() > 0) return null;
    return meta_capi_purchase_from_rows($lines, $receipts);
}

function meta_capi_purchase_audit(PDO $pdo): array {
    $refs = $pdo->query("SELECT DISTINCT order_ref FROM orders WHERE status = 'Livrée' AND delivered_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
        UNION SELECT DISTINCT o.order_ref FROM orders o JOIN accounting_allocations a ON a.order_id = o.id
        JOIN accounting_operations op ON op.id = a.operation_id
        WHERE o.status = 'Livrée' AND op.effective_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) AND op.status = 'confirmed'")->fetchAll(PDO::FETCH_COLUMN);
    $result = [];
    $existing = $pdo->prepare("SELECT status FROM meta_capi_events WHERE order_ref = ? AND event_key = 'purchase'");
    foreach ($refs as $ref) {
        $facts = meta_capi_event_facts($pdo, $ref, 'purchase');
        if (!$facts || !meta_capi_is_recent($facts['occurred_at'])) continue;
        $existing->execute([$ref]);
        $result[] = ['order_ref' => $ref, 'status' => $existing->fetchColumn() ?: 'missing'] + $facts;
    }
    return $result;
}

function meta_capi_repair_unsent_dates(PDO $pdo): int {
    $rows = $pdo->query("SELECT * FROM meta_capi_events WHERE status <> 'sent' ORDER BY id")->fetchAll();
    $updated = 0;
    foreach ($rows as $row) {
        $facts = meta_capi_event_facts($pdo, $row['order_ref'], $row['event_key']);
        $status = !$facts ? 'unverified' : (meta_capi_is_recent($facts['occurred_at']) ? 'pending' : 'expired');
        $date = $facts['occurred_at'] ?? $row['occurred_at'];
        $value = $facts['value_fcfa'] ?? $row['value_fcfa'];
        if ($date !== $row['occurred_at'] || $status !== $row['status'] || $value !== $row['value_fcfa']) {
            $pdo->prepare("UPDATE meta_capi_events SET occurred_at = ?, value_fcfa = ?, status = ?, last_error = ? WHERE id = ? AND status <> 'sent'")
                ->execute([$date, $value, $status, $status === 'expired' ? 'Date réelle hors délai de 7 jours.' : ($status === 'unverified' ? 'Date métier ou encaissement non vérifiable.' : null), $row['id']]);
            log_event('meta_date', 'Signal ' . $row['event_id'] . ' : ' . $row['occurred_at'] . ' → ' . $date . ' · ' . $status);
            $updated++;
        }
    }
    return $updated;
}
