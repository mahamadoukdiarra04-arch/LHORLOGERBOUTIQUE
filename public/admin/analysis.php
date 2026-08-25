<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();

[$periodKey, $periodLabel, $start, $end] = allowed_period();
$pdo = db();

try {
    ensure_accounting_schema();
    $accountsReady = accounting_has_active_accounts($pdo);
    $realized = $accountsReady ? accounting_product_results($pdo, $start, $end) : [];
} catch (Throwable $exception) {
    error_log('L’Horloger: analyse produit comptable indisponible.');
    $analysisError = accounting_safe_error_message($exception, 'L’analyse comptable ne peut pas être préparée pour le moment.');
    $accountsReady = false;
    $realized = [];
}

$realizedByProduct = [];
foreach ($realized as $result) $realizedByProduct[(int) $result['product_id']] = $result;

$statement = $pdo->prepare(
    "SELECT p.id, p.name, p.slug,
            COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.product_id = p.id), 0) AS stock,
            COALESCE((SELECT SUM(o.quantity) FROM orders o WHERE o.product_id = p.id AND o.status = 'Livrée' AND DATE(COALESCE(o.delivered_at, o.created_at)) BETWEEN ? AND ?), 0) AS web_units,
            COALESCE((SELECT SUM(ac.amount_fcfa) FROM ad_costs ac WHERE ac.product_id = p.id AND ac.start_date <= ? AND ac.end_date >= ?), 0) AS meta_tracking_fcfa
     FROM products p
     ORDER BY p.name ASC, p.id ASC"
);
$statement->execute([$start, $end, $end, $start]);
$rows = $statement->fetchAll();

foreach ($rows as &$row) {
    $actual = $realizedByProduct[(int) $row['id']] ?? null;
    $row['is_realized'] = $actual !== null;
    $row['net_revenue_fcfa'] = $actual['net_revenue_fcfa'] ?? 0;
    $row['cogs_fcfa'] = $actual['cogs_fcfa'] ?? 0;
    $row['direct_expense_fcfa'] = $actual['direct_expense_fcfa'] ?? 0;
    $row['contribution_fcfa'] = $actual['contribution_fcfa'] ?? 0;
    $row['meta_ads_fcfa'] = $actual['meta_ads_fcfa'] ?? 0;
    $row['web_units'] = (int) $row['web_units'];
    $row['meta_tracking_fcfa'] = (int) $row['meta_tracking_fcfa'];
    $row['marketing_cac_fcfa'] = $row['web_units'] > 0 && $row['meta_tracking_fcfa'] > 0
        ? intdiv($row['meta_tracking_fcfa'], $row['web_units'])
        : null;
}
unset($row);

$sort = (string) ($_GET['sort'] ?? 'revenue');
$sorts = ['revenue', 'contribution', 'units', 'cac'];
if (!in_array($sort, $sorts, true)) $sort = 'revenue';
usort($rows, static function (array $left, array $right) use ($sort): int {
    if ($sort === 'cac') return ($left['marketing_cac_fcfa'] ?? PHP_INT_MAX) <=> ($right['marketing_cac_fcfa'] ?? PHP_INT_MAX);
    if ($sort === 'units') return $right['web_units'] <=> $left['web_units'];
    $key = $sort === 'contribution' ? 'contribution_fcfa' : 'net_revenue_fcfa';
    return $right[$key] <=> $left[$key];
});

$realizedRows = array_values(array_filter($rows, static fn(array $row): bool => $row['is_realized']));
$bestRevenue = $realizedRows[0] ?? null;
$contributionRows = $realizedRows;
usort($contributionRows, static fn(array $left, array $right): int => $right['contribution_fcfa'] <=> $left['contribution_fcfa']);
$bestContribution = $contributionRows[0] ?? null;
$lowestStock = $rows;
usort($lowestStock, static fn(array $left, array $right): int => $left['stock'] <=> $right['stock']);
$lowestStock = $lowestStock[0] ?? null;

$adminPageTitle = 'Analyse produits';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Analyse produits · Réalisé</p>
    <h1>Voir ce qui est vraiment rentable.</h1>
    <p>CA, coût des montres et contribution sont issus du Journal comptable confirmé. Le suivi Meta marketing reste identifié séparément.</p>
  </div>
  <form class="admin-period" method="get">
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <?php foreach (['today' => 'Aujourd’hui', '7' => '7 jours', '14' => '14 jours', '30' => '30 jours', '90' => '90 jours', 'month' => 'Ce mois', 'quarter' => 'Trimestre', 'year' => 'Cette année'] as $key => $label): ?>
      <a class="<?= $periodKey === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(['period' => $key, 'sort' => $sort])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="period" value="custom"><span class="custom-period"><input type="date" name="start" value="<?= e($start) ?>"><input type="date" name="end" value="<?= e($end) ?>"><button class="admin-button">Choisir</button></span>
  </form>
