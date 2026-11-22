<?php
// Temporary, manager-only maintenance for the single deletion approved by the owner.
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
if (new DateTimeImmutable('now', new DateTimeZone('UTC')) >= new DateTimeImmutable('2026-11-23T00:00:00Z')) {
    http_response_code(410); exit('Cette opération temporaire a expiré.');
}
$pdo = db();
ensure_accounting_schema();
ensure_closer_schema();
const CLEANUP_ORDER_REF = 'HOR-261120-803779';
const CLEANUP_ORDER_ID = 717;
function cleanup_invalid_order(PDO $pdo, bool $lock = false): array {
    $find = $pdo->prepare('SELECT id, order_ref, customer_first_name, customer_last_name, status, stock_processed FROM orders WHERE order_ref = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $find->execute([CLEANUP_ORDER_REF]);
    $rows = $find->fetchAll();
    if ($rows === []) return [];
    if (count($rows) !== 1 || (int) $rows[0]['id'] !== CLEANUP_ORDER_ID || $rows[0]['customer_first_name'] !== 'Mmmmpmm' || $rows[0]['status'] !== 'Annulée' || (int) $rows[0]['stock_processed'] !== 0) {
        throw new RuntimeException('L’identité ou l’état de la commande a changé. Suppression bloquée.');
    }
    $checks = [
        ['SELECT COUNT(*) FROM stock_movements WHERE order_id = ?', CLEANUP_ORDER_ID],
        ['SELECT COUNT(*) FROM accounting_allocations WHERE order_id = ?', CLEANUP_ORDER_ID],
        ['SELECT COUNT(*) FROM accounting_operation_groups WHERE order_ref = ?', CLEANUP_ORDER_REF],
        ['SELECT COUNT(*) FROM direct_sales WHERE linked_order_ref = ?', CLEANUP_ORDER_REF],
        ['SELECT COUNT(*) FROM accounting_payment_exceptions WHERE order_ref = ?', CLEANUP_ORDER_REF],
        ["SELECT COUNT(*) FROM meta_capi_events WHERE order_ref = ? AND event_key IN ('confirmed', 'purchase')", CLEANUP_ORDER_REF],
    ];
    foreach ($checks as [$sql, $value]) {
        $check = $pdo->prepare($sql); $check->execute([$value]);
        if ((int) $check->fetchColumn() !== 0) throw new RuntimeException('La commande comporte un historique financier, de stock ou de conversion. Suppression bloquée.');
    }
    return $rows;
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (($_POST['confirmation'] ?? '') !== CLEANUP_ORDER_REF) throw new RuntimeException('La référence de confirmation ne correspond pas.');
        $deleted = accounting_with_transaction($pdo, function () use ($pdo): bool {
            if (cleanup_invalid_order($pdo, true) === []) return false;
            foreach ([
                ['DELETE FROM closer_delivery_batch_orders WHERE order_id = ?', CLEANUP_ORDER_ID],
                ['DELETE FROM order_closer_tracking WHERE order_id = ?', CLEANUP_ORDER_ID],
                ['DELETE FROM closer_events WHERE order_id = ?', CLEANUP_ORDER_ID],
                ['DELETE FROM admin_events WHERE order_id = ?', CLEANUP_ORDER_ID],
                ['DELETE FROM meta_capi_events WHERE order_ref = ?', CLEANUP_ORDER_REF],
                ['DELETE FROM order_archives WHERE order_ref = ?', CLEANUP_ORDER_REF],
            ] as [$sql, $value]) { $delete = $pdo->prepare($sql); $delete->execute([$value]); }
            $hasJobs = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_push_jobs'")->fetchColumn();
            if ((int) $hasJobs > 0) { $jobs = $pdo->prepare('DELETE FROM web_push_jobs WHERE event_key = ?'); $jobs->execute(['order-' . CLEANUP_ORDER_REF]); }
            $delete = $pdo->prepare("DELETE FROM orders WHERE id = ? AND order_ref = ? AND status = 'Annulée' AND stock_processed = 0");
            $delete->execute([CLEANUP_ORDER_ID, CLEANUP_ORDER_REF]);
            if ($delete->rowCount() !== 1) throw new RuntimeException('La commande a changé. Suppression annulée.');
            accounting_audit($pdo, 'delete_invalid_order', 'order_reference', CLEANUP_ORDER_ID, null, ['order_ref' => CLEANUP_ORDER_REF, 'confirmed_by_owner' => true, 'ledger_unchanged' => true, 'stock_unchanged' => true], accounting_current_user_id());
            return true;
        });
        $success = $deleted ? 'Commande définitivement supprimée. Aucun encaissement ni stock modifié.' : 'La commande est déjà absente de la base.';
    }
    $rows = cleanup_invalid_order($pdo);
} catch (Throwable $exception) {
    $error = accounting_safe_error_message($exception, 'Opération bloquée. Aucune suppression conservée.');
}
$adminPageTitle = 'Contrôle de suppression';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head"><div><p class="admin-kicker">Maintenance ponctuelle</p><h1>Commande indésirable.</h1></div></header>
<?php if (isset($error)): ?><p class="flash flash-error"><?= e($error) ?></p><?php elseif (isset($success)): ?><p class="flash flash-success"><?= e($success) ?></p><?php endif; ?>
<section class="admin-panel"><h2><?= e(CLEANUP_ORDER_REF) ?></h2>
<?php if (!isset($error) && $rows === []): ?><p>Contrôle base de données : 0 commande restante pour cette référence.</p><a class="admin-button" href="<?= e(url('/admin/orders.php?q=' . rawurlencode(CLEANUP_ORDER_REF))) ?>">Vérifier dans les commandes</a>
<?php elseif (!isset($error)): ?><p><?= e($rows[0]['customer_first_name'] . ' ' . $rows[0]['customer_last_name']) ?></p><p>Commande annulée. Aucune écriture comptable, aucun mouvement de stock, aucune conversion confirmée ou achat.</p><form method="post" class="quick-money-form"><?= csrf_field() ?><label>Référence à supprimer définitivement<input name="confirmation" required autocomplete="off"></label><button class="admin-button accounting-danger">Supprimer définitivement cette commande uniquement</button></form><?php endif; ?></section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
