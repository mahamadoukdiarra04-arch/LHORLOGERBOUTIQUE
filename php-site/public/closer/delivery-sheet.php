<?php
require __DIR__ . '/../../app/bootstrap.php';
require_closer();
require APP_ROOT . '/catalog.php';
require APP_ROOT . '/delivery-pdf.php';
try {
    ensure_closer_schema();
} catch (Throwable $exception) {
    error_log('L’Horloger: bordereau closeuse temporairement indisponible.');
    http_response_code(503);
    header('Retry-After: 15');
    exit('La connexion est momentanément indisponible. Le bordereau n’a pas été créé. Réessayez dans quelques instants.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/closer/');
verify_csrf();

$date = (string) ($_POST['delivery_date'] ?? '');
$dateObject = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    flash('error', 'Choisissez une date valide pour le bordereau.');
    redirect('/closer/');
}
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['order_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
if (!$ids) {
    flash('error', 'Sélectionnez au moins une commande confirmée.');
    redirect('/closer/');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
try {
    $statement = db()->prepare(
        "SELECT o.*, p.slug
         FROM orders o
         JOIN order_closer_tracking t ON t.order_id = o.id
         JOIN products p ON p.id = o.product_id
         WHERE t.closer_identity = ?
           AND t.follow_up_status = 'Confirmée'
           AND o.status = 'Confirmée'
           AND o.id IN ($placeholders)
         ORDER BY o.created_at ASC"
    );
    $statement->execute(array_merge([admin_identity()], $ids));
    $rows = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('L’Horloger: préparation du bordereau closeuse échouée.');
    flash('error', closer_safe_error_message($exception));
    redirect('/closer/');
}
if (!$rows) {
    flash('error', 'Aucune commande confirmée correspondante à imprimer.');
    redirect('/closer/');
}

$catalog = catalog();
$orders = array_map(static function (array $row) use ($catalog): array {
    $slug = (string) $row['slug'];
    return [
        'order_ref' => $row['order_ref'],
        'customer' => trim($row['customer_first_name'] . ' ' . $row['customer_last_name']),
        'phone' => $row['phone'],
        'district' => $row['district'],
        'product' => $row['product_name'],
        'variant' => $row['variant'],
        'quantity' => (int) $row['quantity'],
        'amount' => money((int) $row['quantity'] * (int) $row['unit_price_fcfa']),
        'image' => $catalog[$slug]['variants'][$row['variant']] ?? $catalog[$slug]['image'] ?? 'products/nocturne-chrono.jpg',
    ];
}, $rows);
$pdf = delivery_sheet_pdf($orders, $date, dirname(APP_ROOT) . '/public');
$filename = 'commandes-du-' . $date . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
