<?php
declare(strict_types=1);

function closer_whatsapp_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/app/bootstrap.php');
$closer = file_get_contents($root . '/public/closer/index.php');
$script = file_get_contents($root . '/public/assets/js/closer.js');
$admin = file_get_contents($root . '/public/admin/closer.php');
$legacyRoute = file_get_contents($root . '/public/closer/delivery-sheet.php');
$management = file_get_contents($root . '/public/admin/orders.php');

foreach ([$bootstrap, $closer, $script, $admin, $legacyRoute, $management] as $source) {
    closer_whatsapp_assert(is_string($source), 'Un fichier du parcours closeuse est illisible.');
}

closer_whatsapp_assert(
    str_contains($bootstrap, 'whatsapp_sent_at')
        && str_contains($bootstrap, "status = 'replaced'")
        && str_contains($closer, "elseif (\$action === 'mark_whatsapp_sent')"),
    'Le nouveau parcours doit conserver la date d’envoi et désactiver les anciens brouillons.'
);
closer_whatsapp_assert(
    str_contains($closer, "SET status = 'En livraison'")
        && str_contains($closer, "o.status NOT IN ('Annulée', 'En livraison', 'Livrée')")
        && str_contains($closer, '*PRIX À ENCAISSER')
        && str_contains($closer, 'data-whatsapp-share')
        && !str_contains($closer, 'add_to_delivery')
        && !str_contains($closer, 'delivery-selection'),
    'Le message envoyé doit remplacer le bordereau, passer la commande en livraison et la retirer du suivi.'
);
closer_whatsapp_assert(
    str_contains($script, 'createDeliveryImage')
        && str_contains($script, 'navigator.share')
        && str_contains($script, 'files: [file]')
        && str_contains($script, 'form.requestSubmit()')
        && str_contains($script, 'data-whatsapp-fallback-confirm'),
    'Le partage mobile doit joindre la fiche illustrée et prévoir une confirmation de secours.'
);
closer_whatsapp_assert(
    str_contains($admin, 'DATE(whatsapp_sent_at)')
        && str_contains($admin, 'Messages envoyés')
        && str_contains($legacyRoute, "redirect('/closer/')"),
    'La gestion doit mesurer les envois réels et l’ancienne route ne doit plus produire de PDF.'
);
closer_whatsapp_assert(
    str_contains($closer, "UPDATE orders SET status = 'Annulée' WHERE order_ref = ?")
        && !str_contains($management, "'Injoignable'"),
    'Injoignable doit toujours annuler la commande en gestion et la retirer du suivi actif.'
);

echo "closer_whatsapp_delivery_test: OK\n";
