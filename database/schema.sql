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
  INDEX idx_orders_ref (order_ref), INDEX idx_orders_status (status), INDEX idx_orders_created (created_at),
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  movement_type ENUM('Réassort','Sortie','Ajustement') NOT NULL,
  quantity INT NOT NULL,
  purchase_price_fcfa INT UNSIGNED NULL,
  transit_price_fcfa INT UNSIGNED NULL,
  unit_cost_fcfa DECIMAL(12,2) NULL,
  note VARCHAR(255) NULL,
  actor VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stock_product_date (product_id, created_at),
  CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_costs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  channel ENUM('Meta') NOT NULL DEFAULT 'Meta',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  amount_fcfa INT UNSIGNED NOT NULL,
  actor VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ads_product_period (product_id, start_date, end_date),
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

INSERT IGNORE INTO products (id, slug, sku, name, price_fcfa) VALUES
  (1, 'nocturne-chrono', 'T-01', 'Nocturne Chrono', 52000),
  (2, 'azur-squelette', 'T-02', 'Azur Squelette', 62000),
  (3, 'eclipse-lunaire', 'T-03', 'Éclipse Lunaire', 59000);

INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price_fcfa, transit_price_fcfa, unit_cost_fcfa, note, actor)
SELECT 1, 'Réassort', 18, 468000, 81000, 30500, 'Stock initial', 'Système'
WHERE NOT EXISTS (SELECT 1 FROM stock_movements WHERE product_id=1);
INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price_fcfa, transit_price_fcfa, unit_cost_fcfa, note, actor)
SELECT 2, 'Réassort', 8, 272000, 36800, 38600, 'Stock initial', 'Système'
WHERE NOT EXISTS (SELECT 1 FROM stock_movements WHERE product_id=2);
INSERT INTO stock_movements (product_id, movement_type, quantity, purchase_price_fcfa, transit_price_fcfa, unit_cost_fcfa, note, actor)
SELECT 3, 'Réassort', 4, 128000, 16800, 36200, 'Stock initial', 'Système'
WHERE NOT EXISTS (SELECT 1 FROM stock_movements WHERE product_id=3);
