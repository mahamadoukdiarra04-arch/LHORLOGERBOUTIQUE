INSERT INTO accounting_categories
  (code, name, direction, treatment, default_scope, is_system, is_active, sort_order)
VALUES
  ('delivery_cost', 'Livraison', 'disbursement', 'direct_expense', 'product', 1, 1, 65)
ON DUPLICATE KEY UPDATE is_active = 1;
