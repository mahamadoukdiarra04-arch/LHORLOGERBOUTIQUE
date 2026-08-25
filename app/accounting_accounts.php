<?php
declare(strict_types=1);

function accounting_account_types(): array {
    return ['cash', 'bank', 'mobile_money'];
}

function accounting_list_accounts(PDO $pdo, bool $activeOnly = false): array {
    $sql = 'SELECT id, code, name, account_type, opening_balance_fcfa, opening_at, description, is_active, created_by_user_id, created_at, updated_at
            FROM accounting_accounts';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY is_active DESC, account_type ASC, name ASC, id ASC';
    return $pdo->query($sql)->fetchAll();
}

function accounting_find_account(PDO $pdo, int $accountId, bool $lock = false): array {
    if ($accountId < 1) throw new RuntimeException('Compte invalide.');
    $statement = $pdo->prepare(
        'SELECT id, code, name, account_type, opening_balance_fcfa, opening_at, description, is_active, created_by_user_id, created_at, updated_at
         FROM accounting_accounts WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$accountId]);
    $account = $statement->fetch();
    if (!$account) throw new RuntimeException('Compte introuvable.');
    return $account;
}

function accounting_require_active_account(PDO $pdo, int $accountId, bool $lock = false): array {
    $account = accounting_find_account($pdo, $accountId, $lock);
    if (!(bool) $account['is_active']) throw new RuntimeException('Ce compte est désactivé.');
    return $account;
}

function accounting_create_account(PDO $pdo, array $data, ?int $userId = null): array {
    $code = accounting_code($data['code'] ?? '', 'Le code du compte');
    $name = accounting_non_empty_text($data['name'] ?? '', 'Le nom du compte', 120);
    $type = (string) ($data['account_type'] ?? '');
    if (!in_array($type, accounting_account_types(), true)) throw new RuntimeException('Type de compte invalide.');
    $openingBalance = accounting_integer($data['opening_balance_fcfa'] ?? 0, 'Le solde d’ouverture', PHP_INT_MIN);
    $openingAt = accounting_effective_at($data['opening_at'] ?? '', 'La date d’ouverture');
    $description = accounting_optional_text($data['description'] ?? null, 'La description', 500);

    return accounting_with_transaction($pdo, function () use ($pdo, $code, $name, $type, $openingBalance, $openingAt, $description, $userId): array {
        $existing = $pdo->prepare('SELECT id FROM accounting_accounts WHERE code = ? FOR UPDATE');
        $existing->execute([$code]);
        if ($existing->fetchColumn()) throw new RuntimeException('Ce code de compte est déjà utilisé.');

        $insert = $pdo->prepare(
            'INSERT INTO accounting_accounts (code, name, account_type, opening_balance_fcfa, opening_at, description, is_active, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $insert->execute([$code, $name, $type, $openingBalance, $openingAt, $description, $userId ?? accounting_current_user_id()]);
        $id = (int) $pdo->lastInsertId();
        $account = accounting_find_account($pdo, $id);
        accounting_audit($pdo, 'create', 'account', $id, null, $account, $userId);
        return $account;
    });
}

function accounting_account_has_activity(PDO $pdo, int $accountId): bool {
    $operation = $pdo->prepare('SELECT 1 FROM accounting_operations WHERE account_id = ? OR destination_account_id = ? LIMIT 1');
    $operation->execute([$accountId, $accountId]);
    if ($operation->fetchColumn()) return true;
    $reconciliation = $pdo->prepare('SELECT 1 FROM accounting_reconciliations WHERE account_id = ? LIMIT 1');
    $reconciliation->execute([$accountId]);
    return (bool) $reconciliation->fetchColumn();
}

function accounting_update_account(PDO $pdo, int $accountId, array $data, ?int $userId = null): array {
    return accounting_with_transaction($pdo, function () use ($pdo, $accountId, $data, $userId): array {
        $current = accounting_find_account($pdo, $accountId, true);
        $name = accounting_non_empty_text($data['name'] ?? $current['name'], 'Le nom du compte', 120);
        $description = accounting_optional_text($data['description'] ?? $current['description'], 'La description', 500);
        $isActive = array_key_exists('is_active', $data) ? accounting_flag($data['is_active'], 'L’état du compte') : (int) $current['is_active'];
        $hasActivity = accounting_account_has_activity($pdo, $accountId);

        $openingBalance = $current['opening_balance_fcfa'];
        $openingAt = $current['opening_at'];
        $type = $current['account_type'];
        if (!$hasActivity) {
            if (array_key_exists('opening_balance_fcfa', $data)) $openingBalance = accounting_integer($data['opening_balance_fcfa'], 'Le solde d’ouverture', PHP_INT_MIN);
            if (array_key_exists('opening_at', $data)) $openingAt = accounting_effective_at($data['opening_at'], 'La date d’ouverture');
            if (array_key_exists('account_type', $data)) {
                $type = (string) $data['account_type'];
                if (!in_array($type, accounting_account_types(), true)) throw new RuntimeException('Type de compte invalide.');
            }
        } else {
            if (array_key_exists('account_type', $data) && (string) $data['account_type'] !== $current['account_type']) {
                throw new RuntimeException('Le code, le type et le solde d’ouverture ne peuvent plus être modifiés après la première utilisation.');
            }
            if (array_key_exists('opening_balance_fcfa', $data)
                && accounting_integer($data['opening_balance_fcfa'], 'Le solde d’ouverture', PHP_INT_MIN) !== (int) $current['opening_balance_fcfa']) {
                throw new RuntimeException('Le code, le type et le solde d’ouverture ne peuvent plus être modifiés après la première utilisation.');
            }
            if (array_key_exists('opening_at', $data)
                && accounting_effective_at($data['opening_at'], 'La date d’ouverture') !== $current['opening_at']) {
                throw new RuntimeException('Le code, le type et le solde d’ouverture ne peuvent plus être modifiés après la première utilisation.');
            }
        }

        if (array_key_exists('code', $data) && accounting_code($data['code'], 'Le code du compte') !== $current['code']) {
            throw new RuntimeException('Le code d’un compte est immuable.');
        }

        $update = $pdo->prepare(
            'UPDATE accounting_accounts
             SET name = ?, account_type = ?, opening_balance_fcfa = ?, opening_at = ?, description = ?, is_active = ?
             WHERE id = ?'
        );
        $update->execute([$name, $type, $openingBalance, $openingAt, $description, $isActive, $accountId]);
        $next = accounting_find_account($pdo, $accountId);
        accounting_audit($pdo, 'update', 'account', $accountId, $current, $next, $userId);
        return $next;
    });
}
