CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  sku VARCHAR(24) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  price_fcfa INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_ref VARCHAR(32) NOT NULL,
  customer_first_name VARCHAR(100) NOT NULL,
  customer_last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  district VARCHAR(150) NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(120) NOT NULL,
  variant VARCHAR(120) NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  unit_price_fcfa INT UNSIGNED NOT NULL,
  status ENUM('À confirmer','Confirmée','En livraison','Livrée','Annulée') NOT NULL DEFAULT 'À confirmer',
  acquisition_channel ENUM('Meta','Réachat') NULL,
  stock_processed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  INDEX idx_orders_ref (order_ref), INDEX idx_orders_status (status), INDEX idx_orders_created (created_at), INDEX idx_orders_ref_status (order_ref, status),
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  direct_sale_item_id BIGINT UNSIGNED NULL,
  operation_group_id BIGINT UNSIGNED NULL,
  movement_type ENUM('Réassort','Sortie','Ajustement') NOT NULL,
  quantity INT NOT NULL,
  purchase_price_fcfa INT UNSIGNED NULL,
  transit_price_fcfa INT UNSIGNED NULL,
  unit_cost_fcfa DECIMAL(12,2) NULL,
  unit_cost_snapshot_fcfa BIGINT UNSIGNED NULL,
  sale_unit_price_fcfa BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  skip_reason VARCHAR(500) NULL,
  actor VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stock_product_date (product_id, created_at), UNIQUE INDEX idx_stock_order_source (order_id, movement_type), UNIQUE INDEX idx_stock_direct_sale_source (direct_sale_item_id, movement_type),
  CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_costs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  accounting_operation_id BIGINT UNSIGNED NULL,
  channel ENUM('Meta') NOT NULL DEFAULT 'Meta',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  actual_paid_at DATETIME NULL,
  amount_fcfa INT UNSIGNED NOT NULL,
  actor VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ads_product_period (product_id, start_date, end_date), INDEX idx_ads_accounting_operation (accounting_operation_id),
  CONSTRAINT fk_ads_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(50) NOT NULL,
  message VARCHAR(255) NOT NULL,
  product_id INT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  actor VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_product_date (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('manager','closer') NOT NULL DEFAULT 'manager',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_users_active (is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_closer_tracking (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  closer_identity VARCHAR(50) NOT NULL,
  follow_up_status VARCHAR(32) NOT NULL DEFAULT 'À appeler',
  follow_up_at DATETIME NULL,
  note TEXT NULL,
  whatsapp_prepared_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_closer_status (closer_identity, follow_up_status),
  INDEX idx_closer_follow_up (follow_up_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS closer_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  closer_identity VARCHAR(50) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_closer_events_order (order_id, created_at),
  INDEX idx_closer_events_identity (closer_identity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO products (id, slug, sku, name, price_fcfa) VALUES
  (1, 'nocturne-chrono', 'T-01', 'Nocturne Chrono', 52000),
  (2, 'azur-squelette', 'T-02', 'Azur Squelette', 62000),
  (3, 'eclipse-lunaire', 'T-03', 'Éclipse Lunaire', 59000);

-- Comptabilité & trésorerie — fondation. Les installations existantes sont
-- mises à niveau de façon idempotente par app/accounting.php.
CREATE TABLE IF NOT EXISTS accounting_schema_migrations (
  version VARCHAR(80) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_accounts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_categories (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_sales (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_sale_items (
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
  INDEX idx_direct_sale_items_product (product_id),
  CONSTRAINT fk_direct_sale_items_sale FOREIGN KEY (direct_sale_id) REFERENCES direct_sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_direct_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_operation_groups (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_operations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_allocations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_payment_exceptions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_reconciliations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_attachments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_audit_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounting_categories (code, name, direction, treatment, default_scope, is_system, is_active, sort_order) VALUES
  ('sale_product', 'Vente de montre', 'receipt', 'product_revenue', 'product', 1, 1, 10),
  ('sale_shop', 'Revenu boutique', 'receipt', 'shop_revenue', 'shop', 1, 1, 20),
  ('refund_product', 'Remboursement montre', 'disbursement', 'product_refund', 'product', 1, 1, 30),
  ('refund_shop', 'Remboursement boutique', 'disbursement', 'shop_refund', 'shop', 1, 1, 40),
  ('meta_ads', 'Publicité Meta', 'disbursement', 'direct_expense', 'product', 1, 1, 50),
  ('product_service', 'Charge directe produit', 'disbursement', 'direct_expense', 'product', 1, 1, 60),
  ('inventory_purchase', 'Achat de stock', 'disbursement', 'inventory', 'product', 1, 1, 70),
  ('inventory_transit', 'Transit de stock', 'disbursement', 'inventory', 'product', 1, 1, 80),
  ('rent', 'Loyer', 'disbursement', 'common_expense', 'shop', 1, 1, 90),
  ('telecom', 'Télécoms et internet', 'disbursement', 'common_expense', 'shop', 1, 1, 100),
  ('bank_fee', 'Frais bancaires', 'disbursement', 'common_expense', 'shop', 1, 1, 110),
  ('owner_contribution', 'Apport propriétaire', 'receipt', 'out_of_result', 'shop', 1, 1, 120),
  ('owner_withdrawal', 'Retrait propriétaire', 'disbursement', 'out_of_result', 'shop', 1, 1, 130),
  ('other_out_of_result', 'Autre hors résultat', 'both', 'out_of_result', 'unassigned', 1, 1, 140);
