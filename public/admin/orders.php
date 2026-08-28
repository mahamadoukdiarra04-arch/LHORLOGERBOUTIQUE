<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
try {
    ensure_accounting_schema();
} catch (Throwable $exception) {
    error_log('L’Horloger: initialisation de la livraison comptable échouée.');
    http_response_code(503);
    exit('La livraison comptable ne peut pas être préparée pour le moment. Réessayez dans quelques instants.');
}

function orders_statuses_without_delivery(): array {
    return ['À confirmer', 'Confirmée', 'En livraison', 'Annulée'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $channel = (string) ($_POST['channel'] ?? '');

    if ($orderId < 1 || $status === 'Livrée') {
        flash('error', 'Finalisez une livraison depuis son formulaire d’encaissement.');
    } elseif (!in_array($status, orders_statuses_without_delivery(), true)) {
        flash('error', 'Mise à jour impossible.');
    } elseif ($status !== 'À confirmer' && !in_array($channel, ['Meta', 'Réachat'], true)) {
        flash('error', 'Choisissez Meta ou Réachat avant de quitter « À confirmer ».');
    } else {
        try {
            $pdo->beginTransaction();
            $selected = $pdo->prepare('SELECT id, order_ref FROM orders WHERE id = ? FOR UPDATE');
            $selected->execute([$orderId]);
            $order = $selected->fetch();
            if (!$order) throw new RuntimeException('Commande introuvable.');

            $allLines = $pdo->prepare('SELECT id, status, stock_processed FROM orders WHERE order_ref = ? FOR UPDATE');
            $allLines->execute([$order['order_ref']]);
            foreach ($allLines->fetchAll() as $line) {
                if ($line['status'] === 'Livrée' || (int) ($line['stock_processed'] ?? 0) === 1) {
                    throw new RuntimeException('Une référence déjà livrée ne peut plus être modifiée depuis cette liste.');
                }
            }
            $update = $pdo->prepare('UPDATE orders SET status = ?, acquisition_channel = ? WHERE order_ref = ?');
            $update->execute([$status, $status === 'À confirmer' ? null : $channel, $order['order_ref']]);
            $pdo->commit();
            log_event('commande', 'Commande ' . $order['order_ref'] . ' mise à jour : ' . $status, null, $orderId);
            flash('success', 'Référence de commande mise à jour.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $messages = [
                'Commande introuvable.',
                'Une référence déjà livrée ne peut plus être modifiée depuis cette liste.',
            ];
            flash('error', in_array($exception->getMessage(), $messages, true) ? $exception->getMessage() : 'La mise à jour a échoué. Réessayez dans quelques instants.');
            error_log('L’Horloger: mise à jour de commande échouée.');
        }
    }
    redirect('/admin/orders.php?order=' . $orderId . '#order-detail-' . $orderId);
}

$statusFilter = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$selected = (int) ($_GET['order'] ?? 0);
$where = [];
$params = [];
if (in_array($statusFilter, ['À confirmer', 'Confirmée', 'En livraison', 'Livrée', 'Annulée'], true)) {
    $where[] = 'o.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = 'CONCAT(o.order_ref," ",o.customer_first_name," ",o.customer_last_name," ",o.phone," ",o.district," ",o.product_name," ",o.variant) LIKE ?';
    $params[] = '%' . $search . '%';
}
$sql = 'SELECT o.* FROM orders o' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.created_at DESC LIMIT 150';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$orders = $statement->fetchAll();

$selectedOrder = null;
foreach ($orders as $candidate) {
    if ($selected === (int) $candidate['id']) {
        $selectedOrder = $candidate;
        break;
    }
}
$selectedFinance = null;
$selectedFinanceTracked = false;
$selectedOpenException = false;
if ($selectedOrder && $selectedOrder['status'] === 'Livrée') {
    try {
        $selectedFinanceTracked = accounting_order_has_confirmed_entries($pdo, (string) $selectedOrder['order_ref']);
        if ($selectedFinanceTracked) {
            $selectedFinance = accounting_order_payment_summary($pdo, (string) $selectedOrder['order_ref']);
            $selectedOpenException = accounting_order_has_open_payment_exception($pdo, (string) $selectedOrder['order_ref']);
        }
    } catch (Throwable $exception) {
        error_log('L’Horloger: détail comptable de commande indisponible.');
        $selectedFinanceError = 'Le détail comptable de cette commande ne peut pas être préparé pour le moment.';
    }
}

$adminPageTitle = 'Commandes';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Commandes</p>
    <h1>Chaque détail à portée de main.</h1>
    <p>Le canal est requis dès que la commande est confirmée. La livraison et l’encaissement se font ensemble, pour toute la référence.</p>
  </div>
  <div class="metric"><p>À confirmer</p><strong><?= (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status='À confirmer'")->fetchColumn() ?></strong></div>
</header>

<form class="admin-filter" method="get">
  <input name="q" value="<?= e($search) ?>" placeholder="Client, référence ou quartier">
  <select name="status"><option value="">Tous les états</option><?php foreach (['À confirmer', 'Confirmée', 'En livraison', 'Livrée', 'Annulée'] as $option): ?><option <?= $statusFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
  <button class="admin-button">Filtrer</button>
</form>

<section class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Commande</th><th>Client</th><th>Produit</th><th>Canal</th><th>État</th><th>Montant</th><th></th></tr></thead><tbody>
<?php foreach ($orders as $order): ?>
  <tr>
    <td><strong><?= e($order['order_ref']) ?></strong><small><?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></small></td>
    <td><strong><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></strong><small><?= e($order['phone']) ?> · <?= e($order['district']) ?></small></td>
    <td><strong><?= e($order['product_name']) ?></strong><small><?= e($order['variant']) ?> · Qté <?= (int) $order['quantity'] ?></small></td>
    <td><?= e($order['acquisition_channel'] ?? 'À renseigner') ?></td>
    <td><span class="status <?= $order['status'] === 'Livrée' ? 'delivered' : ($order['status'] === 'En livraison' ? 'delivery' : '') ?>"><?= e($order['status']) ?></span></td>
    <td><strong><?= money($order['unit_price_fcfa'] * $order['quantity']) ?></strong></td>
    <td><a class="text-link" href="?<?= e(http_build_query(['q' => $search, 'status' => $statusFilter, 'order' => $order['id']])) ?>#order-detail-<?= (int) $order['id'] ?>">Détails</a></td>
  </tr>
  <?php if ($selected === (int) $order['id']): ?>
    <tr id="order-detail-<?= (int) $order['id'] ?>" class="order-detail-row"><td colspan="7"><section class="order-detail">
      <div class="order-detail-grid">
        <div class="fact"><span>Coloris</span><b><?= e($order['variant']) ?></b></div><div class="fact"><span>Quantité</span><b><?= (int) $order['quantity'] ?></b></div>
        <div class="fact"><span>Prix unitaire</span><b><?= money($order['unit_price_fcfa']) ?></b></div><div class="fact"><span>Livraison</span><b>Offerte à Bamako</b></div>
        <div class="fact"><span>Quartier</span><b><?= e($order['district']) ?></b></div><div class="fact"><span>Paiement</span><b>À la réception</b></div>
        <div class="fact"><span>Téléphone</span><b><?= e($order['phone']) ?></b></div><div class="fact"><span>Référence</span><b><?= e($order['order_ref']) ?></b></div>
      </div>
      <?php if ($order['status'] === 'Livrée'): ?>
        <p class="admin-copy">Cette référence a été livrée. Son historique financier est protégé contre toute modification directe.</p>
        <?php if (isset($selectedFinanceError)): ?>
          <p class="flash flash-error"><?= e($selectedFinanceError) ?></p>
        <?php elseif (!$selectedFinanceTracked): ?>
          <section class="order-finance finance-context"><p class="admin-kicker">Comptabilité</p><h3>Historique non initialisé.</h3><p>Cette livraison ne possède pas d’écriture comptable confirmée. Aucun encaissement ou solde n’est déduit des anciennes données.</p></section>
        <?php else: ?>
          <section class="order-finance finance-context"><div class="admin-panel__head"><div><p class="admin-kicker">Paiement réalisé · <?= e($selectedFinance['payment_state']) ?></p><h3>Suivi financier de la référence.</h3></div><a href="<?= e(url('/admin/accounting-journal.php?q=' . rawurlencode((string) $order['order_ref']))) ?>">Voir le Journal →</a></div><div class="order-detail-grid"><div class="fact"><span>Total de commande</span><b><?= money($selectedFinance['sale_total_fcfa']) ?></b></div><div class="fact"><span>Encaissements</span><b><?= money($selectedFinance['received_fcfa']) ?></b></div><div class="fact"><span>Remboursements</span><b><?= money($selectedFinance['refunded_fcfa']) ?></b></div><div class="fact"><span>Solde à suivre</span><b><?= money($selectedFinance['remaining_fcfa']) ?></b></div></div><?php if ($selectedOpenException): ?><p class="admin-copy">Un reliquat est ouvert pour cette référence. Il peut être régularisé depuis l’espace Comptabilité.</p><a class="text-link" href="<?= e(url('/admin/accounting-action.php?action=collect_balance')) ?>">Encaisser ce reliquat →</a><?php endif; ?></section>
        <?php endif; ?>
      <?php else: ?>
        <form class="inline-form" method="post">
          <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
          <label>Statut<select name="status"><?php foreach (orders_statuses_without_delivery() as $option): ?><option <?= $order['status'] === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
          <label>Canal d’acquisition<select name="channel"><option value="">À renseigner</option><?php foreach (['Meta', 'Réachat'] as $channel): ?><option <?= $order['acquisition_channel'] === $channel ? 'selected' : '' ?>><?= $channel ?></option><?php endforeach; ?></select></label>
          <button class="admin-button">Enregistrer</button>
          <?php if ($order['status'] !== 'Annulée'): ?><a class="admin-button" href="<?= e(url('/admin/accounting-delivery.php?order=' . (int) $order['id'])) ?>">Encaisser & livrer</a><?php endif; ?>
        </form>
      <?php endif; ?>
    </section></td></tr>
  <?php endif; ?>
<?php endforeach; ?>
<?php if (!$orders): ?><tr><td colspan="7">Aucune commande ne correspond à ce filtre.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
