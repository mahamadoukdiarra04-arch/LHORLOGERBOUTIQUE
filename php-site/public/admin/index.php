<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();

[$periodKey, $periodLabel, $start, $end] = allowed_period();
$pdo = db();

try {
    ensure_accounting_schema();
    $accountsReady = accounting_has_active_accounts($pdo);
    $ted = $accountsReady ? accounting_ted_report($pdo, $start, $end) : null;

    $salesCountStatement = $pdo->prepare(
        'SELECT COUNT(DISTINCT g.id)
         FROM accounting_operation_groups g
         INNER JOIN accounting_operations o ON o.group_id = g.id AND o.status = "confirmed"
         WHERE g.group_type IN ("delivery", "direct_sale")
           AND o.effective_at BETWEEN ? AND ?'
    );
    $salesCountStatement->execute(accounting_period_bounds($start, $end));
    $accountedSales = (int) $salesCountStatement->fetchColumn();
} catch (Throwable $exception) {
    error_log('L’Horloger: tableau de bord comptable indisponible.');
    $financeError = accounting_safe_error_message($exception, 'Les indicateurs comptables ne peuvent pas être préparés pour le moment.');
    $accountsReady = false;
    $ted = null;
    $accountedSales = 0;
}

$channelsStatement = $pdo->prepare(
    "SELECT COALESCE(acquisition_channel, 'À renseigner') AS channel,
            COUNT(DISTINCT order_ref) AS orders,
            COALESCE(SUM(quantity * unit_price_fcfa), 0) AS revenue
     FROM orders
     WHERE status = 'Livrée'
       AND DATE(COALESCE(delivered_at, created_at)) BETWEEN ? AND ?
     GROUP BY acquisition_channel
     ORDER BY orders DESC, channel ASC"
);
$channelsStatement->execute([$start, $end]);
$channels = $channelsStatement->fetchAll();

$metaStatement = $pdo->prepare(
    'SELECT COALESCE(SUM(amount_fcfa), 0) FROM ad_costs WHERE start_date <= ? AND end_date >= ?'
);
$metaStatement->execute([$end, $start]);
$metaTrackingSpend = (int) $metaStatement->fetchColumn();
$metaOrders = 0;
foreach ($channels as $channel) {
    if ($channel['channel'] === 'Meta') $metaOrders = (int) $channel['orders'];
}

$stock = $pdo->query(
    'SELECT p.id, p.name, COALESCE(SUM(sm.quantity), 0) AS quantity
     FROM products p LEFT JOIN stock_movements sm ON sm.product_id = p.id
     GROUP BY p.id, p.name ORDER BY p.id ASC'
)->fetchAll();
$low = array_values(array_filter($stock, static fn(array $item): bool => (int) $item['quantity'] <= 6));
$recent = $pdo->query(
    'SELECT id, order_ref, customer_first_name, customer_last_name, product_name, variant, quantity, status, unit_price_fcfa, created_at
     FROM orders ORDER BY created_at DESC LIMIT 6'
)->fetchAll();
$periodQuery = http_build_query(['period' => $periodKey, 'start' => $start, 'end' => $end]);

$adminPageTitle = 'Vue d’ensemble';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Pilotage · Réalisé</p>
    <h1>Voir ce qui fait avancer la boutique.</h1>
    <p><?= e($periodLabel) ?> · les chiffres financiers viennent uniquement des écritures comptables confirmées.</p>
  </div>
  <form class="admin-period" method="get">
    <?php foreach (['today' => 'Aujourd’hui', '7' => '7 jours', '14' => '14 jours', '30' => '30 jours', '90' => '90 jours', 'month' => 'Ce mois', 'quarter' => 'Trimestre', 'year' => 'Cette année'] as $key => $label): ?>
      <a class="<?= $periodKey === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(['period' => $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="period" value="custom"><span class="custom-period"><input type="date" name="start" value="<?= e($start) ?>"><input type="date" name="end" value="<?= e($end) ?>"><button class="admin-button">Choisir</button></span>
  </form>
</header>

<?php if (isset($financeError)): ?>
  <section class="admin-panel"><p class="flash flash-error"><?= e($financeError) ?></p></section>
<?php elseif (!$accountsReady): ?>
  <section class="admin-panel finance-context"><p class="admin-kicker">Comptabilité à initialiser</p><h2>Les indicateurs réalisés apparaîtront après la création d’un compte réel.</h2><p class="admin-copy">Aucun CA, coût ou résultat ne sera estimé ici. Ajoutez d’abord la caisse, le compte bancaire ou le Mobile Money réellement utilisé.</p><a class="admin-button" href="<?= e(url('/admin/accounting-settings.php')) ?>">Configurer les comptes</a></section>
