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
     WHERE t.follow_up_status IN ('À appeler', 'À rappeler')
       AND o.status NOT IN ('Annulée', 'En livraison', 'Livrée')"
)->fetchColumn();
$confirmedStatement = $pdo->prepare("SELECT COUNT(*) FROM order_closer_tracking WHERE follow_up_status = 'Confirmée' AND DATE(updated_at) = ?");
$confirmedStatement->execute([$today]);
$confirmedToday = (int) $confirmedStatement->fetchColumn();
$whatsappStatement = $pdo->prepare('SELECT COUNT(*) FROM order_closer_tracking WHERE whatsapp_sent_at IS NOT NULL AND DATE(whatsapp_sent_at) = ?');
$whatsappStatement->execute([$today]);
$whatsappToday = (int) $whatsappStatement->fetchColumn();

$closerIdentities = $pdo->query(
    'SELECT DISTINCT closer_identity FROM order_closer_tracking ORDER BY closer_identity'
)->fetchAll(PDO::FETCH_COLUMN);
$historyStatuses = [
    'all' => 'Tous les statuts',
    'active' => 'À traiter',
    'confirmed' => 'Confirmées',
    'delivery' => 'En livraison',
    'delivered' => 'Livrées',
    'unreachable' => 'Injoignables',
    'cancelled' => 'Annulées',
];
$historyPeriods = [
    '7' => '7 derniers jours',
    '30' => '30 derniers jours',
    '90' => '90 derniers jours',
    'all' => 'Toute la période',
];
$historySearch = trim((string) ($_GET['q'] ?? ''));
$historySearch = function_exists('mb_substr') ? mb_substr($historySearch, 0, 80) : substr($historySearch, 0, 80);
$historyStatus = (string) ($_GET['status'] ?? 'all');
if (!array_key_exists($historyStatus, $historyStatuses)) $historyStatus = 'all';
$historyPeriod = (string) ($_GET['period'] ?? '30');
if (!array_key_exists($historyPeriod, $historyPeriods)) $historyPeriod = '30';
$historyCloser = trim((string) ($_GET['closer'] ?? ''));
if ($historyCloser !== '' && !in_array($historyCloser, $closerIdentities, true)) $historyCloser = '';

$historyWhere = [];
$historyParams = [];
if ($historyPeriod !== 'all') {
    $historyWhere[] = 't.updated_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $historyPeriod . ' DAY)';
}
if ($historyCloser !== '') {
    $historyWhere[] = 't.closer_identity = ?';
    $historyParams[] = $historyCloser;
}
if ($historySearch !== '') {
    $historyWhere[] = "CONCAT_WS(' ', o.order_ref, o.customer_first_name, o.customer_last_name, o.phone, o.district, o.product_name, o.variant) LIKE ?";
    $historyParams[] = '%' . $historySearch . '%';
}
if ($historyStatus === 'active') {
    $historyWhere[] = "t.follow_up_status IN ('À appeler', 'À rappeler', 'Confirmée') AND o.status NOT IN ('Annulée', 'En livraison', 'Livrée')";
} elseif ($historyStatus === 'confirmed') {
    $historyWhere[] = "t.follow_up_status = 'Confirmée' AND o.status NOT IN ('Annulée', 'En livraison', 'Livrée')";
} elseif ($historyStatus === 'delivery') {
    $historyWhere[] = "o.status = 'En livraison'";
} elseif ($historyStatus === 'delivered') {
    $historyWhere[] = "o.status = 'Livrée'";
} elseif ($historyStatus === 'unreachable') {
    $historyWhere[] = "t.follow_up_status = 'Injoignable'";
} elseif ($historyStatus === 'cancelled') {
    $historyWhere[] = "t.follow_up_status = 'Annulée'";
}

