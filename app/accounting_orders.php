<?php
declare(strict_types=1);

/**
 * Validate the editable part of a web order before touching MySQL. Customer
 * details belong to the whole reference, while product fields belong to the
 * selected line only.
 */
function accounting_normalize_order_edit_payload(array $data): array {
    $firstName = accounting_non_empty_text($data['customer_first_name'] ?? null, 'Le prénom', 100);
    $lastName = accounting_non_empty_text($data['customer_last_name'] ?? null, 'Le nom', 100);
    $phone = accounting_non_empty_text($data['phone'] ?? null, 'Le téléphone', 32);
    $district = accounting_non_empty_text($data['district'] ?? null, 'Le quartier', 150);
    if (mb_strlen($firstName) < 2 || mb_strlen($lastName) < 2) {
        throw new RuntimeException('Le prénom et le nom doivent contenir au moins 2 caractères.');
    }
    if (mb_strlen($phone) < 7) throw new RuntimeException('Le téléphone doit contenir au moins 7 caractères.');
    if (mb_strlen($district) < 2) throw new RuntimeException('Le quartier doit contenir au moins 2 caractères.');

    $quantity = accounting_integer($data['quantity'] ?? null, 'La quantité', 1);
    if ($quantity > 100) throw new RuntimeException('La quantité ne peut pas dépasser 100 unités.');
    $unitPrice = accounting_integer($data['unit_price_fcfa'] ?? null, 'Le prix unitaire', 1);
    if ($unitPrice > 100000000) throw new RuntimeException('Le prix unitaire est trop élevé.');

    return [
        'customer_first_name' => $firstName,
        'customer_last_name' => $lastName,
        'phone' => $phone,
        'district' => $district,
        'product_id' => accounting_integer($data['product_id'] ?? null, 'Le produit', 1),
        'variant_id' => accounting_integer($data['variant_id'] ?? null, 'Le coloris', 1),
        'quantity' => $quantity,
        'unit_price_fcfa' => $unitPrice,
    ];
}

function accounting_order_edit_catalog(PDO $pdo): array {
    $statement = $pdo->query(
        'SELECT p.id AS product_id, p.name AS product_name, p.price_fcfa,
                pv.id AS variant_id, pv.name AS variant_name,
                COALESCE(SUM(sm.quantity), 0) AS stock_quantity
         FROM products p
         INNER JOIN product_variants pv ON pv.product_id = p.id AND pv.is_active = 1
         LEFT JOIN stock_movements sm ON sm.variant_id = pv.id
         GROUP BY p.id, p.name, p.price_fcfa, pv.id, pv.name
         ORDER BY p.name ASC, pv.name ASC'
    );
    $catalog = [];
    foreach ($statement->fetchAll() as $row) {
        $productId = (int) $row['product_id'];
        if (!isset($catalog[$productId])) {
            $catalog[$productId] = [
                'id' => $productId,
                'name' => (string) $row['product_name'],
                'price_fcfa' => (int) $row['price_fcfa'],
                'variants' => [],
            ];
        }
        $catalog[$productId]['variants'][] = [
            'id' => (int) $row['variant_id'],
            'name' => (string) $row['variant_name'],
            'stock_quantity' => (int) $row['stock_quantity'],
        ];
    }
    return $catalog;
}

function accounting_order_editability(PDO $pdo, string $orderRef): array {
    $orderRef = accounting_non_empty_text($orderRef, 'La référence de commande', 32);
    if (accounting_order_has_confirmed_entries($pdo, $orderRef)) {
        return ['editable' => false, 'reason' => 'Un encaissement comptable existe déjà pour cette référence.'];
    }
    $statement = $pdo->prepare(
        'SELECT status, stock_processed FROM orders WHERE order_ref = ? ORDER BY id ASC'
    );
    $statement->execute([$orderRef]);
    $lines = $statement->fetchAll();
    if ($lines === []) return ['editable' => false, 'reason' => 'Commande introuvable.'];
    foreach ($lines as $line) {
        if ($line['status'] === 'Livrée' || (int) ($line['stock_processed'] ?? 0) === 1) {
            return ['editable' => false, 'reason' => 'La livraison ou la sortie de stock a déjà été enregistrée.'];
        }
    }
    return ['editable' => true, 'reason' => null];
}

