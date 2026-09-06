<?php
require __DIR__ . '/../../app/bootstrap.php';
require_closer();
require_once APP_ROOT . '/catalog.php';
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
$batchId = (int) ($_POST['batch_id'] ?? 0);
if ($batchId < 1) {
    flash('error', 'Le bordereau en cours est invalide. Rechargez la page.');
    redirect('/closer/');
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $batchStatement = $pdo->prepare(
        "SELECT * FROM closer_delivery_batches
         WHERE id = ? AND closer_identity = ? AND status = 'draft'
         FOR UPDATE"
    );
    $batchStatement->execute([$batchId, admin_identity()]);
    $batch = $batchStatement->fetch();
    if (!$batch) throw new RuntimeException('Ce bordereau a déjà été téléchargé ou n’est plus disponible.');

    $statement = $pdo->prepare(
        "SELECT o.*, p.slug
         FROM closer_delivery_batch_orders batch_item
         JOIN orders o ON o.id = batch_item.order_id
         JOIN order_closer_tracking t ON t.order_id = o.id
         JOIN products p ON p.id = o.product_id
         JOIN closer_delivery_batches batch ON batch.id = batch_item.batch_id
         WHERE t.closer_identity = ?
           AND t.follow_up_status = 'Confirmée'
           AND o.status = 'Confirmée'
           AND batch.id = ?
           AND batch.status = 'draft'
         ORDER BY o.created_at ASC"
    );
    $statement->execute([admin_identity(), $batchId]);
    $rows = $statement->fetchAll();
    if (!$rows) throw new RuntimeException('Ajoutez au moins une commande confirmée au bordereau.');

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
            'image' => catalog_order_preview_image($catalog, $slug, (string) $row['variant']),
        ];
    }, $rows);
    $pdf = delivery_sheet_pdf($orders, $date, dirname(APP_ROOT) . '/public');

    $closeBatch = $pdo->prepare(
        "UPDATE closer_delivery_batches
         SET status = 'downloaded', draft_owner = NULL, delivery_date = ?, downloaded_at = NOW()
         WHERE id = ? AND status = 'draft'"
    );
    $closeBatch->execute([$date, $batchId]);
    if ($closeBatch->rowCount() !== 1) throw new RuntimeException('Le bordereau n’a pas pu être clôturé. Rechargez la page.');
    foreach ($rows as $row) {
        log_closer_event((int) $row['id'], 'Bordereau téléchargé', 'Commande incluse dans le bordereau du ' . date('d/m/Y', strtotime($date)) . '.');
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('L’Horloger: préparation du bordereau closeuse échouée.');
    flash('error', closer_safe_error_message($exception));
    redirect('/closer/');
}
$filename = 'commandes-du-' . $date . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
