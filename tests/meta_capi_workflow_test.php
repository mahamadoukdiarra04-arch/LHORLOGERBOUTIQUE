<?php
declare(strict_types=1);

function meta_capi_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$meta = file_get_contents($root . '/app/meta_capi.php');
$checkout = file_get_contents($root . '/public/checkout.php');
$success = file_get_contents($root . '/public/order-success.php');
$closer = file_get_contents($root . '/public/closer/index.php');
$management = file_get_contents($root . '/public/admin/orders.php');
$delivery = file_get_contents($root . '/app/accounting_delivery.php');
$balance = file_get_contents($root . '/app/accounting_sales.php');
$schema = file_get_contents($root . '/database/schema.sql');

foreach ([$meta, $checkout, $success, $closer, $management, $delivery, $balance, $schema] as $source) {
    meta_capi_assert(is_string($source), 'Un fichier du parcours Meta est illisible.');
}

meta_capi_assert(
    str_contains($meta, "'confirmed' => 'ConfirmedOrder'")
        && str_contains($meta, "'purchase' => 'Purchase'")
        && str_contains($meta, "'lead' => 'Lead'"),
    'Les trois évènements métier doivent être clairement séparés.'
);
meta_capi_assert(
    str_contains($meta, 'meta_capi_events')
        && str_contains($meta, 'UNIQUE KEY uq_meta_capi_order_event')
        && str_contains($meta, "status IN ('pending', 'failed')"),
    'Les évènements Meta doivent être mis en file et dédupliqués par commande.'
);
meta_capi_assert(
    str_contains($meta, "'fbp'")
        && str_contains($meta, "'fbc'")
        && str_contains($meta, "hash('sha256'")
        && str_contains($meta, 'CURLOPT_TIMEOUT'),
    'Le CAPI doit transmettre les identifiants de rapprochement, hacher les PII et rester borné.'
);
meta_capi_assert(
    str_contains($checkout, "meta_capi_queue_order_event(\$pdo, \$ref, 'lead')")
        && str_contains($success, "'Lead'")
        && str_contains($success, 'eventID'),
    'La demande doit être envoyée par serveur et navigateur avec le même identifiant de déduplication.'
);
meta_capi_assert(
    str_contains($closer, "meta_capi_queue_order_event(\$pdo, (string) \$order['order_ref'], 'confirmed')")
        && str_contains($management, "meta_capi_queue_order_event(\$pdo, (string) \$order['order_ref'], 'confirmed')"),
    'La confirmation closeuse et gestion doit produire le même signal ConfirmedOrder.'
);
meta_capi_assert(
    str_contains($delivery, "'purchase'")
        && str_contains($delivery, "paid_fcfa'] === (int) \$result['total_fcfa']")
        && str_contains($balance, "'purchase'"),
    'Purchase ne doit partir qu’après encaissement complet, y compris après régularisation.'
);
meta_capi_assert(
    str_contains($schema, 'meta_client_user_agent') && str_contains($schema, 'meta_capi_events'),
    'Le schéma neuf doit inclure les données de rapprochement et la file CAPI.'
);

echo "meta_capi_workflow_test: OK\n";
