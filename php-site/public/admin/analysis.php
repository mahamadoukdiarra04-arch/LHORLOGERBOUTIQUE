<?php
require __DIR__ . '/../../app/bootstrap.php';
require_admin();

[$periodKey, $periodLabel, $start, $end] = allowed_period();
$pdo = db();

$query = "
    SELECT
        p.id,
        p.name,
        p.slug,
        p.price_fcfa,
        COALESCE((
            SELECT SUM(sm.quantity)
            FROM stock_movements sm
            WHERE sm.product_id = p.id
        ), 0) AS stock,
        COALESCE((
            SELECT SUM(o.quantity)
            FROM orders o
            WHERE o.product_id = p.id
              AND o.status = 'Livrée'
              AND DATE(o.created_at) BETWEEN ? AND ?
        ), 0) AS units,
        COALESCE((
            SELECT SUM(o.quantity * o.unit_price_fcfa)
            FROM orders o
            WHERE o.product_id = p.id
              AND o.status = 'Livrée'
              AND DATE(o.created_at) BETWEEN ? AND ?
        ), 0) AS revenue,
        (
            SELECT
                SUM(sm.purchase_price_fcfa + COALESCE(sm.transit_price_fcfa, 0))
                / NULLIF(SUM(sm.quantity), 0)
            FROM stock_movements sm
            WHERE sm.product_id = p.id
              AND sm.movement_type = 'Réassort'
              AND sm.purchase_price_fcfa IS NOT NULL
        ) AS unit_cost,
        COALESCE((
            SELECT SUM(ac.amount_fcfa)
            FROM ad_costs ac
            WHERE ac.product_id = p.id
              AND ac.start_date <= ?
              AND ac.end_date >= ?
        ), 0) AS ad_spend
    FROM products p
    ORDER BY revenue DESC
";

$statement = $pdo->prepare($query);
$statement->execute([$start, $end, $start, $end, $end, $start]);
$rows = $statement->fetchAll();

foreach ($rows as &$row) {
    $row['gross_margin'] = (int) $row['units'] > 0 && $row['unit_cost'] === null
        ? null
        : (float) $row['revenue']
            - ((int) $row['units'] * (float) ($row['unit_cost'] ?? 0))
            - (float) $row['ad_spend'];
    $row['cac'] = (int) $row['units'] > 0 && (float) $row['ad_spend'] > 0
        ? (float) $row['ad_spend'] / (int) $row['units']
        : null;
}
unset($row);

$sort = (string) ($_GET['sort'] ?? 'revenue');
$allowedSort = ['revenue', 'gross_margin', 'units', 'cac'];
if (!in_array($sort, $allowedSort, true)) $sort = 'revenue';

usort($rows, static function (array $left, array $right) use ($sort): int {
    if ($sort === 'cac') return ($left['cac'] ?? PHP_FLOAT_MAX) <=> ($right['cac'] ?? PHP_FLOAT_MAX);
    if ($sort === 'gross_margin') {
        return ($right['gross_margin'] ?? -PHP_FLOAT_MAX) <=> ($left['gross_margin'] ?? -PHP_FLOAT_MAX);
    }
    return $right[$sort] <=> $left[$sort];
});

$bestRevenue = $rows[0] ?? null;
$marginRows = array_values(array_filter($rows, static fn(array $row): bool => $row['gross_margin'] !== null));
usort($marginRows, static fn(array $left, array $right): int => $right['gross_margin'] <=> $left['gross_margin']);
$bestMargin = $marginRows[0] ?? null;
$lowestStock = $rows;
usort($lowestStock, static fn(array $left, array $right): int => $left['stock'] <=> $right['stock']);
$lowestStock = $lowestStock[0] ?? null;

$adminPageTitle = 'Analyse produits';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Analyse produits</p>
    <h1>Voir ce qui est vraiment rentable.</h1>
    <p>Chiffre d’affaires, marge, publicité et CAC sont calculés sur la période choisie.</p>
  </div>
  <form class="admin-period" method="get">
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <?php foreach (['today' => 'Aujourd’hui', '7' => '7 jours', '14' => '14 jours', '30' => '30 jours', '90' => '90 jours', 'month' => 'Ce mois', 'quarter' => 'Trimestre', 'year' => 'Cette année'] as $key => $label): ?>
      <a class="<?= $periodKey === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(['period' => $key, 'sort' => $sort])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="period" value="custom">
    <span class="custom-period">
      <input type="date" name="start" value="<?= e($start) ?>">
      <input type="date" name="end" value="<?= e($end) ?>">
      <button class="admin-button">Choisir</button>
    </span>
  </form>
</header>

<section class="metric-grid">
  <article class="metric">
    <p>Meilleur CA</p>
    <strong><?= e($bestRevenue['name'] ?? '—') ?></strong>
    <span><?= e(money((float) ($bestRevenue['revenue'] ?? 0))) ?></span>
  </article>
  <article class="metric">
    <p>Meilleure marge</p>
    <strong><?= e($bestMargin['name'] ?? 'À renseigner') ?></strong>
    <span><?= $bestMargin ? e(money((float) $bestMargin['gross_margin'])) : 'Coûts unitaires à renseigner' ?></span>
  </article>
  <article class="metric">
    <p>À sécuriser</p>
    <strong><?= e($lowestStock['name'] ?? '—') ?></strong>
    <span><?= (int) ($lowestStock['stock'] ?? 0) ?> unité(s) disponible(s)</span>
  </article>
  <article class="metric">
    <p>Période active</p>
    <strong><?= e($periodLabel) ?></strong>
    <span><?= e($start) ?> → <?= e($end) ?></span>
  </article>
</section>

<div class="admin-filter">
  <span style="align-self:center;font-size:12px;font-weight:700">Classer par :</span>
  <?php foreach (['revenue' => 'Chiffre d’affaires', 'gross_margin' => 'Marge', 'units' => 'Ventes', 'cac' => 'CAC Meta'] as $key => $label): ?>
    <a class="<?= $sort === $key ? 'admin-button' : '' ?>" style="padding:9px 11px;border:1px solid #d3dfeb;border-radius:5px;font-size:11px" href="?<?= e(http_build_query(['period' => $periodKey, 'start' => $start, 'end' => $end, 'sort' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<section class="admin-panel">
  <p class="admin-kicker">Classement détaillé</p>
  <h2>Produits par rentabilité.</h2>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Produit</th><th>Ventes</th><th>CA</th><th>Coût unitaire</th><th>Ads Meta</th><th>CAC Meta</th><th>Marge après ads</th><th>Stock</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
          <tr>
            <td><strong>0<?= $index + 1 ?> · <?= e($row['name']) ?></strong><small><?= e($row['slug']) ?></small></td>
            <td><?= (int) $row['units'] ?> unité(s)</td>
            <td><strong><?= e(money((float) $row['revenue'])) ?></strong></td>
            <td><?= $row['unit_cost'] !== null ? e(money((float) $row['unit_cost'])) : 'À renseigner' ?></td>
            <td><?= (float) $row['ad_spend'] > 0 ? e(money((float) $row['ad_spend'])) : 'À renseigner' ?></td>
            <td><?= $row['cac'] !== null ? e(money((float) $row['cac'])) : ((int) $row['units'] ? 'Renseignez les ads' : 'Aucune vente') ?></td>
            <td><strong><?= $row['gross_margin'] !== null ? e(money((float) $row['gross_margin'])) : 'À renseigner' ?></strong></td>
            <td><span class="status <?= (int) $row['stock'] <= 6 ? '' : 'delivered' ?>"><?= (int) $row['stock'] ?> unité(s)</span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
