<?php
declare(strict_types=1);

const ACCOUNTING_FOUNDATION_VERSION = '20260825_accounting_foundation';
const ACCOUNTING_DELIVERY_VERSION = '20260825_accounting_delivery';

/**
 * The accounting foundation is deliberately initialized from PHP as well as
 * documented in database/migrations. This lets an existing Hostinger install
 * upgrade safely the first time a manager opens the protected accounting page.
 */
function ensure_accounting_schema(): void {
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS accounting_schema_migrations (
            version VARCHAR(80) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $lock = $pdo->query("SELECT GET_LOCK('lhorloger_accounting_foundation', 15)")->fetchColumn();
    if ((int) $lock !== 1) throw new RuntimeException('La préparation de la comptabilité est déjà en cours. Réessayez dans quelques instants.');

    try {
        $applied = $pdo->prepare('SELECT 1 FROM accounting_schema_migrations WHERE version = ?');
        $applied->execute([ACCOUNTING_FOUNDATION_VERSION]);
        if (!$applied->fetchColumn()) {
            accounting_create_foundation_tables($pdo);
            accounting_add_foundation_columns($pdo);
            accounting_seed_categories($pdo);
            $mark = $pdo->prepare('INSERT INTO accounting_schema_migrations (version) VALUES (?)');
            $mark->execute([ACCOUNTING_FOUNDATION_VERSION]);
        }
        $deliveryApplied = $pdo->prepare('SELECT 1 FROM accounting_schema_migrations WHERE version = ?');
        $deliveryApplied->execute([ACCOUNTING_DELIVERY_VERSION]);
        if (!$deliveryApplied->fetchColumn()) {
            accounting_add_delivery_integrity($pdo);
            $mark = $pdo->prepare('INSERT INTO accounting_schema_migrations (version) VALUES (?)');
            $mark->execute([ACCOUNTING_DELIVERY_VERSION]);
        }
        $ready = true;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('lhorloger_accounting_foundation')");
    }
}

function accounting_create_foundation_tables(PDO $pdo): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS accounting_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            account_type ENUM('cash','bank','mobile_money') NOT NULL,
            opening_balance_fcfa BIGINT NOT NULL DEFAULT 0,
            opening_at DATETIME NOT NULL,
            description VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_accounting_accounts_active (is_active, account_type),
            CONSTRAINT fk_accounting_accounts_user FOREIGN KEY (created_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            direction ENUM('receipt','disbursement','both') NOT NULL,
            treatment ENUM('product_revenue','shop_revenue','product_refund','shop_refund','direct_expense','common_expense','inventory','out_of_result') NOT NULL,
            default_scope ENUM('product','shop','unassigned') NOT NULL DEFAULT 'shop',
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_accounting_categories_active (is_active, direction)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS direct_sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_ref VARCHAR(50) NOT NULL UNIQUE,
            customer_name VARCHAR(200) NULL,
            customer_phone VARCHAR(32) NULL,
            channel VARCHAR(60) NULL,
            subtotal_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            discount_total_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            deduct_stock TINYINT(1) NOT NULL DEFAULT 1,
            stock_skip_reason VARCHAR(500) NULL,
            effective_at DATETIME NOT NULL,
            status ENUM('confirmed','reversed') NOT NULL DEFAULT 'confirmed',
            linked_order_ref VARCHAR(32) NULL,
            note TEXT NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_direct_sales_effective (effective_at),
            INDEX idx_direct_sales_order_ref (linked_order_ref),
            CONSTRAINT fk_direct_sales_user FOREIGN KEY (created_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS direct_sale_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            direct_sale_id BIGINT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name_snapshot VARCHAR(120) NOT NULL,
            variant_snapshot VARCHAR(120) NULL,
            quantity SMALLINT UNSIGNED NOT NULL,
            unit_price_fcfa BIGINT UNSIGNED NOT NULL,
            discount_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            line_total_fcfa BIGINT UNSIGNED NOT NULL,
            unit_cost_snapshot_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_direct_sale_items_sale FOREIGN KEY (direct_sale_id) REFERENCES direct_sales(id) ON DELETE RESTRICT,
            CONSTRAINT fk_direct_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
            INDEX idx_direct_sale_items_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_operation_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_reference VARCHAR(50) NOT NULL UNIQUE,
            group_type ENUM('delivery','balance_collection','direct_sale','manual','transfer','refund','reversal') NOT NULL,
            idempotency_key CHAR(36) NOT NULL UNIQUE,
            order_ref VARCHAR(32) NULL,
            direct_sale_id BIGINT UNSIGNED NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accounting_groups_order_ref (order_ref),
            CONSTRAINT fk_accounting_groups_sale FOREIGN KEY (direct_sale_id) REFERENCES direct_sales(id) ON DELETE SET NULL,
            CONSTRAINT fk_accounting_groups_user FOREIGN KEY (created_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_operations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(60) NOT NULL UNIQUE,
            nature ENUM('receipt','disbursement','transfer','adjustment') NOT NULL,
            status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
            account_id BIGINT UNSIGNED NOT NULL,
            destination_account_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            source_type ENUM('order','direct_sale','manual','refund','transfer','reversal') NOT NULL,
            amount_fcfa BIGINT UNSIGNED NOT NULL,
            effective_at DATETIME NOT NULL,
            label VARCHAR(180) NOT NULL,
            counterparty VARCHAR(180) NULL,
            payment_reference VARCHAR(120) NULL,
            note TEXT NULL,
            reversal_of_id BIGINT UNSIGNED NULL UNIQUE,
            created_by_user_id BIGINT UNSIGNED NULL,
            confirmed_by_user_id BIGINT UNSIGNED NULL,
            confirmed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_accounting_operations_account_date (account_id, status, effective_at),
            INDEX idx_accounting_operations_category_date (category_id, effective_at),
            INDEX idx_accounting_operations_group (group_id),
            CONSTRAINT fk_accounting_operations_group FOREIGN KEY (group_id) REFERENCES accounting_operation_groups(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_operations_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_operations_destination FOREIGN KEY (destination_account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_operations_category FOREIGN KEY (category_id) REFERENCES accounting_categories(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_operations_user_created FOREIGN KEY (created_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
            CONSTRAINT fk_accounting_operations_user_confirmed FOREIGN KEY (confirmed_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operation_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            treatment ENUM('product_revenue','shop_revenue','product_refund','shop_refund','direct_expense','common_expense','inventory','out_of_result') NOT NULL,
            scope ENUM('product','shop','unassigned') NOT NULL,
            product_id INT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            direct_sale_item_id BIGINT UNSIGNED NULL,
            amount_fcfa BIGINT UNSIGNED NOT NULL,
            effect_sign TINYINT NOT NULL DEFAULT 1,
            quantity_equivalent DECIMAL(16,6) NOT NULL DEFAULT 0,
            unit_cost_snapshot_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cogs_amount_fcfa BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accounting_allocations_product (product_id, treatment),
            INDEX idx_accounting_allocations_order (order_id),
            CONSTRAINT fk_accounting_allocations_operation FOREIGN KEY (operation_id) REFERENCES accounting_operations(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_allocations_category FOREIGN KEY (category_id) REFERENCES accounting_categories(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_allocations_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            CONSTRAINT fk_accounting_allocations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            CONSTRAINT fk_accounting_allocations_direct_item FOREIGN KEY (direct_sale_item_id) REFERENCES direct_sale_items(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_payment_exceptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_ref VARCHAR(32) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status ENUM('open','resolved','cancelled') NOT NULL DEFAULT 'open',
            opened_by_user_id BIGINT UNSIGNED NULL,
            resolved_by_user_id BIGINT UNSIGNED NULL,
            opened_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            INDEX idx_accounting_exceptions_ref_status (order_ref, status),
            CONSTRAINT fk_accounting_exceptions_opened_by FOREIGN KEY (opened_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
            CONSTRAINT fk_accounting_exceptions_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_reconciliations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            reconciled_at DATETIME NOT NULL,
            calculated_balance_fcfa BIGINT NOT NULL,
            statement_balance_fcfa BIGINT NOT NULL,
            difference_fcfa BIGINT NOT NULL,
            note VARCHAR(1000) NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accounting_reconciliations_account_date (account_id, reconciled_at),
            CONSTRAINT fk_accounting_reconciliations_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_reconciliations_user FOREIGN KEY (created_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operation_id BIGINT UNSIGNED NULL,
            reconciliation_id BIGINT UNSIGNED NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(128) NOT NULL UNIQUE,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            storage_path VARCHAR(500) NOT NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accounting_attachments_operation (operation_id),
            INDEX idx_accounting_attachments_reconciliation (reconciliation_id),
            CONSTRAINT fk_accounting_attachments_operation FOREIGN KEY (operation_id) REFERENCES accounting_operations(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_attachments_reconciliation FOREIGN KEY (reconciliation_id) REFERENCES accounting_reconciliations(id) ON DELETE RESTRICT,
            CONSTRAINT fk_accounting_attachments_user FOREIGN KEY (created_by_user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS accounting_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            previous_data LONGTEXT NULL,
            next_data LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accounting_audit_entity (entity_type, entity_id, created_at),
            CONSTRAINT fk_accounting_audit_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $statement) $pdo->exec($statement);
}

function accounting_add_foundation_columns(PDO $pdo): void {
    accounting_add_column_if_missing($pdo, 'orders', 'delivered_at', 'DATETIME NULL AFTER updated_at');
    accounting_add_index_if_missing($pdo, 'orders', 'idx_orders_ref_status', 'INDEX idx_orders_ref_status (order_ref, status)');
    accounting_add_column_if_missing($pdo, 'stock_movements', 'order_id', 'BIGINT UNSIGNED NULL AFTER product_id');
    accounting_add_column_if_missing($pdo, 'stock_movements', 'direct_sale_item_id', 'BIGINT UNSIGNED NULL AFTER order_id');
    accounting_add_column_if_missing($pdo, 'stock_movements', 'operation_group_id', 'BIGINT UNSIGNED NULL AFTER direct_sale_item_id');
    accounting_add_column_if_missing($pdo, 'stock_movements', 'unit_cost_snapshot_fcfa', 'BIGINT UNSIGNED NULL AFTER unit_cost_fcfa');
    accounting_add_column_if_missing($pdo, 'stock_movements', 'sale_unit_price_fcfa', 'BIGINT UNSIGNED NULL AFTER unit_cost_snapshot_fcfa');
    accounting_add_column_if_missing($pdo, 'stock_movements', 'skip_reason', 'VARCHAR(500) NULL AFTER note');
    accounting_add_index_if_missing($pdo, 'stock_movements', 'idx_stock_order_source', 'INDEX idx_stock_order_source (order_id, movement_type)');
    accounting_add_index_if_missing($pdo, 'stock_movements', 'idx_stock_direct_sale_source', 'INDEX idx_stock_direct_sale_source (direct_sale_item_id, movement_type)');
    accounting_add_column_if_missing($pdo, 'ad_costs', 'accounting_operation_id', 'BIGINT UNSIGNED NULL AFTER product_id');
    accounting_add_column_if_missing($pdo, 'ad_costs', 'actual_paid_at', 'DATETIME NULL AFTER end_date');
    accounting_add_index_if_missing($pdo, 'ad_costs', 'idx_ads_accounting_operation', 'INDEX idx_ads_accounting_operation (accounting_operation_id)');
}

function accounting_add_delivery_integrity(PDO $pdo): void {
    accounting_add_column_if_missing($pdo, 'orders', 'stock_processed', 'TINYINT(1) NOT NULL DEFAULT 0');
    accounting_ensure_unique_index($pdo, 'stock_movements', 'idx_stock_order_source', 'UNIQUE INDEX idx_stock_order_source (order_id, movement_type)');
    accounting_ensure_unique_index($pdo, 'stock_movements', 'idx_stock_direct_sale_source', 'UNIQUE INDEX idx_stock_direct_sale_source (direct_sale_item_id, movement_type)');
}

function accounting_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);
    if ($statement->fetchColumn()) return;
    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
}

function accounting_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void {
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $statement->execute([$table, $index]);
    if ($statement->fetchColumn()) return;
    $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition);
}

function accounting_ensure_unique_index(PDO $pdo, string $table, string $index, string $definition): void {
    $statement = $pdo->prepare(
        'SELECT non_unique FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
    );
    $statement->execute([$table, $index]);
    $existing = $statement->fetchColumn();
    if ($existing !== false && (int) $existing === 0) return;
    if ($existing !== false) {
        $duplicates = $pdo->query(
            'SELECT 1 FROM `' . $table . '` WHERE `' . ($index === 'idx_stock_order_source' ? 'order_id' : 'direct_sale_item_id') . '` IS NOT NULL
             GROUP BY `' . ($index === 'idx_stock_order_source' ? 'order_id' : 'direct_sale_item_id') . '`, movement_type HAVING COUNT(*) > 1 LIMIT 1'
        )->fetchColumn();
        if ($duplicates) throw new RuntimeException('Des sorties de stock en double doivent être corrigées avant d’activer la livraison comptable.');
        $pdo->exec('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
    }
    $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition);
}

function accounting_system_categories(): array {
    return [
        ['sale_product', 'Vente de montre', 'receipt', 'product_revenue', 'product', 10],
        ['sale_shop', 'Revenu boutique', 'receipt', 'shop_revenue', 'shop', 20],
        ['refund_product', 'Remboursement montre', 'disbursement', 'product_refund', 'product', 30],
        ['refund_shop', 'Remboursement boutique', 'disbursement', 'shop_refund', 'shop', 40],
        ['meta_ads', 'Publicité Meta', 'disbursement', 'direct_expense', 'product', 50],
        ['product_service', 'Charge directe produit', 'disbursement', 'direct_expense', 'product', 60],
        ['inventory_purchase', 'Achat de stock', 'disbursement', 'inventory', 'product', 70],
        ['inventory_transit', 'Transit de stock', 'disbursement', 'inventory', 'product', 80],
        ['rent', 'Loyer', 'disbursement', 'common_expense', 'shop', 90],
        ['telecom', 'Télécoms et internet', 'disbursement', 'common_expense', 'shop', 100],
        ['bank_fee', 'Frais bancaires', 'disbursement', 'common_expense', 'shop', 110],
        ['owner_contribution', 'Apport propriétaire', 'receipt', 'out_of_result', 'shop', 120],
        ['owner_withdrawal', 'Retrait propriétaire', 'disbursement', 'out_of_result', 'shop', 130],
        ['other_out_of_result', 'Autre hors résultat', 'both', 'out_of_result', 'unassigned', 140],
    ];
}

function accounting_seed_categories(PDO $pdo): void {
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO accounting_categories (code, name, direction, treatment, default_scope, is_system, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, 1, 1, ?)'
    );
    foreach (accounting_system_categories() as $category) $insert->execute($category);
}

function accounting_foundation_status(): array {
    ensure_accounting_schema();
    $pdo = db();
    return [
        'accounts' => (int) $pdo->query('SELECT COUNT(*) FROM accounting_accounts')->fetchColumn(),
        'categories' => (int) $pdo->query('SELECT COUNT(*) FROM accounting_categories WHERE is_active = 1')->fetchColumn(),
        'operations' => (int) $pdo->query('SELECT COUNT(*) FROM accounting_operations')->fetchColumn(),
    ];
}

/**
 * Monetary inputs stay as integers from the first HTTP boundary through to
 * MySQL. This intentionally rejects formatted values such as "55 000" so a
 * browser can never silently turn a separator into a rounding error.
 */
function accounting_integer(mixed $value, string $label, int $minimum = 0): int {
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value)) {
        $limit = str_starts_with($value, '-') ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;
        $absolute = ltrim($value, '-');
        if (strlen($absolute) > strlen($limit) || (strlen($absolute) === strlen($limit) && strcmp($absolute, $limit) > 0)) {
            throw new RuntimeException($label . ' est trop élevé.');
        }
        $number = (int) $value;
    } else {
        throw new RuntimeException($label . ' doit être un montant entier en FCFA.');
    }

    if ($number < $minimum) {
        throw new RuntimeException($label . ' doit être ' . ($minimum > 0 ? 'strictement positif.' : 'positif ou nul.'));
    }
    return $number;
}

function accounting_flag(mixed $value, string $label): int {
    if ($value === true || $value === 1 || $value === '1') return 1;
    if ($value === false || $value === 0 || $value === '0') return 0;
    throw new RuntimeException($label . ' est invalide.');
}

function accounting_historical_cogs_fcfa(mixed $quantity, mixed $unitCostFcfa): int {
    $units = accounting_integer($quantity, 'La quantité', 0);
    $unitCost = accounting_integer($unitCostFcfa, 'Le coût unitaire historique', 0);
    if ($units > 0 && $unitCost > intdiv(PHP_INT_MAX, $units)) {
        throw new RuntimeException('Le coût historique est trop élevé.');
    }
    return $units * $unitCost;
}

function accounting_non_empty_text(mixed $value, string $label, int $maximum): string {
    $text = trim((string) $value);
    if ($text === '') throw new RuntimeException($label . ' est obligatoire.');
    if (mb_strlen($text) > $maximum) throw new RuntimeException($label . ' est trop long.');
    return $text;
}

function accounting_optional_text(mixed $value, string $label, int $maximum): ?string {
    $text = trim((string) $value);
    if ($text === '') return null;
    if (mb_strlen($text) > $maximum) throw new RuntimeException($label . ' est trop long.');
    return $text;
}

function accounting_bamako_timezone(): DateTimeZone {
    static $timezone = null;
    return $timezone ??= new DateTimeZone('Africa/Bamako');
}

function accounting_effective_at(mixed $value, string $label = 'La date'): string {
    $raw = trim((string) $value);
    $formats = ['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw, accounting_bamako_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $format === '!Y-m-d' ? $date->setTime(0, 0, 0)->format('Y-m-d H:i:s') : $date->format('Y-m-d H:i:s');
        }
    }
    throw new RuntimeException($label . ' est invalide.');
}

function accounting_code(mixed $value, string $label, int $maximum = 40): string {
    $code = strtoupper(trim((string) $value));
    if (!preg_match('/^[A-Z0-9_-]{2,' . $maximum . '}$/', $code)) {
        throw new RuntimeException($label . ' doit contenir uniquement des lettres, chiffres, tirets ou underscores.');
    }
    return $code;
}

function accounting_uuid(mixed $value, string $label = 'La clé de confirmation'): string {
    $uuid = strtolower(trim((string) $value));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
        throw new RuntimeException($label . ' est invalide. Rechargez la page puis réessayez.');
    }
    return $uuid;
}

function accounting_new_uuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function accounting_current_user_id(): ?int {
    $userId = (int) ($_SESSION['admin_user_id'] ?? 0);
    return $userId > 0 ? $userId : null;
}

function accounting_json_snapshot(?array $data): ?string {
    if ($data === null) return null;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function accounting_with_transaction(PDO $pdo, callable $callback): mixed {
    $owner = !$pdo->inTransaction();
    if ($owner) $pdo->beginTransaction();
    try {
        $result = $callback();
        if ($owner) $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($owner && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function accounting_safe_error_message(Throwable $exception, string $fallback): string {
    if ($exception instanceof PDOException) return $fallback;
    return $exception instanceof RuntimeException ? $exception->getMessage() : $fallback;
}

function accounting_audit(PDO $pdo, string $action, string $entityType, ?int $entityId, ?array $previous = null, ?array $next = null, ?int $userId = null): void {
    $statement = $pdo->prepare(
        'INSERT INTO accounting_audit_log (user_id, action, entity_type, entity_id, previous_data, next_data, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $userId ?? accounting_current_user_id(),
        $action,
        $entityType,
        $entityId,
        accounting_json_snapshot($previous),
        accounting_json_snapshot($next),
        accounting_optional_text($_SERVER['REMOTE_ADDR'] ?? null, 'Adresse IP', 45),
        accounting_optional_text($_SERVER['HTTP_USER_AGENT'] ?? null, 'Navigateur', 500),
    ]);
}

require_once __DIR__ . '/accounting_accounts.php';
require_once __DIR__ . '/accounting_categories.php';
require_once __DIR__ . '/accounting_allocations.php';
require_once __DIR__ . '/accounting_operations.php';
require_once __DIR__ . '/accounting_stock.php';
require_once __DIR__ . '/accounting_delivery.php';
require_once __DIR__ . '/accounting_treasury.php';
require_once __DIR__ . '/accounting_sales.php';
require_once __DIR__ . '/accounting_attachments.php';
require_once __DIR__ . '/accounting_reports.php';
