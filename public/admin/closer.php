<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
ensure_closer_schema();
$pdo = db();
sync_all_closer_tracking($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $courierWhatsapp = preg_replace('/\D+/', '', (string) ($_POST['courier_whatsapp'] ?? ''));
        if ($courierWhatsapp !== '' && !preg_match('/^\d{8,15}$/', $courierWhatsapp)) {
            throw new RuntimeException('Indiquez un numéro WhatsApp de livreur valide, avec l’indicatif pays.');
        }
        save_app_setting('courier_whatsapp', $courierWhatsapp);
        flash('success', $courierWhatsapp === '' ? 'Numéro WhatsApp du livreur retiré.' : 'Numéro WhatsApp du livreur enregistré.');
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
    }
    redirect('/admin/closer.php');
}

$today = date('Y-m-d');
$activeCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM order_closer_tracking t
     JOIN orders o ON o.id = t.order_id
     WHERE t.follow_up_status IN ('À appeler', 'À rappeler', 'Injoignable')
       AND o.status NOT IN ('Annulée', 'Livrée')"
)->fetchColumn();
$confirmedStatement = $pdo->prepare("SELECT COUNT(*) FROM order_closer_tracking WHERE follow_up_status = 'Confirmée' AND DATE(updated_at) = ?");
$confirmedStatement->execute([$today]);
$confirmedToday = (int) $confirmedStatement->fetchColumn();
$whatsappStatement = $pdo->prepare('SELECT COUNT(*) FROM order_closer_tracking WHERE whatsapp_prepared_at IS NOT NULL AND DATE(whatsapp_prepared_at) = ?');
$whatsappStatement->execute([$today]);
$whatsappToday = (int) $whatsappStatement->fetchColumn();
$tracking = $pdo->query(
    "SELECT t.*, o.order_ref, o.customer_first_name, o.customer_last_name, o.phone, o.district,
            o.product_name, o.variant, o.quantity, o.unit_price_fcfa, o.status, o.acquisition_channel
     FROM order_closer_tracking t
     JOIN orders o ON o.id = t.order_id
     ORDER BY FIELD(t.follow_up_status, 'À appeler', 'À rappeler', 'Injoignable', 'Confirmée', 'Annulée', 'Livrée'),
              t.follow_up_at IS NULL, t.follow_up_at, t.updated_at DESC"
)->fetchAll();
$courierWhatsapp = (string) app_setting('courier_whatsapp', '');
$adminPageTitle = 'Suivi closeuse';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Ventes par téléphone</p>
    <h1>Suivi de la closeuse.</h1>
    <p>Les validations, rappels et préparations livreur effectués par la closeuse apparaissent ici immédiatement.</p>
  </div>
</header>

<section class="metric-grid closer-admin-metrics">
  <article class="metric"><span>À suivre</span><strong><?= $activeCount ?></strong><small>appels, rappels ou numéros injoignables</small></article>
  <article class="metric"><span>Confirmées aujourd’hui</span><strong><?= $confirmedToday ?></strong><small>commandes passées au statut confirmée</small></article>
  <article class="metric"><span>WhatsApp préparés</span><strong><?= $whatsappToday ?></strong><small>messages livreur prêts aujourd’hui</small></article>
</section>

<section class="admin-grid" style="margin-top:15px">
  <article class="admin-panel">
    <p class="admin-kicker">Configuration livreur</p>
    <h2>Numéro WhatsApp du livreur.</h2>
    <p class="admin-copy">La closeuse ouvre WhatsApp avec un message déjà rempli. L’envoi reste volontairement à sa main.</p>
    <form class="data-form" method="post">
      <?= csrf_field() ?>
      <label>WhatsApp du livreur (avec indicatif pays)<input name="courier_whatsapp" inputmode="tel" maxlength="20" value="<?= e($courierWhatsapp) ?>" placeholder="223XXXXXXXX"></label>
      <button class="admin-button" type="submit">Enregistrer</button>
    </form>
  </article>
  <article class="admin-panel">
    <p class="admin-kicker">Fonctionnement</p>
    <h2>Une vue séparée, une donnée commune.</h2>
    <p class="admin-copy">La closeuse choisit ses nouvelles commandes, consigne les appels puis sélectionne les commandes confirmées dans un bordereau PDF illustré. Les statuts et canaux d’acquisition restent visibles dans les commandes et les analyses de gestion.</p>
  </article>
</section>

<section class="admin-panel" style="margin-top:15px">
  <p class="admin-kicker">Historique opérationnel</p>
  <h2>Commandes prises en charge.</h2>
  <div class="admin-table-wrap mobile-card-table-wrap">
    <table class="admin-table closer-admin-table mobile-card-table">
      <thead><tr><th>Closeuse</th><th>Commande</th><th>Client</th><th>Produit</th><th>Suivi</th><th>Rappel</th><th>Canal</th><th>Livraison</th></tr></thead>
      <tbody>
      <?php foreach ($tracking as $item): ?>
        <tr class="mobile-card-row">
          <td data-label="Closeuse"><strong><?= e($item['closer_identity']) ?></strong></td>
          <td data-label="Commande"><strong><?= e($item['order_ref']) ?></strong><br><small><?= e(date('d/m/Y H:i', strtotime($item['created_at']))) ?></small></td>
          <td data-label="Client"><?= e($item['customer_first_name'] . ' ' . $item['customer_last_name']) ?><br><a href="tel:<?= e($item['phone']) ?>"><small><?= e($item['phone']) ?></small></a><br><small><?= e($item['district']) ?></small></td>
          <td data-label="Produit"><?= e($item['product_name']) ?><br><small><?= e($item['variant']) ?> · Qté <?= (int) $item['quantity'] ?></small></td>
          <td data-label="Suivi"><span class="status status-<?= e(strtolower(str_replace([' ', 'é', 'à'], ['-', 'e', 'a'], $item['follow_up_status']))) ?>"><?= e($item['follow_up_status']) ?></span><?php if ($item['note']): ?><br><small><?= e($item['note']) ?></small><?php endif; ?></td>
          <td data-label="Rappel"><?= $item['follow_up_at'] ? e(date('d/m/Y H:i', strtotime($item['follow_up_at']))) : '—' ?></td>
          <td data-label="Canal"><?= e((string) ($item['acquisition_channel'] ?? '—')) ?></td>
          <td data-label="Livraison"><?= $item['whatsapp_prepared_at'] ? 'WhatsApp prêt · ' . e(date('d/m H:i', strtotime($item['whatsapp_prepared_at']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tracking): ?><tr class="mobile-card-empty"><td colspan="8" class="admin-table-empty">Aucune commande n’est encore prise en charge par la closeuse.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
