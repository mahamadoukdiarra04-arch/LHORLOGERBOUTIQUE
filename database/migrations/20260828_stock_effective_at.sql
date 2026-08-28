ALTER TABLE stock_movements ADD COLUMN effective_at DATETIME NULL AFTER actor;
UPDATE stock_movements SET effective_at = created_at WHERE effective_at IS NULL;
ALTER TABLE stock_movements ADD INDEX idx_stock_product_effective (product_id, effective_at);
