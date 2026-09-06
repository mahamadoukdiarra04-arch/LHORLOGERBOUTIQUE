<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
try {
    ensure_accounting_schema();
    ensure_closer_schema();
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
    $formAction = (string) ($_POST['form_action'] ?? 'update_status');

    if ($formAction === 'edit_order') {
        try {
            $result = accounting_update_order_before_payment($pdo, $orderId, $_POST, accounting_current_user_id());
            flash('success', 'Commande ' . $result['order_ref'] . ' modifiée. Le nouveau total sera repris à l’encaissement.');
        } catch (Throwable $exception) {
            error_log('L’Horloger: modification avant encaissement échouée.');
            flash('error', accounting_safe_error_message($exception, 'La commande n’a pas pu être modifiée. Réessayez dans quelques instants.'));
        }
        redirect('/admin/orders.php?order=' . $orderId . '#order-detail-' . $orderId);
    }

    $status = (string) ($_POST['status'] ?? '');
    $channel = (string) ($_POST['channel'] ?? '');

    if ($orderId < 1 || $status === 'Livrée') {
        flash('error', 'Finalisez une livraison depuis son formulaire d’encaissement.');
    } elseif (!in_array($status, orders_statuses_without_delivery(), true)) {
        flash('error', 'Mise à jour impossible.');
    } elseif (in_array($status, ['Confirmée', 'En livraison'], true) && !in_array($channel, ['Meta', 'Réachat'], true)) {
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
            if ($status === 'Annulée') {
                $update = $pdo->prepare('UPDATE orders SET status = ? WHERE order_ref = ?');
                $update->execute([$status, $order['order_ref']]);
            } else {
                $update = $pdo->prepare('UPDATE orders SET status = ?, acquisition_channel = ? WHERE order_ref = ?');
                $update->execute([$status, $status === 'À confirmer' ? null : $channel, $order['order_ref']]);
            }
            sync_closer_tracking_for_order_ref($pdo, (string) $order['order_ref']);
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
$orderEditCatalog = accounting_order_edit_catalog($pdo);

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
$selectedEditability = null;
if ($selectedOrder) {
    $selectedEditability = accounting_order_editability($pdo, (string) $selectedOrder['order_ref']);
}
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

<section class="admin-panel"><div class="admin-table-wrap mobile-card-table-wrap"><table class="admin-table mobile-card-table orders-table"><thead><tr><th>Commande</th><th>Client</th><th>Produit</th><th>Canal</th><th>État</th><th>Montant</th><th></th></tr></thead><tbody>
<?php foreach ($orders as $order): ?>
  <?php $isExpanded = $selected === (int) $order['id']; ?>
  <tr id="order-card-<?= (int) $order['id'] ?>" class="mobile-card-row <?= $isExpanded ? 'is-expanded' : '' ?>">
    <td data-label="Commande"><strong><?= e($order['order_ref']) ?></strong><small><?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></small></td>
    <td data-label="Client"><strong><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></strong><small><?= e($order['phone']) ?> · <?= e($order['district']) ?></small></td>
    <td data-label="Produit"><strong><?= e($order['product_name']) ?></strong><small><?= e($order['variant']) ?> · Qté <?= (int) $order['quantity'] ?></small></td>
    <td data-label="Canal"><?= e($order['acquisition_channel'] ?? 'À renseigner') ?></td>
    <td data-label="État"><span class="status <?= $order['status'] === 'Livrée' ? 'delivered' : ($order['status'] === 'En livraison' ? 'delivery' : '') ?>"><?= e($order['status']) ?></span></td>
    <td data-label="Montant"><strong><?= money($order['unit_price_fcfa'] * $order['quantity']) ?></strong></td>
    <td class="order-actions">
      <?php if ($isExpanded): ?>
        <a class="text-link" href="?<?= e(http_build_query(['q' => $search, 'status' => $statusFilter])) ?>#order-card-<?= (int) $order['id'] ?>" aria-expanded="true" aria-controls="order-detail-<?= (int) $order['id'] ?>">Fermer les détails <span class="order-action-arrow" aria-hidden="true">↑</span></a>
      <?php else: ?>
        <a class="text-link" href="?<?= e(http_build_query(['q' => $search, 'status' => $statusFilter, 'order' => $order['id']])) ?>#order-card-<?= (int) $order['id'] ?>" aria-expanded="false" aria-controls="order-detail-<?= (int) $order['id'] ?>">Voir les détails <span class="order-action-arrow" aria-hidden="true">↓</span></a>
      <?php endif; ?>
    </td>
  </tr>
  <?php if ($isExpanded): ?>
    <tr id="order-detail-<?= (int) $order['id'] ?>" class="order-detail-row"><td class="order-detail-cell" colspan="7"><section class="order-detail">
      <header class="order-detail__heading"><span>Détail ouvert</span><strong><?= e($order['product_name']) ?> · <?= e($order['variant']) ?></strong></header>
      <div class="order-detail-grid">
        <section class="order-detail-group">
          <h3 class="order-detail-group-title">Produit commandé</h3>
          <div class="order-detail-facts">
            <div class="fact"><span>Coloris</span><b><?= e($order['variant']) ?></b></div><div class="fact"><span>Quantité</span><b><?= (int) $order['quantity'] ?></b></div>
            <div class="fact fact--wide-mobile"><span>Prix unitaire</span><b><?= money($order['unit_price_fcfa']) ?></b></div>
          </div>
        </section>
        <section class="order-detail-group">
          <h3 class="order-detail-group-title">Client et livraison</h3>
          <div class="order-detail-facts">
            <div class="fact"><span>Livraison</span><b>Offerte à Bamako</b></div><div class="fact"><span>Paiement</span><b>À la réception</b></div>
            <div class="fact"><span>Quartier</span><b><?= e($order['district']) ?></b></div><div class="fact"><span>Téléphone</span><b><a href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a></b></div>
            <div class="fact fact--wide-mobile"><span>Référence</span><b><?= e($order['order_ref']) ?></b></div>
          </div>
        </section>
      </div>
      <?php if ($selectedEditability && $selectedEditability['editable']): ?>
        <?php $currentVariants = $orderEditCatalog[(int) $order['product_id']]['variants'] ?? []; ?>
        <section class="order-edit-panel">
          <div class="order-edit-panel__head">
            <div><p class="admin-kicker">Avant encaissement</p><h3>Modifier la commande.</h3></div>
            <span>Les coordonnées s’appliquent à toute la référence.</span>
          </div>
          <form class="order-edit-form" method="post" data-order-edit-form>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="edit_order">
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
            <fieldset>
              <legend>Client et livraison</legend>
              <div class="order-edit-fields">
                <label>Prénom<input name="customer_first_name" value="<?= e($order['customer_first_name']) ?>" maxlength="100" required></label>
                <label>Nom<input name="customer_last_name" value="<?= e($order['customer_last_name']) ?>" maxlength="100" required></label>
                <label>Téléphone<input name="phone" value="<?= e($order['phone']) ?>" maxlength="32" inputmode="tel" required></label>
                <label>Quartier<input name="district" value="<?= e($order['district']) ?>" maxlength="150" required></label>
              </div>
            </fieldset>
            <fieldset>
              <legend>Ligne de commande</legend>
              <p>Le produit et le coloris concernent uniquement cette ligne de la référence.</p>
              <div class="order-edit-fields">
                <label>Produit<select name="product_id" data-order-edit-product required><?php foreach ($orderEditCatalog as $product): ?><option value="<?= (int) $product['id'] ?>" data-price="<?= (int) $product['price_fcfa'] ?>" <?= (int) $order['product_id'] === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select></label>
                <label>Coloris<select name="variant_id" data-order-edit-variant required><?php foreach ($currentVariants as $variant): ?><option value="<?= (int) $variant['id'] ?>" <?= ((int) ($order['variant_id'] ?? 0) === (int) $variant['id'] || ((int) ($order['variant_id'] ?? 0) === 0 && $order['variant'] === $variant['name'])) ? 'selected' : '' ?>><?= e($variant['name']) ?> · stock <?= (int) $variant['stock_quantity'] ?></option><?php endforeach; ?></select></label>
                <label>Quantité<input type="number" name="quantity" value="<?= (int) $order['quantity'] ?>" min="1" max="100" inputmode="numeric" required></label>
                <label>Prix unitaire FCFA<input type="number" name="unit_price_fcfa" value="<?= (int) $order['unit_price_fcfa'] ?>" min="1" max="100000000" inputmode="numeric" data-order-edit-price required></label>
              </div>
            </fieldset>
            <div class="order-edit-total"><span>Nouveau total de cette ligne</span><strong data-order-edit-total><?= money((int) $order['quantity'] * (int) $order['unit_price_fcfa']) ?></strong></div>
            <button class="admin-button" type="submit">Enregistrer les modifications</button>
          </form>
        </section>
      <?php elseif ($selectedEditability && $order['status'] !== 'Livrée'): ?>
        <section class="order-edit-panel is-locked"><p class="admin-kicker">Modification verrouillée</p><h3>Cette commande ne peut plus être modifiée.</h3><p><?= e((string) $selectedEditability['reason']) ?></p></section>
      <?php endif; ?>
      <h3 class="order-detail-group-title order-detail-actions-title">Suivi et actions</h3>
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
          <?= csrf_field() ?><input type="hidden" name="form_action" value="update_status"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
          <label>Statut<select name="status"><?php foreach (orders_statuses_without_delivery() as $option): ?><option <?= $order['status'] === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
          <label>Canal d’acquisition<select name="channel"><option value="">À renseigner</option><?php foreach (['Meta', 'Réachat'] as $channel): ?><option <?= $order['acquisition_channel'] === $channel ? 'selected' : '' ?>><?= $channel ?></option><?php endforeach; ?></select></label>
          <button class="admin-button">Enregistrer</button>
          <?php if ($order['status'] !== 'Annulée'): ?><a class="admin-button" href="<?= e(url('/admin/accounting-delivery.php?order=' . (int) $order['id'])) ?>">Encaisser & livrer</a><?php endif; ?>
        </form>
      <?php endif; ?>
      <a class="order-detail-close" href="?<?= e(http_build_query(['q' => $search, 'status' => $statusFilter])) ?>#order-card-<?= (int) $order['id'] ?>">Fermer les détails <span class="order-action-arrow" aria-hidden="true">↑</span></a>
    </section></td></tr>
  <?php endif; ?>
<?php endforeach; ?>
<?php if (!$orders): ?><tr class="mobile-card-empty"><td colspan="7">Aucune commande ne correspond à ce filtre.</td></tr><?php endif; ?>
</tbody></table></div></section>
<script id="order-edit-catalog" type="application/json"><?= json_encode(array_values($orderEditCatalog), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php $ordersScriptVersion = (string) (@filemtime(dirname(APP_ROOT) . '/public/assets/js/admin-orders.js') ?: '1'); ?>
<script src="<?= e(url('/assets/js/admin-orders.js?v=' . $ordersScriptVersion)) ?>" defer></script>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
