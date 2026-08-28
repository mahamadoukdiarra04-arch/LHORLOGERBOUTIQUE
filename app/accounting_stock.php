<?php
declare(strict_types=1);

function accounting_stock_lock_products(PDO $pdo, array $productIds): array {
    $ids = [];
    foreach ($productIds as $productId) {
        $id = accounting_integer($productId, 'Le produit', 1);
        $ids[$id] = $id;
    }
    if ($ids === []) throw new RuntimeException('Aucun produit à verrouiller.');
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare('SELECT id, name FROM products WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
    $statement->execute($ids);
    $products = [];
    foreach ($statement->fetchAll() as $product) $products[(int) $product['id']] = $product;
    if (count($products) !== count($ids)) throw new RuntimeException('Un produit de la commande est introuvable.');
    return $products;
}

function accounting_stock_lock_variant(PDO $pdo, int $productId, int $variantId): array {
    $statement = $pdo->prepare(
        'SELECT id, product_id, name FROM product_variants WHERE id = ? AND product_id = ? AND is_active = 1 FOR UPDATE'
    );
    $statement->execute([$variantId, $productId]);
    $variant = $statement->fetch();
    if (!$variant) throw new RuntimeException('Le coloris sélectionné ne correspond pas à ce modèle.');
    return $variant;
}

function accounting_stock_variant_id_for_name(PDO $pdo, int $productId, string $variantName): ?int {
    $statement = $pdo->prepare(
        'SELECT id FROM product_variants WHERE product_id = ? AND name = ? AND is_active = 1 LIMIT 1'
    );
    $statement->execute([$productId, $variantName]);
    $variantId = $statement->fetchColumn();
    return $variantId === false ? null : (int) $variantId;
}

function accounting_stock_available(PDO $pdo, int $productId): int {
    $statement = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE product_id = ?');
    $statement->execute([$productId]);
    return accounting_integer((string) $statement->fetchColumn(), 'Le stock disponible', PHP_INT_MIN);
}

function accounting_stock_unit_cost_snapshot(PDO $pdo, int $productId, ?int $variantId = null): ?int {
    $sql =
        'SELECT COALESCE(SUM(purchase_price_fcfa + COALESCE(transit_price_fcfa, 0)), 0) AS total_cost_fcfa,
                COALESCE(SUM(quantity), 0) AS total_quantity
         FROM stock_movements
         WHERE product_id = ? AND movement_type = "Réassort" AND purchase_price_fcfa IS NOT NULL';
    $params = [$productId];
    if ($variantId !== null) {
        $sql .= ' AND variant_id = ?';
        $params[] = $variantId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch() ?: ['total_cost_fcfa' => '0', 'total_quantity' => '0'];
    $quantity = accounting_integer((string) $row['total_quantity'], 'La quantité réassortie', 0);
    if ($quantity < 1) return null;
    $totalCost = accounting_integer((string) $row['total_cost_fcfa'], 'Le coût de réassort', 0);
    return intdiv($totalCost, $quantity);
}

function accounting_stock_record_movement(PDO $pdo, array $data): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('Un mouvement de stock doit être enregistré dans une transaction.');
    $productId = accounting_integer($data['product_id'] ?? null, 'Le produit', 1);
    $movementType = (string) ($data['movement_type'] ?? '');
    if (!in_array($movementType, ['Réassort', 'Sortie', 'Ajustement'], true)) throw new RuntimeException('Type de mouvement de stock invalide.');
    $quantity = accounting_integer($data['quantity'] ?? null, 'La quantité', 1);
    if ($quantity > 2147483647) throw new RuntimeException('La quantité est trop élevée.');
    $products = accounting_stock_lock_products($pdo, [$productId]);
    $variantId = array_key_exists('variant_id', $data) && $data['variant_id'] !== null && $data['variant_id'] !== ''
        ? accounting_integer($data['variant_id'], 'Le coloris', 1)
        : null;
    $variant = $variantId !== null ? accounting_stock_lock_variant($pdo, $productId, $variantId) : null;

    $orderId = array_key_exists('order_id', $data) && $data['order_id'] !== null
        ? accounting_integer($data['order_id'], 'La ligne de commande', 1)
        : null;
    $directSaleItemId = array_key_exists('direct_sale_item_id', $data) && $data['direct_sale_item_id'] !== null
        ? accounting_integer($data['direct_sale_item_id'], 'La ligne de vente directe', 1)
        : null;
    $groupId = array_key_exists('operation_group_id', $data) && $data['operation_group_id'] !== null
        ? accounting_integer($data['operation_group_id'], 'Le groupe comptable', 1)
        : null;
    if ($orderId !== null && $directSaleItemId !== null) throw new RuntimeException('Un mouvement ne peut pas venir de deux sources de vente.');
    $isSaleSource = $orderId !== null || $directSaleItemId !== null;
    $isSaleReturn = accounting_flag($data['is_sale_return'] ?? '0', 'Le retour de vente') === 1;
    if ($movementType !== 'Sortie' && $isSaleSource && !($movementType === 'Ajustement' && $isSaleReturn)) {
        throw new RuntimeException('Une source de vente ne peut être liée qu’à une sortie ou à un retour physique de stock.');
    }
    if ($movementType === 'Sortie' && $isSaleReturn) {
        throw new RuntimeException('Une sortie de stock ne peut pas être déclarée comme un retour.');
    }
    if ($movementType === 'Ajustement' && $isSaleReturn && !$isSaleSource) {
        throw new RuntimeException('Un retour physique doit être relié à une ligne de vente.');
    }
    if ($isSaleSource && $groupId === null) {
        throw new RuntimeException('Un mouvement issu d’une vente doit être lié à son groupe comptable.');
    }

    $storedQuantity = $quantity;
    $purchase = null;
    $transit = null;
    $unitCost = null;
    $unitCostSnapshot = null;
    $saleUnitPrice = null;
    if ($movementType === 'Réassort') {
        $purchase = accounting_integer($data['purchase_price_fcfa'] ?? null, 'Le prix d’achat', 1);
        $transit = accounting_integer($data['transit_price_fcfa'] ?? 0, 'Le prix de transit', 0);
        if ($purchase > PHP_INT_MAX - $transit) throw new RuntimeException('Le coût du réassort est trop élevé.');
        $unitCost = intdiv($purchase + $transit, $quantity);
    } elseif ($movementType === 'Sortie') {
        $available = accounting_stock_available($pdo, $productId);
        if ($available < $quantity) {
            throw new RuntimeException($products[$productId]['name'] . ' ne dispose que de ' . $available . ' unité(s) pour une sortie de ' . $quantity . '.');
        }
        if ($orderId !== null) {
            $existing = $pdo->prepare('SELECT id FROM stock_movements WHERE order_id = ? AND movement_type = "Sortie" FOR UPDATE');
            $existing->execute([$orderId]);
            if ($existing->fetchColumn()) throw new RuntimeException('La sortie de stock de cette ligne de commande existe déjà.');
        }
        if ($directSaleItemId !== null) {
            $existing = $pdo->prepare('SELECT id FROM stock_movements WHERE direct_sale_item_id = ? AND movement_type = "Sortie" FOR UPDATE');
            $existing->execute([$directSaleItemId]);
            if ($existing->fetchColumn()) throw new RuntimeException('La sortie de stock de cette vente directe existe déjà.');
        }
        $unitCostSnapshot = array_key_exists('unit_cost_snapshot_fcfa', $data) && $data['unit_cost_snapshot_fcfa'] !== null
            ? accounting_integer($data['unit_cost_snapshot_fcfa'], 'Le coût unitaire historique', 0)
            : (accounting_stock_unit_cost_snapshot($pdo, $productId, $variantId) ?? accounting_stock_unit_cost_snapshot($pdo, $productId));
        if ($unitCostSnapshot === null) {
            throw new RuntimeException('Renseignez un réassort avec coût avant de sortir ' . $products[$productId]['name'] . ' du stock.');
        }
        $saleUnitPrice = array_key_exists('sale_unit_price_fcfa', $data) && $data['sale_unit_price_fcfa'] !== null
            ? accounting_integer($data['sale_unit_price_fcfa'], 'Le prix de vente historique', 0)
            : null;
        $storedQuantity = -$quantity;
    } elseif ($movementType === 'Ajustement' && $isSaleReturn) {
        if ($orderId !== null) {
            $source = $pdo->prepare('SELECT id FROM stock_movements WHERE order_id = ? AND movement_type = "Sortie" FOR UPDATE');
            $source->execute([$orderId]);
            if (!$source->fetchColumn()) throw new RuntimeException('La sortie de stock d’origine est introuvable pour ce retour.');
            $existing = $pdo->prepare('SELECT id FROM stock_movements WHERE order_id = ? AND movement_type = "Ajustement" FOR UPDATE');
            $existing->execute([$orderId]);
            if ($existing->fetchColumn()) throw new RuntimeException('Le retour physique de cette ligne de commande existe déjà.');
        }
        if ($directSaleItemId !== null) {
            $source = $pdo->prepare('SELECT id FROM stock_movements WHERE direct_sale_item_id = ? AND movement_type = "Sortie" FOR UPDATE');
            $source->execute([$directSaleItemId]);
            if (!$source->fetchColumn()) throw new RuntimeException('La sortie de stock d’origine est introuvable pour ce retour.');
            $existing = $pdo->prepare('SELECT id FROM stock_movements WHERE direct_sale_item_id = ? AND movement_type = "Ajustement" FOR UPDATE');
            $existing->execute([$directSaleItemId]);
            if ($existing->fetchColumn()) throw new RuntimeException('Le retour physique de cette ligne de vente directe existe déjà.');
        }
        $unitCostSnapshot = accounting_integer($data['unit_cost_snapshot_fcfa'] ?? null, 'Le coût unitaire historique', 0);
        $saleUnitPrice = array_key_exists('sale_unit_price_fcfa', $data) && $data['sale_unit_price_fcfa'] !== null
            ? accounting_integer($data['sale_unit_price_fcfa'], 'Le prix de vente historique', 0)
            : null;
    }

    $note = accounting_optional_text($data['note'] ?? null, 'La note', 255);
    $skipReason = accounting_optional_text($data['skip_reason'] ?? null, 'Le motif', 500);
    $actor = accounting_optional_text($data['actor'] ?? admin_identity(), 'L’auteur', 20);
    $effectiveAt = accounting_effective_at(
        $data['effective_at'] ?? (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d H:i:s'),
        'La date du mouvement de stock'
    );
    $insert = $pdo->prepare(
        'INSERT INTO stock_movements
         (product_id, variant_id, order_id, direct_sale_item_id, operation_group_id, movement_type, quantity, purchase_price_fcfa, transit_price_fcfa, unit_cost_fcfa, unit_cost_snapshot_fcfa, sale_unit_price_fcfa, note, skip_reason, actor, effective_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $productId, $variantId, $orderId, $directSaleItemId, $groupId, $movementType, $storedQuantity, $purchase, $transit,
        $unitCost, $unitCostSnapshot, $saleUnitPrice, $note, $skipReason, $actor, $effectiveAt,
    ]);
    return [
        'id' => (int) $pdo->lastInsertId(),
        'product_id' => $productId,
        'product_name' => $products[$productId]['name'],
        'variant_id' => $variantId,
        'variant_name' => $variant['name'] ?? null,
        'movement_type' => $movementType,
        'quantity' => $storedQuantity,
        'effective_at' => $effectiveAt,
        'unit_cost_snapshot_fcfa' => $unitCostSnapshot,
    ];
}
