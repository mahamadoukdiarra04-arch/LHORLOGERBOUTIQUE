<?php
require __DIR__ . '/../../app/bootstrap.php';
require_closer();
require APP_ROOT . '/catalog.php';
ensure_closer_schema();
$pdo = db();
$closer = admin_identity();
$trackingStates = ['À appeler', 'À rappeler', 'Confirmée', 'Injoignable', 'Annulée'];
$channels = ['Meta', 'Réachat'];

function closer_datetime(?string $value): ?string {
    if (!$value) return null;
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}
function closer_image(array $order, array $catalog): string {
    $slug = (string) ($order['slug'] ?? '');
    return $catalog[$slug]['image'] ?? 'products/nocturne-chrono.jpg';
}
function closer_whatsapp_link(array $order, string $number): string {
    $phone = preg_replace('/\D+/', '', $number);
    $message = "L’Horloger - livraison à préparer\n"
        . "Référence : {$order['order_ref']}\n"
        . "Client : {$order['customer_first_name']} {$order['customer_last_name']}\n"
        . "Téléphone : {$order['phone']}\n"
        . "Quartier : {$order['district']}\n"
        . "Montre : {$order['product_name']} - {$order['variant']} x{$order['quantity']}\n"
        . "Total à la réception : " . money((int) $order['quantity'] * (int) $order['unit_price_fcfa']);
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode($message);
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
        } elseif ($action === 'update_follow_up') {
            $state = (string) ($_POST['follow_up_status'] ?? '');
            $note = trim((string) ($_POST['note'] ?? ''));
            $followUp = closer_datetime((string) ($_POST['follow_up_at'] ?? ''));
            $channel = (string) ($_POST['channel'] ?? '');
            if (!in_array($state, $trackingStates, true)) throw new RuntimeException('Statut de suivi invalide.');
            if ((string) ($_POST['follow_up_at'] ?? '') !== '' && !$followUp) throw new RuntimeException('Date de rappel invalide.');
            if ($state === 'Confirmée' && !in_array($channel, $channels, true)) throw new RuntimeException('Choisissez Meta ou Réachat pour confirmer.');

            $updateTracking = $pdo->prepare('UPDATE order_closer_tracking SET follow_up_status = ?, follow_up_at = ?, note = ? WHERE order_id = ?');
            $updateTracking->execute([$state, $followUp, $note !== '' ? $note : null, $orderId]);
            if ($state === 'Confirmée') {
                $updateOrder = $pdo->prepare("UPDATE orders SET status = 'Confirmée', acquisition_channel = ? WHERE order_ref = ?");
                $updateOrder->execute([$channel, $order['order_ref']]);
                log_event('commande', 'Confirmée par ' . $closer, (int) $order['product_id'], $orderId);
            } elseif ($state === 'Annulée') {
                $updateOrder = $pdo->prepare("UPDATE orders SET status = 'Annulée' WHERE order_ref = ?");
                $updateOrder->execute([$order['order_ref']]);
                log_event('commande', 'Annulée par ' . $closer, (int) $order['product_id'], $orderId);
            }
            log_closer_event($orderId, $state, $note !== '' ? $note : null);
            $pdo->commit();
            flash('success', 'Suivi de commande mis à jour.');
        } elseif ($action === 'prepare_whatsapp') {
            if ($tracking['follow_up_status'] !== 'Confirmée' || $order['status'] !== 'Confirmée') {
                throw new RuntimeException('Confirmez la commande avant de préparer WhatsApp.');
            }
            $update = $pdo->prepare('UPDATE order_closer_tracking SET whatsapp_prepared_at = NOW() WHERE order_id = ?');
            $update->execute([$orderId]);
            log_closer_event($orderId, 'WhatsApp préparé', 'Message livreur prêt à être envoyé.');
            $pdo->commit();
            flash('success', 'Message WhatsApp préparé pour le livreur.');
        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $exception->getMessage());
    }
    redirect('/closer/');
}

