<?php
require __DIR__ . '/../../app/bootstrap.php';
require_closer();
require_once APP_ROOT . '/catalog.php';
try {
    ensure_closer_schema();
    ensure_accounting_schema();
    $pdo = db();
} catch (Throwable $exception) {
    error_log('L’Horloger: espace closeuse temporairement indisponible.');
    http_response_code(503);
    header('Retry-After: 15');
    exit('La connexion est momentanément indisponible. Aucune action n’a été enregistrée. Rechargez la page puis réessayez dans quelques instants.');
}
$closer = admin_identity();
$trackingStates = ['À appeler', 'À rappeler', 'Confirmée', 'Injoignable', 'Annulée'];
$channels = ['Meta', 'Réachat'];

function closer_datetime(?string $value): ?string {
    if (!$value) return null;
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}
function closer_image(array $order, array $catalog): string {
    return catalog_order_preview_image(
        $catalog,
        (string) ($order['slug'] ?? ''),
        (string) ($order['variant'] ?? '')
    );
}
function closer_whatsapp_message(array $order): string {
    $amount = money((int) $order['quantity'] * (int) $order['unit_price_fcfa']);
    return "L’HORLOGER · LIVRAISON\n"
        . "Référence : {$order['order_ref']}\n"
        . "Client : {$order['customer_first_name']} {$order['customer_last_name']}\n"
        . "Téléphone : {$order['phone']}\n"
        . "Quartier : {$order['district']}\n"
        . "Montre : {$order['product_name']}\n"
        . "Couleur : {$order['variant']}\n"
        . "Quantité : {$order['quantity']}\n\n"
        . "*PRIX À ENCAISSER : {$amount}*";
}
function closer_whatsapp_link(array $order, string $number): string {
    $phone = preg_replace('/\D+/', '', $number);
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode(closer_whatsapp_message($order));
}
function closer_relative_time(string $value): string {
    try {
        $then = new DateTimeImmutable($value, accounting_bamako_timezone());
        $now = new DateTimeImmutable('now', accounting_bamako_timezone());
        $minutes = max(0, intdiv($now->getTimestamp() - $then->getTimestamp(), 60));
        if ($minutes < 2) return 'à l’instant';
        if ($minutes < 60) return 'il y a ' . $minutes . ' min';
        $hours = intdiv($minutes, 60);
        if ($hours < 24) return 'il y a ' . $hours . ' h';
        $days = intdiv($hours, 24);
        return $days === 1 ? 'il y a 1 jour' : 'il y a ' . $days . ' jours';
    } catch (Throwable) {
        return 'date indisponible';
    }
}
function closer_order_time(string $value): string {
    try {
        return (new DateTimeImmutable($value, accounting_bamako_timezone()))->format('d/m/Y · H:i');
    } catch (Throwable) {
        return 'Heure indisponible';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $orderId = (int) ($_POST['order_id'] ?? 0);

    try {
        if ($orderId < 1) throw new RuntimeException('Commande invalide.');
        $orderStatement = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $pdo->beginTransaction();
        $orderStatement->execute([$orderId]);
        $order = $orderStatement->fetch();
        if (!$order) throw new RuntimeException('Commande introuvable.');

        $trackingStatement = $pdo->prepare('SELECT * FROM order_closer_tracking WHERE order_id = ? FOR UPDATE');
        $trackingStatement->execute([$orderId]);
        $tracking = $trackingStatement->fetch();

        if ($action === 'claim') {
            if ($order['status'] !== 'À confirmer') throw new RuntimeException('Seules les nouvelles commandes peuvent être ajoutées au suivi.');
            if ($tracking && $tracking['closer_identity'] !== $closer) throw new RuntimeException('Cette commande est déjà suivie par une autre closeuse.');
            $assign = $pdo->prepare(
                "INSERT INTO order_closer_tracking (order_id, closer_identity, follow_up_status)
                 VALUES (?, ?, 'À appeler')
                 ON DUPLICATE KEY UPDATE closer_identity = VALUES(closer_identity), follow_up_status = IF(follow_up_status = 'À appeler', follow_up_status, 'À appeler')"
            );
            $assign->execute([$orderId, $closer]);
            log_closer_event($orderId, 'Ajout au suivi', 'Commande ajoutée à la liste personnelle.');
            $pdo->commit();
            flash('success', 'Commande ajoutée à votre suivi.');
        } elseif (!$tracking || $tracking['closer_identity'] !== $closer) {
            throw new RuntimeException('Ajoutez d’abord cette commande à votre suivi.');
        } elseif ($action === 'edit_order') {
            if (!in_array($order['status'], ['À confirmer', 'Confirmée'], true)) {
                throw new RuntimeException('Cette commande ne peut plus être modifiée à cette étape.');
            }
            $result = accounting_update_order_before_payment($pdo, $orderId, $_POST, accounting_current_user_id());
            $resetPreparedMessage = $pdo->prepare(
                'UPDATE order_closer_tracking t
                 JOIN orders o ON o.id = t.order_id
                 SET t.whatsapp_prepared_at = NULL, t.whatsapp_sent_at = NULL
                 WHERE o.order_ref = ?'
            );
            $resetPreparedMessage->execute([$order['order_ref']]);
            log_closer_event($orderId, 'Commande modifiée', 'Coordonnées ou contenu de la commande corrigés avant livraison.');
            $pdo->commit();
            flash('success', 'Commande ' . $result['order_ref'] . ' modifiée. Vérifiez puis préparez le message du livreur.');
        } elseif ($action === 'update_follow_up') {
            if (in_array($order['status'], ['Annulée', 'En livraison', 'Livrée'], true)) {
                throw new RuntimeException('Cette commande est déjà ' . strtolower((string) $order['status']) . ' et a été retirée de votre suivi actif.');
            }
            $state = (string) ($_POST['follow_up_status'] ?? '');
            $note = trim((string) ($_POST['note'] ?? ''));
            $followUp = closer_datetime((string) ($_POST['follow_up_at'] ?? ''));
            $channel = (string) ($_POST['channel'] ?? '');
            if (!in_array($state, $trackingStates, true)) throw new RuntimeException('Statut de suivi invalide.');
            if ((string) ($_POST['follow_up_at'] ?? '') !== '' && !$followUp) throw new RuntimeException('Date de rappel invalide.');
            if ($state === 'Confirmée' && !in_array($channel, $channels, true)) throw new RuntimeException('Choisissez Meta ou Réachat pour confirmer.');

            $updateTracking = $pdo->prepare('UPDATE order_closer_tracking SET follow_up_status = ?, follow_up_at = ?, note = ? WHERE order_id = ?');
            $updateTracking->execute([$state, $followUp, $note !== '' ? $note : null, $orderId]);
            $isUnreachable = $state === 'Injoignable';
            if ($state === 'Confirmée') {
                $updateOrder = $pdo->prepare("UPDATE orders SET status = 'Confirmée', acquisition_channel = ? WHERE order_ref = ?");
                $updateOrder->execute([$channel, $order['order_ref']]);
                log_event('commande', 'Confirmée par ' . $closer, (int) $order['product_id'], $orderId);
            } elseif (in_array($state, ['Annulée', 'Injoignable'], true)) {
                $updateOrder = $pdo->prepare("UPDATE orders SET status = 'Annulée' WHERE order_ref = ?");
                $updateOrder->execute([$order['order_ref']]);
                log_event(
                    'commande',
                    $isUnreachable ? 'Classée injoignable par ' . $closer : 'Annulée par ' . $closer,
                    (int) $order['product_id'],
                    $orderId
                );
            } else {
                $updateOrder = $pdo->prepare("UPDATE orders SET status = 'À confirmer', acquisition_channel = NULL WHERE order_ref = ?");
                $updateOrder->execute([$order['order_ref']]);
            }
            sync_closer_tracking_for_order_ref($pdo, (string) $order['order_ref']);
            log_closer_event($orderId, $state, $note !== '' ? $note : null);
            $pdo->commit();
            flash(
                'success',
                $isUnreachable ? 'Commande classée injoignable et retirée du suivi.' : 'Suivi de commande mis à jour.'
            );
        } elseif ($action === 'prepare_whatsapp') {
            if ($tracking['follow_up_status'] !== 'Confirmée' || $order['status'] !== 'Confirmée') {
                throw new RuntimeException('Confirmez la commande avant de préparer WhatsApp.');
            }
            $update = $pdo->prepare('UPDATE order_closer_tracking SET whatsapp_prepared_at = NOW() WHERE order_id = ?');
            $update->execute([$orderId]);
            log_closer_event($orderId, 'WhatsApp préparé', 'Message livreur prêt à être envoyé.');
            $pdo->commit();
            flash('success', 'Message WhatsApp préparé pour le livreur.');
        } elseif ($action === 'mark_whatsapp_sent') {
            if ($tracking['follow_up_status'] !== 'Confirmée' || $order['status'] !== 'Confirmée') {
                throw new RuntimeException('Cette commande n’est plus disponible pour l’envoi au livreur.');
            }
            if (!$tracking['whatsapp_prepared_at']) {
                throw new RuntimeException('Préparez d’abord le message du livreur.');
            }
            $markInDelivery = $pdo->prepare(
                "UPDATE orders SET status = 'En livraison' WHERE order_ref = ? AND status = 'Confirmée'"
            );
            $markInDelivery->execute([$order['order_ref']]);
            if ($markInDelivery->rowCount() < 1) {
                throw new RuntimeException('La commande n’a pas pu passer en livraison. Rechargez la page.');
            }
            $markSent = $pdo->prepare(
                "UPDATE order_closer_tracking t
                 JOIN orders o ON o.id = t.order_id
                 SET t.whatsapp_sent_at = NOW(), t.follow_up_at = NULL
                 WHERE o.order_ref = ? AND t.closer_identity = ?"
            );
            $markSent->execute([$order['order_ref'], $closer]);
            log_closer_event($orderId, 'Message livreur envoyé', 'Commande passée en livraison après le partage du message illustré.');
            log_event('commande', 'Commande ' . $order['order_ref'] . ' passée en livraison par ' . $closer, (int) $order['product_id'], $orderId);
            sync_closer_tracking_for_order_ref($pdo, (string) $order['order_ref']);
            $pdo->commit();
            flash('success', 'Message envoyé : la commande est maintenant en livraison.');
        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('L’Horloger: action closeuse échouée.');
        flash('error', closer_safe_error_message($exception));
    }
    redirect('/closer/');
}

$catalog = catalog();
$closerStock = [];
try {
    $stockStatement = $pdo->query(
        "SELECT p.id AS product_id, p.slug, p.name AS product_name,
                pv.id AS variant_id, pv.name AS variant_name,
                COALESCE(SUM(sm.quantity), 0) AS quantity
         FROM product_variants pv
         JOIN products p ON p.id = pv.product_id
         LEFT JOIN stock_movements sm ON sm.variant_id = pv.id
         WHERE pv.is_active = 1
         GROUP BY p.id, p.slug, p.name, pv.id, pv.name
         ORDER BY p.id ASC, pv.name ASC"
    );
    $closerStock = $stockStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('L’Horloger: aperçu stock closeuse indisponible.');
}
$closerStockTotal = array_reduce(
    $closerStock,
    static fn(int $total, array $variant): int => $total + max(0, (int) $variant['quantity']),
    0
);
$courierWhatsapp = trim((string) app_setting('courier_whatsapp', ''));
$courierReady = preg_match('/^\d{8,15}$/', preg_replace('/\D+/', '', $courierWhatsapp)) === 1;
$newSearch = trim((string) ($_GET['new_q'] ?? ''));
if (mb_strlen($newSearch) > 80) $newSearch = mb_substr($newSearch, 0, 80);
$newPage = filter_var($_GET['new_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$newPerPage = 3;
$newWhere = ["o.status = 'À confirmer'", 't.order_id IS NULL'];
$newParams = [];
if ($newSearch !== '') {
    $newWhere[] = 'CONCAT(o.customer_first_name, " ", o.customer_last_name, " ", o.phone, " ", o.district, " ", o.product_name, " ", o.variant, " ", o.order_ref) LIKE ?';
    $newParams[] = '%' . $newSearch . '%';
}
$newWhereSql = implode(' AND ', $newWhere);
$newCountStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM orders o
     LEFT JOIN order_closer_tracking t ON t.order_id = o.id
     WHERE ' . $newWhereSql
);
$newCountStatement->execute($newParams);
$newOrdersTotal = (int) $newCountStatement->fetchColumn();
$newPages = max(1, intdiv($newOrdersTotal + $newPerPage - 1, $newPerPage));
$newPage = min($newPage, $newPages);
$newOffset = ($newPage - 1) * $newPerPage;
$newOrdersStatement = $pdo->prepare(
    "SELECT o.*, p.slug, t.closer_identity AS assigned_to
     FROM orders o
     JOIN products p ON p.id = o.product_id
     LEFT JOIN order_closer_tracking t ON t.order_id = o.id
     WHERE $newWhereSql
     ORDER BY o.created_at ASC, o.id ASC
     LIMIT $newPerPage OFFSET $newOffset"
);
$newOrdersStatement->execute($newParams);
$newOrders = $newOrdersStatement->fetchAll();
$myOrdersStatement = $pdo->prepare(
    "SELECT o.*, p.slug, t.follow_up_status, t.follow_up_at, t.note, t.whatsapp_prepared_at, t.whatsapp_sent_at, t.updated_at AS tracking_updated_at
     FROM order_closer_tracking t
     JOIN orders o ON o.id = t.order_id
     JOIN products p ON p.id = o.product_id
     WHERE t.closer_identity = ?
       AND o.status NOT IN ('Annulée', 'En livraison', 'Livrée')
       AND (
           t.follow_up_status IN ('À appeler', 'À rappeler')
           OR t.follow_up_status = 'Confirmée'
       )
     ORDER BY FIELD(t.follow_up_status, 'À appeler', 'À rappeler', 'Confirmée', 'Annulée'),
              t.follow_up_at IS NULL, t.follow_up_at, t.updated_at DESC"
);
$myOrdersStatement->execute([$closer]);
$myOrders = $myOrdersStatement->fetchAll();
$orderEditCatalog = accounting_order_edit_catalog($pdo);
$orderEditability = [];
foreach ($myOrders as $candidate) {
    $candidateRef = (string) $candidate['order_ref'];
    if (!array_key_exists($candidateRef, $orderEditability)) {
        $orderEditability[$candidateRef] = accounting_order_editability($pdo, $candidateRef);
    }
}
$historyStatement = $pdo->prepare(
    "SELECT e.*, o.order_ref, o.customer_first_name, o.customer_last_name
     FROM closer_events e JOIN orders o ON o.id = e.order_id
     WHERE e.closer_identity = ? ORDER BY e.created_at DESC LIMIT 12"
);
$historyStatement->execute([$closer]);
$history = $historyStatement->fetchAll();
$today = date('Y-m-d');
$confirmedCountStatement = $pdo->prepare("SELECT COUNT(*) FROM order_closer_tracking WHERE closer_identity = ? AND follow_up_status = 'Confirmée' AND DATE(updated_at) = ?");
$confirmedCountStatement->execute([$closer, $today]);
$confirmedToday = (int) $confirmedCountStatement->fetchColumn();
$followUpCountStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM order_closer_tracking t
     JOIN orders o ON o.id = t.order_id
     WHERE t.closer_identity = ?
       AND t.follow_up_status = 'À rappeler'
       AND o.status NOT IN ('Annulée', 'En livraison', 'Livrée')"
);
$followUpCountStatement->execute([$closer]);
$followUpCount = (int) $followUpCountStatement->fetchColumn();
$closerPageTitle = 'Mon suivi';
require APP_ROOT . '/templates/closer-header.php';
?>
<header class="closer-hero">
  <div><p class="closer-kicker">Espace closeuse</p><h1>Mes ventes à confirmer.</h1><p>Appelez, notez le résultat puis partagez au livreur la fiche illustrée de chaque commande validée.</p></div>
</header>
<section class="closer-metrics">
  <article class="closer-metric"><span>Nouvelles à traiter</span><strong><?= $newOrdersTotal ?></strong></article>
  <article class="closer-metric"><span>Dans mon suivi</span><strong><?= count($myOrders) ?></strong></article>
  <article class="closer-metric"><span>À rappeler</span><strong><?= $followUpCount ?></strong></article>
  <article class="closer-metric"><span>Confirmées aujourd’hui</span><strong><?= $confirmedToday ?></strong></article>
</section>
<section class="closer-stock" aria-labelledby="closer-stock-title">
  <div class="closer-stock__head">
    <div><p class="closer-kicker">Repère visuel</p><h2 id="closer-stock-title">Stock disponible</h2><p>Chaque photo correspond au coloris exact. Faites glisser pour voir toutes les montres.</p></div>
    <span class="closer-count"><?= $closerStockTotal ?> montre<?= $closerStockTotal === 1 ? '' : 's' ?></span>
  </div>
  <?php if ($closerStock): ?>
    <div class="closer-stock__rail" aria-label="Quantités disponibles par coloris" tabindex="0">
      <?php foreach ($closerStock as $variant): $available = max(0, (int) $variant['quantity']); ?>
        <article class="closer-stock-card <?= $available === 0 ? 'is-empty' : '' ?>">
          <img src="<?= e(url('/' . catalog_order_preview_image($catalog, (string) $variant['slug'], (string) $variant['variant_name']))) ?>" alt="<?= e($variant['product_name'] . ' · ' . $variant['variant_name']) ?>">
          <div class="closer-stock-card__copy"><span><?= e($variant['product_name']) ?></span><strong><?= e($variant['variant_name']) ?></strong></div>
          <div class="closer-stock-card__quantity"><b><?= $available ?></b><span>disponible<?= $available === 1 ? '' : 's' ?></span></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="closer-empty">Le stock est momentanément indisponible.</p>
  <?php endif; ?>
</section>
<div class="closer-layout">
  <section class="closer-panel closer-panel--followup">
    <div class="closer-panel__head"><div><h2>Mon suivi</h2><p>Confirmez la commande, préparez son message illustré puis partagez-le au livreur. Après l’envoi, elle passe automatiquement en livraison et quitte cette liste.</p></div></div>
    <?php if (!$courierReady): ?><p class="closer-warning">Le numéro WhatsApp du livreur n’est pas encore renseigné. La gestion peut l’ajouter dans « Suivi closeuse ».</p><?php endif; ?>
    <div class="closer-orders">
      <?php foreach ($myOrders as $order): $confirmed = $order['follow_up_status'] === 'Confirmée'; $isFollowUp = $order['follow_up_status'] === 'À rappeler'; $canEdit = (bool) ($orderEditability[(string) $order['order_ref']]['editable'] ?? false); ?>
        <article class="closer-order">
          <img class="closer-order__image" src="<?= e(url('/' . closer_image($order, $catalog))) ?>" alt="<?= e($order['product_name'] . ' · ' . $order['variant']) ?>">
          <div class="closer-order__content">
            <div class="closer-order__top"><div><h3><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></h3><p><?= e($order['order_ref']) ?> · <?= e($order['product_name']) ?></p><time class="closer-order-time" datetime="<?= e((string) $order['created_at']) ?>">Commandée le <?= e(closer_order_time((string) $order['created_at'])) ?></time></div><span class="closer-pill <?= $confirmed ? 'is-confirmed' : ($isFollowUp ? 'is-followup' : '') ?>"><?= e($order['follow_up_status']) ?></span></div>
            <div class="closer-order__facts"><span><b><?= e($order['variant']) ?></b> · Qté <?= (int) $order['quantity'] ?></span><span><a href="tel:<?= e($order['phone']) ?>"><b><?= e($order['phone']) ?></b></a></span><span><?= e($order['district']) ?></span><span><b><?= money((int) $order['quantity'] * (int) $order['unit_price_fcfa']) ?></b></span></div>
            <?php if ($canEdit): $currentVariants = $orderEditCatalog[(int) $order['product_id']]['variants'] ?? []; ?>
              <button class="closer-edit-toggle" type="button" aria-expanded="false" aria-controls="closer-edit-<?= (int) $order['id'] ?>" data-closer-edit-toggle>Modifier la commande</button>
              <section class="closer-edit-panel" id="closer-edit-<?= (int) $order['id'] ?>" hidden>
                <div class="closer-edit-panel__head"><strong>Modifier la commande</strong><span>Les changements seront aussi visibles côté gestion.</span></div>
                <form class="closer-edit-form" method="post" data-closer-order-edit-form>
                  <?= csrf_field() ?><input type="hidden" name="action" value="edit_order"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <fieldset><legend>Client et livraison</legend><div class="closer-edit-fields">
                    <label>Prénom<input name="customer_first_name" value="<?= e($order['customer_first_name']) ?>" maxlength="100" required></label>
                    <label>Nom<input name="customer_last_name" value="<?= e($order['customer_last_name']) ?>" maxlength="100" required></label>
                    <label>Téléphone<input name="phone" value="<?= e($order['phone']) ?>" maxlength="32" inputmode="tel" required></label>
                    <label>Quartier<input name="district" value="<?= e($order['district']) ?>" maxlength="150" required></label>
                  </div></fieldset>
                  <fieldset><legend>Montre commandée</legend><div class="closer-edit-fields">
                    <label>Produit<select name="product_id" data-closer-edit-product required><?php foreach ($orderEditCatalog as $product): ?><option value="<?= (int) $product['id'] ?>" data-price="<?= (int) $product['price_fcfa'] ?>" <?= (int) $order['product_id'] === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select></label>
                    <label>Couleur<select name="variant_id" data-closer-edit-variant required><?php foreach ($currentVariants as $variant): ?><option value="<?= (int) $variant['id'] ?>" <?= ((int) ($order['variant_id'] ?? 0) === (int) $variant['id'] || ((int) ($order['variant_id'] ?? 0) === 0 && $order['variant'] === $variant['name'])) ? 'selected' : '' ?>><?= e($variant['name']) ?></option><?php endforeach; ?></select></label>
                    <label>Quantité<input type="number" name="quantity" value="<?= (int) $order['quantity'] ?>" min="1" max="100" inputmode="numeric" required></label>
                    <label>Prix unitaire FCFA<input type="number" name="unit_price_fcfa" value="<?= (int) $order['unit_price_fcfa'] ?>" min="1" max="100000000" inputmode="numeric" data-closer-edit-price required></label>
                  </div></fieldset>
                  <div class="closer-edit-total"><span>Nouveau total</span><strong data-closer-edit-total><?= money((int) $order['quantity'] * (int) $order['unit_price_fcfa']) ?></strong></div>
                  <div class="closer-edit-actions"><button class="closer-button" type="submit">Enregistrer les modifications</button><button class="closer-button secondary" type="button" data-closer-edit-cancel>Annuler</button></div>
                </form>
              </section>
            <?php endif; ?>
            <form class="closer-form" method="post">
              <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="update_follow_up">
              <label>Résultat<select name="follow_up_status"><?php foreach ($trackingStates as $state): ?><option value="<?= e($state) ?>" <?= $order['follow_up_status'] === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?></select></label>
              <label>Canal<select name="channel"><option value="">À renseigner</option><?php foreach ($channels as $channel): ?><option value="<?= e($channel) ?>" <?= ($order['acquisition_channel'] ?? '') === $channel ? 'selected' : '' ?>><?= e($channel) ?></option><?php endforeach; ?></select></label>
              <label>Rappel<input type="datetime-local" name="follow_up_at" value="<?= $order['follow_up_at'] ? e(date('Y-m-d\TH:i', strtotime($order['follow_up_at']))) : '' ?>"></label>
              <label class="closer-form__wide">Note<textarea name="note" placeholder="Résultat de l’appel, demande du client…"><?= e((string) ($order['note'] ?? '')) ?></textarea></label>
              <button class="closer-button closer-form__submit" type="submit">Enregistrer</button>
            </form>
            <?php if ($confirmed): ?>
              <?php if (!$order['whatsapp_prepared_at']): ?>
                <form class="closer-actions" method="post">
                  <?= csrf_field() ?><input type="hidden" name="action" value="prepare_whatsapp"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <button class="closer-button secondary" type="submit">Préparer le message livreur</button>
                </form>
              <?php else: $message = closer_whatsapp_message($order); $imageUrl = url('/' . closer_image($order, $catalog)); $amount = money((int) $order['quantity'] * (int) $order['unit_price_fcfa']); ?>
                <section class="closer-message-preview" aria-label="Aperçu du message livreur">
                  <img src="<?= e($imageUrl) ?>" alt="<?= e($order['product_name'] . ' · ' . $order['variant']) ?>">
                  <div><span>Message prêt à partager</span><strong><?= e($order['product_name']) ?></strong><small>Couleur : <?= e($order['variant']) ?> · Qté <?= (int) $order['quantity'] ?></small><b class="closer-message-price"><?= e($amount) ?></b></div>
                </section>
                <form class="closer-message-actions" method="post" data-whatsapp-send-form>
                  <?= csrf_field() ?><input type="hidden" name="action" value="mark_whatsapp_sent"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <button class="closer-button whatsapp" type="button" data-whatsapp-share
                    data-message="<?= e($message) ?>" data-image-url="<?= e($imageUrl) ?>"
                    data-product="<?= e($order['product_name']) ?>" data-variant="<?= e($order['variant']) ?>"
                    data-price="<?= e($amount) ?>" data-reference="<?= e($order['order_ref']) ?>"
                    data-customer="<?= e(trim($order['customer_first_name'] . ' ' . $order['customer_last_name'])) ?>"
                    data-phone="<?= e($order['phone']) ?>" data-district="<?= e($order['district']) ?>" data-quantity="<?= (int) $order['quantity'] ?>">
                    Partager la photo + le message
                  </button>
                  <?php if ($courierReady): ?>
                    <a class="closer-button-link secondary" target="_blank" rel="noopener" data-whatsapp-fallback-link href="<?= e(closer_whatsapp_link($order, $courierWhatsapp)) ?>">Ouvrir WhatsApp en texte</a>
                    <button class="closer-button closer-message-confirm" type="submit" data-whatsapp-fallback-confirm hidden>Message envoyé — passer en livraison</button>
                  <?php endif; ?>
                  <p class="closer-share-status" data-share-status aria-live="polite"></p>
                </form>
                <p class="closer-whatsapp-note">Préparé le <?= e(date('d/m/Y à H:i', strtotime($order['whatsapp_prepared_at']))) ?>. Sur iPhone, le bouton vert partage une image avec la montre, sa couleur et le prix bien visible.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$myOrders): ?><p class="closer-empty">Votre suivi est vide. Ajoutez une nouvelle commande ci-dessous pour commencer.</p><?php endif; ?>
    </div>
  </section>
  <div class="closer-side">
  <aside class="closer-panel closer-panel--queue">
    <div class="closer-panel__head"><div><h2>Nouvelles commandes</h2><p>Priorité aux commandes les plus anciennes. Prenez-les une par une pour garder votre suivi net.</p></div><span class="closer-count"><?= $newOrdersTotal ?> en attente</span></div>
    <form class="closer-queue-tools" method="get" role="search"><label for="new-order-search">Rechercher une commande</label><div><input id="new-order-search" name="new_q" value="<?= e($newSearch) ?>" maxlength="80" placeholder="Client, téléphone, quartier…"><button class="closer-button secondary" type="submit">Filtrer</button></div></form>
    <p class="closer-queue-summary"><?= $newOrdersTotal === 0 ? 'Aucune commande ne correspond.' : 'Commandes ' . (($newOffset + 1)) . ' à ' . min($newOffset + $newPerPage, $newOrdersTotal) . ' sur ' . $newOrdersTotal ?></p>
    <div class="closer-orders closer-queue">
      <?php foreach ($newOrders as $order): ?>
        <article class="closer-order closer-order--queue"><img class="closer-order__image" src="<?= e(url('/' . closer_image($order, $catalog))) ?>" alt="<?= e($order['product_name'] . ' · ' . $order['variant']) ?>"><div class="closer-order__content"><div class="closer-order__top"><div><h3><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></h3><p><?= e($order['product_name']) ?> · <?= e($order['variant']) ?></p></div><span class="closer-age"><time datetime="<?= e((string) $order['created_at']) ?>"><?= e(closer_order_time((string) $order['created_at'])) ?></time><small><?= e(closer_relative_time((string) $order['created_at'])) ?></small></span></div><div class="closer-order__facts"><span><a href="tel:<?= e($order['phone']) ?>"><b><?= e($order['phone']) ?></b></a></span><span><?= e($order['district']) ?></span><span>Qté <?= (int) $order['quantity'] ?></span></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><button class="closer-button" type="submit">Prendre en charge</button></form></div></article>
      <?php endforeach; ?>
      <?php if (!$newOrders): ?><p class="closer-empty">Aucune nouvelle commande à appeler pour le moment.</p><?php endif; ?>
    </div>
    <?php if ($newPages > 1): ?><nav class="closer-pagination" aria-label="Pages des nouvelles commandes"><?php if ($newPage > 1): ?><a href="?<?= e(http_build_query(['new_q' => $newSearch, 'new_page' => $newPage - 1])) ?>">← Précédentes</a><?php endif; ?><span>Page <?= $newPage ?> / <?= $newPages ?></span><?php if ($newPage < $newPages): ?><a href="?<?= e(http_build_query(['new_q' => $newSearch, 'new_page' => $newPage + 1])) ?>">Suivantes →</a><?php endif; ?></nav><?php endif; ?>
  </aside>
  <aside class="closer-panel closer-panel--history"><div class="closer-panel__head"><div><h2>Mon historique</h2><p>Vos dernières actions.</p></div></div><ul class="closer-history"><?php foreach ($history as $event): ?><li><strong><?= e($event['event_type']) ?> · <?= e($event['order_ref']) ?></strong><span><?= e($event['customer_first_name'] . ' ' . $event['customer_last_name']) ?> · <?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?><?= $event['note'] ? ' · ' . e($event['note']) : '' ?></span></li><?php endforeach; ?><?php if (!$history): ?><li><span>Aucune action enregistrée.</span></li><?php endif; ?></ul></aside>
  </div>
</div>
<script id="closer-order-edit-catalog" type="application/json"><?= json_encode(array_values($orderEditCatalog), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require APP_ROOT . '/templates/closer-footer.php'; ?>
