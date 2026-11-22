<?php
declare(strict_types=1);

/** The fast UI delegates to the same ledger/stock services as individual sales. */
function accounting_quick_actions(): array {
    return [
        'deliver' => 'Livrer et encaisser',
        'confirm' => 'Confirmer les commandes',
        'dispatch' => 'Passer en livraison',
        'cancel' => 'Annuler les commandes',
        'collect' => 'Encaisser les soldes restants',
    ];
}

function accounting_simple_expense(PDO $pdo, array $data): array {
    $category = accounting_require_active_category($pdo, accounting_integer($data['category_id'] ?? null, 'La catégorie', 1));
    if (!in_array($category['direction'], ['disbursement', 'both'], true) || in_array($category['treatment'], ['product_refund', 'shop_refund'], true)) {
        throw new RuntimeException('Choisissez une catégorie de dépense. Pour un remboursement, utilisez le parcours dédié.');
    }
    $data['scope'] = $category['default_scope'] === 'product' ? 'product' : 'shop';
    $productName = null;
    if ($data['scope'] === 'product') {
        $productId = accounting_integer($data['product_id'] ?? null, 'Le produit concerné', 1);
        $product = $pdo->prepare('SELECT name FROM products WHERE id = ?');
        $product->execute([$productId]);
        $productName = $product->fetchColumn();
        if ($productName === false) throw new RuntimeException('Produit introuvable.');
    } else { $data['product_id'] = null; }
    if (trim((string) ($data['label'] ?? '')) === '') $data['label'] = $category['name'] . ($productName !== null ? ' · ' . $productName : '');
    return $data;
}

function accounting_quick_ids(mixed $input): array {
    if (!is_array($input) || $input === [] || count($input) > 50) {
        throw new RuntimeException('Sélectionnez entre 1 et 50 commandes.');
    }
    $ids = [];
    foreach ($input as $value) {
        if (!is_int($value) && !is_string($value)) throw new RuntimeException('Sélection de commandes invalide.');
        $id = accounting_integer($value, 'La commande sélectionnée', 1);
        $ids[$id] = $id;
    }
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
}

function accounting_quick_fingerprint(array $lines): string {
    $facts = [];
    foreach ($lines as $line) {
        $row = [];
        foreach (['id', 'order_ref', 'product_id', 'variant_id', 'variant', 'quantity', 'unit_price_fcfa', 'status', 'stock_processed', 'acquisition_channel', 'delivered_at', 'customer_first_name', 'customer_last_name', 'phone', 'district'] as $field) {
            $row[$field] = (string) ($line[$field] ?? '');
        }
        $facts[] = $row;
    }
    usort($facts, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
    return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
}

function accounting_quick_child_key(string $batchKey, string $orderRef): string {
    $hex = substr(hash('sha256', $batchKey . ':' . $orderRef), 0, 32);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20);
}

function accounting_quick_validate_lines(array $lines, string $action): void {
    if (!isset(accounting_quick_actions()[$action])) throw new RuntimeException('Action groupée invalide.');
    foreach ($lines as $line) {
        if ($action === 'collect') {
            if ($line['status'] !== 'Livrée') throw new RuntimeException('Les soldes ne concernent que les commandes déjà livrées.');
        } elseif (!in_array($line['status'], ['À confirmer', 'Confirmée', 'En livraison'], true) || (int) $line['stock_processed'] === 1) {
            throw new RuntimeException('Une commande annulée ou déjà livrée ne peut pas être traitée à nouveau dans ce lot.');
        }
    }
}

function accounting_quick_remaining(PDO $pdo, string $orderRef, array $lines): int {
    if (!accounting_order_has_open_payment_exception($pdo, $orderRef)) {
        throw new RuntimeException('Cette commande ne présente pas de solde ouvert à encaisser.');
    }
    $receipts = accounting_order_line_receipts($pdo, $orderRef);
    $remaining = 0;
    foreach ($lines as $line) {
        $total = accounting_historical_cogs_fcfa((int) $line['quantity'], (int) $line['unit_price_fcfa']);
        $received = $receipts[(int) $line['id']] ?? 0;
        if ($received > $total) throw new RuntimeException('Un encaissement dépasse le total de la commande. Utilisez son détail.');
        $remaining = accounting_quick_add($remaining, $total - $received);
    }
    if ($remaining < 1) throw new RuntimeException('Cette commande est déjà entièrement encaissée.');
    return $remaining;
}

function accounting_quick_add(int $total, int $amount): int {
    if ($amount < 0 || $amount > PHP_INT_MAX - $total) throw new RuntimeException('Le total du lot est trop élevé.');
    return $total + $amount;
}