$catalog = catalog();
$courierWhatsapp = trim((string) app_setting('courier_whatsapp', ''));
$courierReady = preg_match('/^\d{8,15}$/', preg_replace('/\D+/', '', $courierWhatsapp)) === 1;
$newOrdersStatement = $pdo->prepare(
    "SELECT o.*, p.slug, t.closer_identity AS assigned_to
     FROM orders o
     JOIN products p ON p.id = o.product_id
     LEFT JOIN order_closer_tracking t ON t.order_id = o.id
     WHERE o.status = 'À confirmer'
       AND t.order_id IS NULL
     ORDER BY o.created_at DESC"
);
$newOrdersStatement->execute();
$newOrders = $newOrdersStatement->fetchAll();
$myOrdersStatement = $pdo->prepare(
    "SELECT o.*, p.slug, t.follow_up_status, t.follow_up_at, t.note, t.whatsapp_prepared_at, t.updated_at AS tracking_updated_at
     FROM order_closer_tracking t
     JOIN orders o ON o.id = t.order_id
     JOIN products p ON p.id = o.product_id
     WHERE t.closer_identity = ?
     ORDER BY FIELD(t.follow_up_status, 'À appeler', 'À rappeler', 'Injoignable', 'Confirmée', 'Annulée'),
              t.follow_up_at IS NULL, t.follow_up_at, t.updated_at DESC"
);
$myOrdersStatement->execute([$closer]);
$myOrders = $myOrdersStatement->fetchAll();
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
$followUpCountStatement = $pdo->prepare("SELECT COUNT(*) FROM order_closer_tracking WHERE closer_identity = ? AND follow_up_status = 'À rappeler'");
$followUpCountStatement->execute([$closer]);
$followUpCount = (int) $followUpCountStatement->fetchColumn();
$closerPageTitle = 'Mon suivi';
require APP_ROOT . '/templates/closer-header.php';
?>
<header class="closer-hero">
  <div><p class="closer-kicker">Espace closeuse</p><h1>Mes ventes à confirmer.</h1><p>Appelez, notez le résultat puis préparez les commandes validées pour le livreur.</p></div>
</header>
<section class="closer-metrics">
  <article class="closer-metric"><span>Nouvelles à traiter</span><strong><?= count($newOrders) ?></strong></article>
  <article class="closer-metric"><span>Dans mon suivi</span><strong><?= count($myOrders) ?></strong></article>
  <article class="closer-metric"><span>À rappeler</span><strong><?= $followUpCount ?></strong></article>
  <article class="closer-metric"><span>Confirmées aujourd’hui</span><strong><?= $confirmedToday ?></strong></article>
