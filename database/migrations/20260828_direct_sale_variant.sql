ALTER TABLE direct_sale_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id;
ALTER TABLE direct_sale_items ADD INDEX idx_direct_sale_items_variant (variant_id);

UPDATE direct_sale_items dsi
INNER JOIN product_variants pv ON pv.product_id = dsi.product_id AND pv.name = dsi.variant_snapshot
SET dsi.variant_id = pv.id
WHERE dsi.variant_id IS NULL AND dsi.variant_snapshot IS NOT NULL;