/** One entry per order reference, even when several selected IDs share it. */
function accounting_quick_prepare(PDO $pdo, array $data): array {
    $ids = accounting_quick_ids($data['order_ids'] ?? null);
    $action = (string) ($data['batch_action'] ?? '');
    if (!isset(accounting_quick_actions()[$action])) throw new RuntimeException('Choisissez une action.');
    $financial = in_array($action, ['deliver', 'collect'], true);
    $date = $financial ? accounting_delivery_effective_at($data['effective_at'] ?? null) : null;
    $channel = (string) ($data['channel'] ?? '');
    if (!in_array($channel, ['', 'Meta', 'Réachat'], true)) throw new RuntimeException('Canal invalide.');
    $accountId = $financial ? accounting_integer($data['account_id'] ?? null, 'Le compte d’encaissement', 1) : null;
    if ($accountId !== null) accounting_require_active_account($pdo, $accountId);
    $statement = $pdo->prepare('SELECT id, order_ref FROM orders WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $statement->execute($ids);
    $selected = $statement->fetchAll();
    if (count($selected) !== count($ids)) throw new RuntimeException('Une commande sélectionnée est introuvable.');
    $refs = array_unique(array_column($selected, 'order_ref'));
    sort($refs, SORT_STRING);
    $entries = [];
    $total = 0;
    foreach ($refs as $ref) {
        $lines = accounting_delivery_order_lines($pdo, (string) $ref);
        try { accounting_quick_validate_lines($lines, $action); }
        catch (RuntimeException $error) { throw new RuntimeException($ref . ' : ' . $error->getMessage()); }
        if (in_array($action, ['deliver', 'confirm', 'dispatch'], true) && $channel === '') {
            foreach ($lines as $line) if (!in_array($line['acquisition_channel'], ['Meta', 'Réachat'], true)) {
                throw new RuntimeException($ref . ' : renseignez le canal des commandes non renseignées avant de continuer.');
            }
        }
        $amount = $action === 'collect' ? accounting_quick_remaining($pdo, (string) $ref, $lines) : accounting_delivery_order_total($lines);
        $total = accounting_quick_add($total, $amount);
        $entries[] = [
            'order_id' => (int) $lines[0]['id'], 'order_ref' => (string) $ref,
            'lines' => $lines, 'fingerprint' => accounting_quick_fingerprint($lines),
            'amount_fcfa' => $amount, 'effective_at' => $date,
        ];
    }
    return ['key' => accounting_new_uuid(), 'action' => $action, 'account_id' => $accountId, 'channel' => $channel, 'entries' => $entries, 'total_fcfa' => $total];
}

function accounting_quick_apply_dates(array $plan, mixed $dates): array {
    if (!in_array($plan['action'], ['deliver', 'collect'], true)) return $plan;
    if (!is_array($dates) || count($dates) !== count($plan['entries'])) throw new RuntimeException('Vérifiez les dates de chaque commande.');
    foreach ($plan['entries'] as &$entry) {
        $entry['effective_at'] = accounting_delivery_effective_at($dates[(string) $entry['order_id']] ?? null);
        foreach ($entry['lines'] as $line) {
            if ($entry['effective_at'] < (string) $line['created_at']) {
                throw new RuntimeException($entry['order_ref'] . ' : la livraison ne peut pas précéder la commande.');
            }
            if ($plan['action'] === 'collect' && $entry['effective_at'] < (string) ($line['delivered_at'] ?? '')) {
                throw new RuntimeException($entry['order_ref'] . ' : l’encaissement du solde ne peut pas précéder la livraison.');
            }
        }
    }
    unset($entry);
    return $plan;
}

function accounting_quick_plan_hash(array $plan): string {
    $facts = ['action' => $plan['action'], 'account_id' => $plan['account_id'], 'channel' => $plan['channel'], 'entries' => []];
    foreach ($plan['entries'] as $entry) $facts['entries'][] = [$entry['order_ref'], $entry['fingerprint'], $entry['amount_fcfa'], $entry['effective_at']];
    return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
}

function accounting_quick_execute(PDO $pdo, array $plan, ?int $userId = null): array {
    ensure_accounting_schema();
    ensure_closer_schema();
    if ($pdo->inTransaction()) throw new RuntimeException('Le lot doit être confirmé séparément.');
    $key = accounting_uuid($plan['key'] ?? null);
    $hash = accounting_quick_plan_hash($plan);
    $financial = in_array($plan['action'], ['deliver', 'collect'], true);
    $result = accounting_with_transaction($pdo, function () use ($pdo, $plan, $userId, $key, $hash, $financial): array {
        // The unique insert also serializes two simultaneous submissions of this lot.
        $insert = $pdo->prepare('INSERT INTO accounting_quick_batches (idempotency_key, payload_hash, created_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE idempotency_key = idempotency_key');
        $insert->execute([$key, $hash, $userId]);
        $existing = $pdo->prepare('SELECT payload_hash, result_json FROM accounting_quick_batches WHERE idempotency_key = ? FOR UPDATE');
        $existing->execute([$key]);
        $batch = $existing->fetch();
        if (!hash_equals((string) $batch['payload_hash'], $hash)) throw new RuntimeException('Ce lot a déjà été utilisé avec des informations différentes.');
        if ($batch['result_json'] !== null) return json_decode($batch['result_json'], true, 512, JSON_THROW_ON_ERROR) + ['replayed' => true];

        // Lock all order lines before money/stock locks; validation is repeated at commit.
        $locked = [];
        foreach ($plan['entries'] as $entry) {
            $lines = accounting_delivery_order_lines($pdo, $entry['order_ref'], true);
            if (!hash_equals($entry['fingerprint'], accounting_quick_fingerprint($lines))) {
                throw new RuntimeException($entry['order_ref'] . ' a changé depuis le récapitulatif. Aucune commande du lot n’a été modifiée. Recommencez la sélection.');
            }
            accounting_quick_validate_lines($lines, $plan['action']);
            if ($plan['action'] === 'collect' && accounting_quick_remaining($pdo, $entry['order_ref'], $lines) !== $entry['amount_fcfa']) {
                throw new RuntimeException($entry['order_ref'] . ' : le solde a changé. Recommencez la sélection.');
            }
            $locked[$entry['order_ref']] = $lines;
        }
        if ($financial) accounting_require_active_account($pdo, (int) $plan['account_id'], true);
        $productIds = [];
        if ($plan['action'] === 'deliver') {
            foreach ($locked as $lines) foreach ($lines as $line) $productIds[] = (int) $line['product_id'];
            accounting_stock_lock_products($pdo, $productIds);
        }
        $results = [];
        foreach ($plan['entries'] as $entry) {
            $ref = $entry['order_ref'];
            $lines = $locked[$ref];
            $needsChannel = in_array($plan['action'], ['deliver', 'confirm', 'dispatch'], true);
            if ($needsChannel) {
                foreach ($lines as $line) {
                    if (!in_array($line['acquisition_channel'], ['Meta', 'Réachat'], true)) {
                        if ($plan['channel'] === '') throw new RuntimeException($ref . ' : choisissez le canal des commandes non renseignées.');
                        $setChannel = $pdo->prepare('UPDATE orders SET acquisition_channel = ? WHERE id = ?');
                        $setChannel->execute([$plan['channel'], $line['id']]);
                    }
                }
            }
            $data = [
                'idempotency_key' => accounting_quick_child_key($key, $ref), 'effective_at' => $entry['effective_at'],
                'payments' => [['account_id' => $plan['account_id'], 'amount_fcfa' => $entry['amount_fcfa']]],
                'order_ref' => $ref, 'note' => 'Saisie rapide · lot ' . $key,
            ];
            try {
                if ($plan['action'] === 'deliver') {
                    $operation = accounting_confirm_delivery($pdo, (int) $entry['order_id'], $data, $userId, false);
                } elseif ($plan['action'] === 'collect') {
                    $operation = accounting_collect_order_balance($pdo, $data, $userId, false);
                } else {
                    $status = ['confirm' => 'Confirmée', 'dispatch' => 'En livraison', 'cancel' => 'Annulée'][$plan['action']];
                    $update = $pdo->prepare('UPDATE orders SET status = ? WHERE order_ref = ?');
                    $update->execute([$status, $ref]);
                    log_event('commande', 'Commande ' . $ref . ' mise à jour : ' . $status . ' · saisie rapide', null, (int) $entry['order_id']);
                    sync_closer_tracking_for_order_ref($pdo, $ref);
                    $operation = [];
                }
            } catch (RuntimeException $error) {
                if ($error instanceof PDOException) throw $error;
                throw new RuntimeException($ref . ' : ' . $error->getMessage());
            }
            $results[] = ['order_ref' => $ref, 'group_reference' => $operation['group']['public_reference'] ?? null, 'amount_fcfa' => $financial ? $entry['amount_fcfa'] : 0];
        }
        $result = ['key' => $key, 'count' => count($results), 'total_fcfa' => $financial ? $plan['total_fcfa'] : 0, 'orders' => $results];
        accounting_audit($pdo, 'quick_batch', 'batch', null, null, $result + ['action' => $plan['action']], $userId);
        $done = $pdo->prepare('UPDATE accounting_quick_batches SET result_json = ? WHERE idempotency_key = ?');
        $done->execute([json_encode($result, JSON_THROW_ON_ERROR), $key]);
        return $result + ['replayed' => false];
    });
    // Never queue conversions from a transaction that might still roll back.
    if (!$result['replayed'] && in_array($plan['action'], ['deliver', 'collect', 'confirm'], true)) {
        foreach ($plan['entries'] as $entry) {
            try {
                meta_capi_queue_order_event($pdo, $entry['order_ref'], $plan['action'] === 'confirm' ? 'confirmed' : 'purchase');
            } catch (Throwable $error) { error_log('L’Horloger : signal Meta de saisie rapide différé.'); }
        }
    }
    return $result;
}