</header>

<?php if (isset($analysisError)): ?>
  <section class="admin-panel"><p class="flash flash-error"><?= e($analysisError) ?></p></section>
<?php elseif (!$accountsReady): ?>
  <section class="admin-panel finance-context"><p class="admin-kicker">Comptabilité à initialiser</p><h2>La rentabilité réalisée apparaîtra après la création d’un compte réel.</h2><p class="admin-copy">Aucune marge estimée à partir du coût de stock n’est affichée ici : l’analyse attend les mouvements comptables confirmés.</p><a class="admin-button" href="<?= e(url('/admin/accounting-settings.php')) ?>">Configurer les comptes</a></section>
<?php else: ?>
  <section class="metric-grid">
    <article class="metric"><p>Meilleur CA net réalisé</p><strong><?= e($bestRevenue['name'] ?? '—') ?></strong><span><?= $bestRevenue ? money($bestRevenue['net_revenue_fcfa']) : 'Aucune vente comptabilisée' ?></span></article>
    <article class="metric"><p>Meilleure contribution</p><strong><?= e($bestContribution['name'] ?? '—') ?></strong><span><?= $bestContribution ? money($bestContribution['contribution_fcfa']) : 'Aucune vente comptabilisée' ?></span></article>
    <article class="metric"><p>À sécuriser</p><strong><?= e($lowestStock['name'] ?? '—') ?></strong><span><?= (int) ($lowestStock['stock'] ?? 0) ?> unité(s) disponible(s)</span></article>
    <article class="metric"><p>Période active</p><strong><?= e($periodLabel) ?></strong><span><?= e($start) ?> → <?= e($end) ?></span></article>
  </section>

  <div class="admin-filter"><span style="align-self:center;font-size:12px;font-weight:700">Classer par :</span><?php foreach (['revenue' => 'CA net réalisé', 'contribution' => 'Contribution', 'units' => 'Ventes web', 'cac' => 'CAC marketing'] as $key => $label): ?><a class="<?= $sort === $key ? 'admin-button' : '' ?>" style="padding:9px 11px;border:1px solid #d3dfeb;border-radius:5px;font-size:11px" href="?<?= e(http_build_query(['period' => $periodKey, 'start' => $start, 'end' => $end, 'sort' => $key])) ?>"><?= e($label) ?></a><?php endforeach; ?></div>

  <section class="admin-panel finance-context"><p class="admin-kicker">Lecture des données</p><p class="admin-copy"><strong>Réalisé :</strong> montants issus de la trésorerie comptable. <strong>Suivi Meta marketing :</strong> coûts saisis dans Stock, utiles au CAC des commandes web et volontairement exclus du résultat tant qu’ils ne sont pas saisis comme décaissement Meta.</p><a class="text-link" href="<?= e(url('/admin/accounting-ted.php?period=' . rawurlencode($periodKey) . '&start=' . rawurlencode($start) . '&end=' . rawurlencode($end))) ?>">Contrôler le TED de cette période →</a></section>

  <section class="admin-panel" style="margin-top:15px"><p class="admin-kicker">Classement détaillé</p><h2>Produits par rentabilité réalisée.</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Produit</th><th>Ventes web</th><th>CA net réalisé</th><th>CMV réalisé</th><th>Charges directes</th><th>Contribution</th><th>Suivi Meta</th><th>CAC marketing</th><th>Stock</th></tr></thead><tbody><?php foreach ($rows as $index => $row): ?><tr><td><strong><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($row['name']) ?></strong><small><?= e($row['slug']) ?></small></td><td><?= $row['web_units'] ?> unité(s)</td><td><strong><?= $row['is_realized'] ? money($row['net_revenue_fcfa']) : 'Aucune écriture' ?></strong></td><td><?= $row['is_realized'] ? money($row['cogs_fcfa']) : '—' ?></td><td><?= $row['is_realized'] ? money($row['direct_expense_fcfa']) : '—' ?></td><td><strong><?= $row['is_realized'] ? money($row['contribution_fcfa']) : '—' ?></strong></td><td><?= $row['meta_tracking_fcfa'] > 0 ? money($row['meta_tracking_fcfa']) : 'À renseigner' ?></td><td><?= $row['marketing_cac_fcfa'] !== null ? money($row['marketing_cac_fcfa']) : ($row['web_units'] > 0 ? 'Coût Meta à renseigner' : 'Aucune vente web') ?></td><td><span class="status <?= (int) $row['stock'] <= 6 ? '' : 'delivered' ?>"><?= (int) $row['stock'] ?> unité(s)</span></td></tr><?php endforeach; ?><?php if ($rows === []): ?><tr><td colspan="9">Aucun produit actif.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