<?php else: ?>
  <section class="metric-grid">
    <article class="metric"><p>CA net réalisé</p><strong><?= money($ted['net_product_revenue_fcfa'] + $ted['net_shop_revenue_fcfa']) ?></strong><span>Ventes moins remboursements</span></article>
    <article class="metric"><p>Marge brute réalisée</p><strong><?= money($ted['gross_margin_fcfa']) ?></strong><span>CA montres net − coût des montres vendues</span></article>
    <article class="metric"><p>Contribution montres</p><strong><?= money($ted['product_contribution_fcfa']) ?></strong><span>Après charges directes, dont Meta comptabilisé</span></article>
    <article class="metric"><p>Ventes comptabilisées</p><strong><?= $accountedSales ?></strong><span>Livraisons et ventes directes de la période</span></article>
  </section>
  <section class="admin-panel finance-context"><div class="admin-panel__head"><div><p class="admin-kicker">Source unique</p><h2>Lecture financière réalisée.</h2></div><a href="<?= e(url('/admin/accounting-ted.php?' . $periodQuery)) ?>">Voir le TED →</a></div><p class="admin-copy">Les données de trésorerie et de rentabilité sont identiques dans le TED, l’Analyse produits et les fiches Stock pour cette période.</p><a class="text-link" href="<?= e(url('/admin/accounting-journal.php?start=' . rawurlencode($start) . '&end=' . rawurlencode($end))) ?>">Contrôler le Journal de la période →</a></section>
<?php endif; ?>

<section class="admin-grid">
  <article class="admin-panel"><div class="admin-panel__head"><div><p class="admin-kicker">À traiter</p><h2>Commandes récentes</h2></div><a href="<?= e(url('/admin/orders.php')) ?>">Tout voir</a></div><div class="mini-table"><?php foreach ($recent as $order): ?><div><span><strong><?= e($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></strong><small><?= e($order['order_ref']) ?> · <?= e($order['product_name']) ?> · <?= e($order['variant']) ?></small></span><span class="status <?= $order['status'] === 'Livrée' ? 'delivered' : ($order['status'] === 'En livraison' ? 'delivery' : '') ?>"><?= e($order['status']) ?></span><strong><?= money((int) $order['quantity'] * (int) $order['unit_price_fcfa']) ?></strong></div><?php endforeach; ?><?php if ($recent === []): ?><div><span>Aucune commande pour le moment.</span></div><?php endif; ?></div></article>
  <article class="admin-panel"><p class="admin-kicker">Attribution commerciale</p><h2>Canaux suivis</h2><p class="admin-copy">Suivi des commandes web livrées, distinct de la trésorerie comptable.</p><div class="channel-list"><?php foreach ($channels as $channel): ?><div><strong><?= e($channel['channel']) ?></strong><b><?= money((int) $channel['revenue']) ?></b><span><?= (int) $channel['orders'] ?> commande(s) web livrée(s)</span><span><?= $channel['channel'] === 'Meta' && $metaTrackingSpend > 0 && $metaOrders > 0 ? 'CAC marketing suivi · ' . money(intdiv($metaTrackingSpend, $metaOrders)) : ($channel['channel'] === 'Meta' ? 'Coût Meta de suivi à renseigner' : 'Sans dépense attribuée') ?></span></div><?php endforeach; ?><?php if ($channels === []): ?><p>Aucun canal renseigné sur cette période.</p><?php endif; ?></div></article>
</section>
<section class="admin-panel finance-context" style="margin-top:15px"><p class="admin-kicker">Suivi Meta marketing</p><h2><?= $metaTrackingSpend > 0 ? money($metaTrackingSpend) : 'Aucun coût renseigné' ?></h2><p class="admin-copy">Ce suivi aide à piloter le CAC des commandes web. Il n’entre pas dans le résultat réalisé tant qu’un décaissement Meta n’est pas comptabilisé.</p><a class="text-link" href="<?= e(url('/admin/stock.php')) ?>">Renseigner ou consulter les coûts Meta →</a></section>
<?php if ($low !== []): ?><section class="admin-panel low-stock" style="margin-top:14px"><p class="admin-kicker">Stock à surveiller</p><h2><?= count($low) ?> modèle(s) atteignent le seuil de 6 unités.</h2><p><?php foreach ($low as $item): ?><strong><?= e($item['name']) ?></strong> : <?= (int) $item['quantity'] ?> unité(s) · <?php endforeach; ?></p><a class="admin-button" href="<?= e(url('/admin/stock.php')) ?>">Mettre le stock à jour</a></section><?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
