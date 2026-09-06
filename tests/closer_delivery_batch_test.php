<?php
declare(strict_types=1);

function closer_batch_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/app/bootstrap.php');
$closer = file_get_contents($root . '/public/closer/index.php');
$download = file_get_contents($root . '/public/closer/delivery-sheet.php');
$management = file_get_contents($root . '/public/admin/orders.php');

foreach ([$bootstrap, $closer, $download, $management] as $source) {
    closer_batch_assert(is_string($source), 'Un fichier du parcours closeuse est illisible.');
}

closer_batch_assert(
    str_contains($bootstrap, 'closer_delivery_batches')
        && str_contains($bootstrap, 'closer_delivery_batch_orders')
        && str_contains($bootstrap, 'UNIQUE KEY uq_closer_delivery_order'),
    'Le bordereau en cours doit être persistant et empêcher les doublons.'
);
closer_batch_assert(
    str_contains($closer, "elseif (\$action === 'add_to_delivery')")
        && str_contains($closer, 'Commande ajoutée au bordereau')
        && str_contains($closer, "o.status NOT IN ('Annulée', 'Injoignable', 'Livrée')"),
    'La closeuse doit ajouter les commandes une par une et ne plus voir les états terminaux.'
);
closer_batch_assert(
    str_contains($download, "SET status = 'downloaded', draft_owner = NULL")
        && str_contains($download, "SET status = 'En livraison'")
        && str_contains($download, "batch.status = 'draft'")
        && !str_contains($download, "order_ids"),
    'Un téléchargement doit passer les commandes en livraison et clôturer le brouillon courant avant le suivant.'
);
closer_batch_assert(
    str_contains($management, "'Injoignable'")
        && str_contains($bootstrap, "WHEN o.status = 'Injoignable' THEN 'Injoignable'")
        && str_contains($bootstrap, "DELETE batch_item"),
    'Injoignable doit être visible en gestion et retiré du suivi et du bordereau actif.'
);

echo "closer_delivery_batch_test: OK\n";
