<?php
declare(strict_types=1);

function accounting_journal_filter_options(): array {
    return [
        'group_type' => accounting_group_types(),
        'nature' => ['receipt', 'disbursement', 'transfer'],
        'status' => ['draft', 'confirmed'],
    ];
}

function accounting_journal_filters(array $input): array {
    $options = accounting_journal_filter_options();
    $groupType = trim((string) ($input['group_type'] ?? ''));
    $nature = trim((string) ($input['nature'] ?? ''));
    $status = trim((string) ($input['status'] ?? 'confirmed'));
    if ($groupType !== '' && !in_array($groupType, $options['group_type'], true)) throw new RuntimeException('Filtre de groupe invalide.');
    if ($nature !== '' && !in_array($nature, $options['nature'], true)) throw new RuntimeException('Filtre de nature invalide.');
    if ($status !== '' && !in_array($status, $options['status'], true)) throw new RuntimeException('Filtre de statut invalide.');
    $accountId = trim((string) ($input['account_id'] ?? ''));
    $categoryId = trim((string) ($input['category_id'] ?? ''));
    $productId = trim((string) ($input['product_id'] ?? ''));
    $query = accounting_optional_text($input['q'] ?? null, 'La recherche', 120);
    $start = trim((string) ($input['start'] ?? ''));
    $end = trim((string) ($input['end'] ?? ''));
    $period = $start !== '' || $end !== ''
        ? accounting_period_bounds($start === '' ? '2000-01-01' : $start, $end === '' ? (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d') : $end)
        : [null, null];
    $page = accounting_integer((string) ($input['page'] ?? '1'), 'La page', 1);
    return [
        'group_type' => $groupType,
        'nature' => $nature,
        'status' => $status,
        'account_id' => $accountId === '' ? null : accounting_integer($accountId, 'Le compte', 1),
        'category_id' => $categoryId === '' ? null : accounting_integer($categoryId, 'La catégorie', 1),
        'product_id' => $productId === '' ? null : accounting_integer($productId, 'Le produit', 1),
        'q' => $query,
        'start_at' => $period[0],
        'end_at' => $period[1],
        'page' => min($page, 100000),
        'per_page' => 30,
    ];
}

function accounting_journal_page(PDO $pdo, array $filters): array {
    $where = [];
    $params = [];
    foreach ([
        'group_type' => 'g.group_type',
        'nature' => 'o.nature',
        'status' => 'o.status',
    ] as $key => $column) {
        if (($filters[$key] ?? '') !== '') {
            $where[] = $column . ' = ?';
            $params[] = $filters[$key];
        }
    }
    if (($filters['account_id'] ?? null) !== null) {
        $where[] = '(o.account_id = ? OR o.destination_account_id = ?)';
        $params[] = $filters['account_id'];
        $params[] = $filters['account_id'];
    }
    if (($filters['category_id'] ?? null) !== null) {
        $where[] = 'o.category_id = ?';
        $params[] = $filters['category_id'];
    }
    if (($filters['product_id'] ?? null) !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM accounting_allocations product_allocation WHERE product_allocation.operation_id = o.id AND product_allocation.product_id = ?)';
        $params[] = $filters['product_id'];
    }
    if (($filters['start_at'] ?? null) !== null) {
        $where[] = 'o.effective_at >= ?';
        $params[] = $filters['start_at'];
    }
    if (($filters['end_at'] ?? null) !== null) {
        $where[] = 'o.effective_at <= ?';
        $params[] = $filters['end_at'];
    }
    if (($filters['q'] ?? null) !== null) {
        $where[] = '(o.reference LIKE ? OR g.public_reference LIKE ? OR g.order_ref LIKE ? OR o.label LIKE ? OR o.counterparty LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_operations o INNER JOIN accounting_operation_groups g ON g.id = o.group_id' . $whereSql
    );
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = (($filters['page'] - 1) * $filters['per_page']);
    $statement = $pdo->prepare(
        'SELECT o.*, g.public_reference AS group_reference, g.group_type, g.order_ref, g.direct_sale_id,
                a.name AS account_name, a.code AS account_code, d.name AS destination_account_name,
                c.name AS category_name, c.treatment AS category_treatment,
                (SELECT COUNT(*) FROM accounting_attachments at WHERE at.operation_id = o.id) AS attachment_count
         FROM accounting_operations o
         INNER JOIN accounting_operation_groups g ON g.id = o.group_id
         INNER JOIN accounting_accounts a ON a.id = o.account_id
         LEFT JOIN accounting_accounts d ON d.id = o.destination_account_id
         LEFT JOIN accounting_categories c ON c.id = o.category_id'
        . $whereSql
        . ' ORDER BY o.effective_at DESC, o.created_at DESC, o.id DESC LIMIT ' . (int) $filters['per_page'] . ' OFFSET ' . (int) $offset
    );
    $statement->execute($params);
    return [
        'rows' => $statement->fetchAll(),
        'total' => $total,
        'page' => $filters['page'],
        'per_page' => $filters['per_page'],
        'pages' => max(1, intdiv($total + $filters['per_page'] - 1, $filters['per_page'])),
    ];
}

function accounting_operation_detail(PDO $pdo, int $operationId): array {
    $operation = accounting_find_operation($pdo, $operationId);
    $accounts = $pdo->prepare(
        'SELECT a.name AS account_name, a.code AS account_code, d.name AS destination_account_name, d.code AS destination_account_code,
                c.name AS category_name, c.treatment AS category_treatment,
                (SELECT r.id FROM accounting_operations r WHERE r.reversal_of_id = o.id LIMIT 1) AS reversal_id
         FROM accounting_operations o INNER JOIN accounting_accounts a ON a.id = o.account_id
         LEFT JOIN accounting_accounts d ON d.id = o.destination_account_id
         LEFT JOIN accounting_categories c ON c.id = o.category_id
         WHERE o.id = ?'
    );
    $accounts->execute([$operationId]);
    $detail = $accounts->fetch();
    if (!$detail) throw new RuntimeException('Opération introuvable.');
    $allocations = $pdo->prepare(
        'SELECT al.*, p.name AS product_name, ord.order_ref, ds.sale_ref
         FROM accounting_allocations al
         LEFT JOIN products p ON p.id = al.product_id
         LEFT JOIN orders ord ON ord.id = al.order_id
         LEFT JOIN direct_sale_items dsi ON dsi.id = al.direct_sale_item_id
         LEFT JOIN direct_sales ds ON ds.id = dsi.direct_sale_id
         WHERE al.operation_id = ? ORDER BY al.id ASC'
    );
    $allocations->execute([$operationId]);
    $attachments = $pdo->prepare('SELECT id, original_name, mime_type, size_bytes, created_at FROM accounting_attachments WHERE operation_id = ? ORDER BY id DESC');
    $attachments->execute([$operationId]);
    return ['operation' => array_merge($operation, $detail), 'allocations' => $allocations->fetchAll(), 'attachments' => $attachments->fetchAll()];
}