</section>
<div class="closer-layout">
  <section class="closer-panel">
    <div class="closer-panel__head"><div><h2>Mon suivi</h2><p>Chaque validation met immédiatement à jour l’état et le canal dans l’administration.</p></div></div>
    <form id="delivery-selection" class="closer-delivery" method="post" action="<?= e(url('/closer/delivery-sheet.php')) ?>">
      <?= csrf_field() ?>
      <p><strong>Commandes du jour</strong><br>Sélectionnez les commandes confirmées, puis téléchargez le bordereau PDF avec photos.</p>
      <input class="closer-delivery-date" type="date" name="delivery_date" value="<?= e($today) ?>" aria-label="Date du bordereau">
      <button class="closer-button" type="submit">Télécharger le PDF</button>
    </form>
    <?php if (!$courierReady): ?><p class="closer-warning">Le numéro WhatsApp du livreur n’est pas encore renseigné. La gestion peut l’ajouter dans « Suivi closeuse ».</p><?php endif; ?>
    <div class="closer-orders">
      <?php foreach ($myOrders as $order): $confirmed = $order['follow_up_status'] === 'Confirmée'; $isFollowUp = $order['follow_up_status'] === 'À rappeler'; ?>
        <article class="closer-order">
          <img class="closer-order__image" src="<?= e(url('/' . closer_image($order, $catalog))) ?>" alt="<?= e($order['product_name']) ?>">
          <div class="closer-order__content">
            <div class="closer-order__top"><div><h3><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></h3><p><?= e($order['order_ref']) ?> · <?= e($order['product_name']) ?></p></div><span class="closer-pill <?= $confirmed ? 'is-confirmed' : ($isFollowUp ? 'is-followup' : '') ?>"><?= e($order['follow_up_status']) ?></span></div>
            <div class="closer-order__facts"><span><b><?= e($order['variant']) ?></b> · Qté <?= (int) $order['quantity'] ?></span><span><a href="tel:<?= e($order['phone']) ?>"><b><?= e($order['phone']) ?></b></a></span><span><?= e($order['district']) ?></span><span><b><?= money((int) $order['quantity'] * (int) $order['unit_price_fcfa']) ?></b></span></div>
            <?php if ($confirmed): ?><label class="closer-choice"><input form="delivery-selection" type="checkbox" name="order_ids[]" value="<?= (int) $order['id'] ?>"> Ajouter au PDF du livreur</label><?php endif; ?>
            <form class="closer-form" method="post">
              <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="update_follow_up">
              <label>Résultat<select name="follow_up_status"><?php foreach ($trackingStates as $state): ?><option value="<?= e($state) ?>" <?= $order['follow_up_status'] === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?></select></label>
              <label>Canal<select name="channel"><option value="">À renseigner</option><?php foreach ($channels as $channel): ?><option value="<?= e($channel) ?>" <?= ($order['acquisition_channel'] ?? '') === $channel ? 'selected' : '' ?>><?= e($channel) ?></option><?php endforeach; ?></select></label>
              <label>Rappel<input type="datetime-local" name="follow_up_at" value="<?= $order['follow_up_at'] ? e(date('Y-m-d\TH:i', strtotime($order['follow_up_at']))) : '' ?>"></label>
              <label class="closer-form__wide">Note<textarea name="note" placeholder="Résultat de l’appel, demande du client…"><?= e((string) ($order['note'] ?? '')) ?></textarea></label>
              <button class="closer-button closer-form__submit" type="submit">Enregistrer</button>
            </form>
            <?php if ($confirmed): ?>
              <div class="closer-actions" style="margin-top:10px">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="prepare_whatsapp"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><button class="closer-button secondary" type="submit">Préparer WhatsApp</button></form>
                <?php if ($courierReady && $order['whatsapp_prepared_at']): ?><a class="closer-button-link whatsapp" target="_blank" rel="noopener" href="<?= e(closer_whatsapp_link($order, $courierWhatsapp)) ?>">Ouvrir WhatsApp</a><?php endif; ?>
              </div>
              <?php if ($order['whatsapp_prepared_at']): ?><p class="closer-whatsapp-note">Message préparé le <?= e(date('d/m/Y à H:i', strtotime($order['whatsapp_prepared_at']))) ?>. WhatsApp s’ouvrira avec les informations déjà rédigées.</p><?php endif; ?>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$myOrders): ?><p class="closer-empty">Votre suivi est vide. Ajoutez une nouvelle commande ci-dessous pour commencer.</p><?php endif; ?>
    </div>
  </section>
  <aside class="closer-panel">
    <div class="closer-panel__head"><div><h2>Nouvelles commandes</h2><p>Ajoutez celles que vous prenez en charge.</p></div></div>
    <div class="closer-orders">
      <?php foreach ($newOrders as $order): ?>
        <article class="closer-order"><img class="closer-order__image" src="<?= e(url('/' . closer_image($order, $catalog))) ?>" alt="<?= e($order['product_name']) ?>"><div class="closer-order__content"><div class="closer-order__top"><div><h3><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></h3><p><?= e($order['order_ref']) ?> · <?= e($order['product_name']) ?></p></div></div><div class="closer-order__facts"><span><?= e($order['variant']) ?> · Qté <?= (int) $order['quantity'] ?></span><span><a href="tel:<?= e($order['phone']) ?>"><b><?= e($order['phone']) ?></b></a></span><span><?= e($order['district']) ?></span></div><?php if (($order['assigned_to'] ?? '') === $closer): ?><span class="closer-pill is-followup">Déjà dans votre suivi</span><?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><button class="closer-button" type="submit">Ajouter à mon suivi</button></form><?php endif; ?></div></article>
      <?php endforeach; ?>
      <?php if (!$newOrders): ?><p class="closer-empty">Aucune nouvelle commande à appeler pour le moment.</p><?php endif; ?>
    </div>
    <div style="margin-top:22px"><div class="closer-panel__head"><div><h2>Mon historique</h2><p>Vos dernières actions.</p></div></div><ul class="closer-history"><?php foreach ($history as $event): ?><li><strong><?= e($event['event_type']) ?> · <?= e($event['order_ref']) ?></strong><span><?= e($event['customer_first_name'] . ' ' . $event['customer_last_name']) ?> · <?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?><?= $event['note'] ? ' · ' . e($event['note']) : '' ?></span></li><?php endforeach; ?><?php if (!$history): ?><li><span>Aucune action enregistrée.</span></li><?php endif; ?></ul></div>
  </aside>
</div>
<?php require APP_ROOT . '/templates/closer-footer.php'; ?>