$historyWhereSql = $historyWhere ? ' WHERE ' . implode(' AND ', $historyWhere) : '';
$historyCountStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM order_closer_tracking t JOIN orders o ON o.id = t.order_id' . $historyWhereSql
);
$historyCountStatement->execute($historyParams);
$historyTotal = (int) $historyCountStatement->fetchColumn();
$historyPerPage = 20;
$historyPages = max(1, (int) ceil($historyTotal / $historyPerPage));
$historyPage = max(1, (int) ($_GET['page'] ?? 1));
$historyPage = min($historyPage, $historyPages);
$historyOffset = ($historyPage - 1) * $historyPerPage;
$trackingStatement = $pdo->prepare(
    "SELECT t.*, o.order_ref, o.customer_first_name, o.customer_last_name, o.phone, o.district,
            o.product_name, o.variant, o.quantity, o.unit_price_fcfa, o.status, o.acquisition_channel
     FROM order_closer_tracking t
     JOIN orders o ON o.id = t.order_id" . $historyWhereSql . "
     ORDER BY t.updated_at DESC, t.order_id DESC
     LIMIT " . $historyPerPage . ' OFFSET ' . $historyOffset
);
$trackingStatement->execute($historyParams);
$tracking = $trackingStatement->fetchAll();
$historyQuery = ['q' => $historySearch, 'status' => $historyStatus, 'period' => $historyPeriod, 'closer' => $historyCloser];
$courierWhatsapp = (string) app_setting('courier_whatsapp', '');
$adminPageTitle = 'Suivi closeuse';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Ventes par téléphone</p>
    <h1>Suivi de la closeuse.</h1>
    <p>Les validations, rappels et messages envoyés au livreur par la closeuse apparaissent ici immédiatement.</p>
  </div>
</header>

<section class="metric-grid closer-admin-metrics">
  <article class="metric"><span>À suivre</span><strong><?= $activeCount ?></strong><small>appels et rappels encore actifs</small></article>
  <article class="metric"><span>Confirmées aujourd’hui</span><strong><?= $confirmedToday ?></strong><small>commandes passées au statut confirmée</small></article>
  <article class="metric"><span>Messages envoyés</span><strong><?= $whatsappToday ?></strong><small>commandes transmises au livreur aujourd’hui</small></article>
</section>

<section class="admin-grid" style="margin-top:15px">
  <article class="admin-panel">
    <p class="admin-kicker">Configuration livreur</p>
    <h2>Numéro WhatsApp du livreur.</h2>
    <p class="admin-copy">La closeuse partage une fiche avec la photo exacte, la couleur et le prix à encaisser. Après l’envoi, la commande passe en livraison.</p>
    <form class="data-form" method="post">
      <?= csrf_field() ?>
      <label>WhatsApp du livreur (avec indicatif pays)<input name="courier_whatsapp" inputmode="tel" maxlength="20" value="<?= e($courierWhatsapp) ?>" placeholder="223XXXXXXXX"></label>
      <button class="admin-button" type="submit">Enregistrer</button>
    </form>
  </article>
  <article class="admin-panel">
    <p class="admin-kicker">Fonctionnement</p>
    <h2>Une vue séparée, une donnée commune.</h2>
    <p class="admin-copy">La closeuse choisit ses nouvelles commandes, consigne les appels puis partage chaque commande confirmée au livreur dans un message illustré. Les statuts et canaux d’acquisition restent visibles dans les commandes et les analyses de gestion.</p>
  </article>
</section>

