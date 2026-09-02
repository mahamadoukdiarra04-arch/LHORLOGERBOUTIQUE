UPDATE accounting_categories
SET name = 'Livraison',
    direction = 'disbursement',
    treatment = 'common_expense',
    default_scope = 'shop',
    is_active = 1,
    sort_order = 65
WHERE code = 'delivery_cost';