function accounting_update_order_before_payment(PDO $pdo, int $orderId, array $data, ?int $userId = null): array {
    ensure_accounting_schema();
    if ($orderId < 1) throw new RuntimeException('Commande invalide.');
    $normalized = accounting_normalize_order_edit_payload($data);

    return accounting_with_transaction($pdo, function () use ($pdo, $orderId, $normalized, $userId): array {
        $selectedStatement = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $selectedStatement->execute([$orderId]);
        $selected = $selectedStatement->fetch();
        if (!$selected) throw new RuntimeException('Commande introuvable.');
        $orderRef = (string) $selected['order_ref'];

        $referenceStatement = $pdo->prepare(
            'SELECT id, status, stock_processed FROM orders WHERE order_ref = ? ORDER BY id ASC FOR UPDATE'
        );
        $referenceStatement->execute([$orderRef]);
        foreach ($referenceStatement->fetchAll() as $line) {
            if ($line['status'] === 'Livrée' || (int) ($line['stock_processed'] ?? 0) === 1) {
                throw new RuntimeException('Cette référence a déjà été livrée et ne peut plus être modifiée.');
            }
        }
        if (accounting_order_has_confirmed_entries($pdo, $orderRef)) {
            throw new RuntimeException('Cette référence possède déjà un encaissement comptable et ne peut plus être modifiée.');
        }

        $variantStatement = $pdo->prepare(
            'SELECT p.id AS product_id, p.name AS product_name, pv.id AS variant_id, pv.name AS variant_name
             FROM products p
             INNER JOIN product_variants pv ON pv.product_id = p.id AND pv.is_active = 1
             WHERE p.id = ? AND pv.id = ?
             LIMIT 1'
        );
        $variantStatement->execute([$normalized['product_id'], $normalized['variant_id']]);
        $selection = $variantStatement->fetch();
        if (!$selection) throw new RuntimeException('Choisissez un produit et un coloris compatibles.');

        $customerUpdate = $pdo->prepare(
            'UPDATE orders
             SET customer_first_name = ?, customer_last_name = ?, phone = ?, district = ?
             WHERE order_ref = ?'
        );
        $customerUpdate->execute([
            $normalized['customer_first_name'],
            $normalized['customer_last_name'],
            $normalized['phone'],
            $normalized['district'],
            $orderRef,
        ]);

        $lineUpdate = $pdo->prepare(
            'UPDATE orders
             SET product_id = ?, variant_id = ?, product_name = ?, variant = ?, quantity = ?, unit_price_fcfa = ?
             WHERE id = ?'
        );
        $lineUpdate->execute([
            (int) $selection['product_id'],
            (int) $selection['variant_id'],
            (string) $selection['product_name'],
            (string) $selection['variant_name'],
            $normalized['quantity'],
            $normalized['unit_price_fcfa'],
            $orderId,
        ]);

        $nextStatement = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $nextStatement->execute([$orderId]);
        $next = $nextStatement->fetch();
        accounting_audit($pdo, 'update_order_before_payment', 'order', $orderId, $selected, $next ?: null, $userId);

        $event = $pdo->prepare(
            'INSERT INTO admin_events (event_type, message, product_id, order_id, actor)
             VALUES ("commande", ?, ?, ?, ?)'
        );
        $event->execute([
            'Commande ' . $orderRef . ' modifiée avant encaissement',
            (int) $selection['product_id'],
            $orderId,
            function_exists('admin_identity') ? admin_identity() : null,
        ]);

        return ['order_ref' => $orderRef, 'order' => $next];
    });
}
