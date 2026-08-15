<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/orders.php');
}

verify_csrf();
$pdo = db();

// Target only the five temporary pixel checks created during production setup.
$find = $pdo->prepare(
    "SELECT id FROM orders
     WHERE customer_first_name LIKE 'TEST PIXEL %'
       AND customer_last_name = 'TEST'
       AND phone = '12345678'
       AND district = 'IC'"
);
$find->execute();
$ids = array_map('intval', array_column($find->fetchAll(), 'id'));

if (!$ids) {
    flash('success', 'Aucune commande de test à supprimer.');
    redirect('/admin/orders.php');
}

try {
    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // The event table can be absent on an early installation; it must not block cleanup.
    try {
        $events = $pdo->prepare("DELETE FROM admin_events WHERE order_id IN ($placeholders)");
        $events->execute($ids);
    } catch (Throwable $exception) {
        error_log('L’Horloger: historique de test non supprimé.');
    }

    $delete = $pdo->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
    $delete->execute($ids);
    $pdo->commit();
    flash('success', count($ids) . ' commande(s) de test supprimée(s).');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('L’Horloger: suppression des commandes de test échouée.');
    flash('error', 'Les commandes de test n’ont pas pu être supprimées.');
}

redirect('/admin/orders.php');
