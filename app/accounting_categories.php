<?php
declare(strict_types=1);

function accounting_list_categories(PDO $pdo, bool $activeOnly = false): array {
    $sql = 'SELECT id, code, name, direction, treatment, default_scope, is_system, is_active, sort_order, created_at, updated_at
            FROM accounting_categories';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY is_active DESC, sort_order ASC, name ASC, id ASC';
    return $pdo->query($sql)->fetchAll();
}

function accounting_find_category(PDO $pdo, int $categoryId, bool $lock = false): array {
    if ($categoryId < 1) throw new RuntimeException('Catégorie invalide.');
    $statement = $pdo->prepare(
        'SELECT id, code, name, direction, treatment, default_scope, is_system, is_active, sort_order, created_at, updated_at
         FROM accounting_categories WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$categoryId]);
    $category = $statement->fetch();
    if (!$category) throw new RuntimeException('Catégorie introuvable.');
    return $category;
}

function accounting_require_active_category(PDO $pdo, int $categoryId, bool $lock = false): array {
    $category = accounting_find_category($pdo, $categoryId, $lock);
    if (!(bool) $category['is_active']) throw new RuntimeException('Cette catégorie est désactivée.');
    return $category;
}

function accounting_update_category(PDO $pdo, int $categoryId, array $data, ?int $userId = null): array {
    return accounting_with_transaction($pdo, function () use ($pdo, $categoryId, $data, $userId): array {
        $current = accounting_find_category($pdo, $categoryId, true);
        foreach (['code', 'direction', 'treatment', 'default_scope', 'is_system'] as $immutable) {
            if (array_key_exists($immutable, $data) && (string) $data[$immutable] !== (string) $current[$immutable]) {
                throw new RuntimeException('Le code et le traitement d’une catégorie sont immuables.');
            }
        }
        $name = accounting_non_empty_text($data['name'] ?? $current['name'], 'Le nom de la catégorie', 120);
        $sortOrder = array_key_exists('sort_order', $data)
            ? accounting_integer($data['sort_order'], 'L’ordre d’affichage', 0)
            : (int) $current['sort_order'];
        if ($sortOrder > 65535) throw new RuntimeException('L’ordre d’affichage est trop élevé.');
        $isActive = array_key_exists('is_active', $data) ? accounting_flag($data['is_active'], 'L’état de la catégorie') : (int) $current['is_active'];

        $update = $pdo->prepare('UPDATE accounting_categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ?');
        $update->execute([$name, $sortOrder, $isActive, $categoryId]);
        $next = accounting_find_category($pdo, $categoryId);
        accounting_audit($pdo, 'update', 'category', $categoryId, $current, $next, $userId);
        return $next;
    });
}