<section class="admin-panel" style="margin-top:15px">
  <p class="admin-kicker">Historique opérationnel</p>
  <h2>Commandes prises en charge.</h2>
  <form class="accounting-filter closer-history-filter" method="get">
    <label>Rechercher<input name="q" value="<?= e($historySearch) ?>" maxlength="80" placeholder="Client, téléphone, commande…"></label>
    <label>Statut<select name="status"><?php foreach ($historyStatuses as $value => $label): ?><option value="<?= e($value) ?>" <?= $historyStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>Closeuse<select name="closer"><option value="">Toutes</option><?php foreach ($closerIdentities as $identity): ?><option value="<?= e((string) $identity) ?>" <?= $historyCloser === $identity ? 'selected' : '' ?>><?= e((string) $identity) ?></option><?php endforeach; ?></select></label>
    <label>Période<select name="period"><?php foreach ($historyPeriods as $value => $label): ?><option value="<?= e($value) ?>" <?= $historyPeriod === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="admin-button" type="submit">Afficher</button>
    <a class="accounting-reset" href="<?= e(url('/admin/closer.php')) ?>">Réinitialiser</a>
  </form>
  <p class="accounting-result-count"><?php if ($historyTotal): ?>Affichage de <?= $historyOffset + 1 ?> à <?= min($historyOffset + $historyPerPage, $historyTotal) ?> sur <?= $historyTotal ?> commande<?= $historyTotal === 1 ? '' : 's' ?>.<?php else: ?>Aucune commande ne correspond à ces filtres.<?php endif; ?></p>
  <div class="admin-table-wrap mobile-card-table-wrap">
    <table class="admin-table closer-admin-table mobile-card-table">
      <thead><tr><th>Closeuse</th><th>Commande</th><th>Client</th><th>Produit</th><th>Suivi</th><th>Rappel</th><th>Canal</th><th>Livraison</th></tr></thead>
      <tbody>
      <?php foreach ($tracking as $item): ?>
        <tr class="mobile-card-row">
          <td data-label="Closeuse"><strong><?= e($item['closer_identity']) ?></strong></td>
          <td data-label="Commande"><strong><?= e($item['order_ref']) ?></strong><br><small>Prise en charge : <?= e(date('d/m/Y H:i', strtotime($item['created_at']))) ?></small><small>Dernière action : <?= e(date('d/m/Y H:i', strtotime($item['updated_at']))) ?></small></td>
          <td data-label="Client"><?= e($item['customer_first_name'] . ' ' . $item['customer_last_name']) ?><br><a href="tel:<?= e($item['phone']) ?>"><small><?= e($item['phone']) ?></small></a><br><small><?= e($item['district']) ?></small></td>
          <td data-label="Produit"><?= e($item['product_name']) ?><br><small><?= e($item['variant']) ?> · Qté <?= (int) $item['quantity'] ?></small></td>
          <td data-label="Suivi"><span class="status status-<?= e(strtolower(str_replace([' ', 'é', 'à'], ['-', 'e', 'a'], $item['follow_up_status']))) ?>"><?= e($item['follow_up_status']) ?></span><?php if ($item['note']): ?><br><small><?= e($item['note']) ?></small><?php endif; ?></td>
          <td data-label="Rappel"><?= $item['follow_up_at'] ? e(date('d/m/Y H:i', strtotime($item['follow_up_at']))) : '—' ?></td>
          <td data-label="Canal"><?= e((string) ($item['acquisition_channel'] ?? '—')) ?></td>
          <td data-label="Livraison"><?php if ($item['whatsapp_sent_at']): ?>Envoyé · <?= e(date('d/m H:i', strtotime($item['whatsapp_sent_at']))) ?><?php elseif ($item['whatsapp_prepared_at']): ?>Prêt · <?= e(date('d/m H:i', strtotime($item['whatsapp_prepared_at']))) ?><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tracking): ?><tr class="mobile-card-empty"><td colspan="8" class="admin-table-empty">Aucune commande n’est encore prise en charge par la closeuse.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($historyPages > 1): $pageStart = max(1, $historyPage - 2); $pageEnd = min($historyPages, $historyPage + 2); ?>
    <nav class="accounting-pagination" aria-label="Pages de l’historique closeuse">
      <?php if ($historyPage > 1): ?><a href="?<?= e(http_build_query(array_merge($historyQuery, ['page' => $historyPage - 1]))) ?>" aria-label="Page précédente">←</a><?php endif; ?>
      <?php if ($pageStart > 1): ?><a href="?<?= e(http_build_query(array_merge($historyQuery, ['page' => 1]))) ?>">1</a><?php endif; ?>
      <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?><a class="<?= $pageNumber === $historyPage ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($historyQuery, ['page' => $pageNumber]))) ?>" <?= $pageNumber === $historyPage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a><?php endfor; ?>
      <?php if ($pageEnd < $historyPages): ?><a href="?<?= e(http_build_query(array_merge($historyQuery, ['page' => $historyPages]))) ?>"><?= $historyPages ?></a><?php endif; ?>
      <?php if ($historyPage < $historyPages): ?><a href="?<?= e(http_build_query(array_merge($historyQuery, ['page' => $historyPage + 1]))) ?>" aria-label="Page suivante">→</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
