<?php
declare(strict_types=1);

function closer_edit_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$closer = file_get_contents($root . '/public/closer/index.php');
$script = file_get_contents($root . '/public/assets/js/closer.js');
$styles = file_get_contents($root . '/public/assets/css/closer.css');
$orders = file_get_contents($root . '/app/accounting_orders.php');

foreach ([$closer, $script, $styles, $orders] as $source) {
    closer_edit_assert(is_string($source), 'Un fichier de modification des commandes est illisible.');
}

closer_edit_assert(
    str_contains($closer, "elseif (\$action === 'edit_order')")
        && str_contains($closer, 'accounting_update_order_before_payment')
        && str_contains($closer, "['À confirmer', 'Confirmée']")
        && str_contains($closer, 'whatsapp_prepared_at = NULL'),
    'La closeuse doit pouvoir modifier uniquement une commande active et invalider un ancien message préparé.'
);
closer_edit_assert(
    str_contains($closer, 'data-closer-edit-toggle')
        && str_contains($closer, 'data-closer-order-edit-form')
        && str_contains($closer, 'data-closer-edit-product')
        && str_contains($closer, 'data-closer-edit-variant')
        && str_contains($closer, 'Enregistrer les modifications'),
    'La carte doit proposer un formulaire explicite pour le client, le produit, la couleur, la quantité et le prix.'
);
closer_edit_assert(
    str_contains($script, 'setupOrderEditing')
        && str_contains($script, 'data-closer-edit-cancel')
        && str_contains($script, 'updateTotal')
        && str_contains($styles, '.closer-edit-toggle')
        && str_contains($styles, 'min-height:48px'),
    'L’ouverture, le calcul du total et les cibles tactiles du formulaire doivent rester adaptés au mobile.'
);
closer_edit_assert(
    str_contains($orders, 'WHERE order_ref = ?')
        && str_contains($orders, 'WHERE id = ?')
        && str_contains($orders, 'Un encaissement comptable existe déjà'),
    'Les coordonnées de la référence et la ligne sélectionnée doivent être modifiées sans toucher aux ventes encaissées.'
);

echo "closer_order_edit_test: OK\n";
