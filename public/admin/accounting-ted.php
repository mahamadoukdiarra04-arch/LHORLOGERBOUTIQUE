<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
try {
    ensure_accounting_schema();
    [, $periodLabel, $start, $end] = allowed_period();
    $ted = accounting_ted_report($pdo, $start, $end);
    $products = accounting_product_results($pdo, $start, $end);
} catch (Throwable $exception) {
    error_log('L’Horloger: TED indisponible.');
    $tedError = accounting_safe_error_message($exception, 'Le TED ne peut pas être préparé pour le moment.');
}
$adminPageTitle = 'TED & rentabilité';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head accounting-head"><div><p class="admin-kicker">Comptabilité · TED</p><h1>Comprendre ce qui crée le résultat.</h1><p>Le tableau économique distingue le CA, le coût des montres, les charges directes et les charges de boutique.</p></div><a class="admin-button" href="<?= e(url('/admin/accounting-journal.php')) ?>">Ouvrir le Journal</a></header>
<nav class="accounting-tabs" aria-label="Navigation comptabilité"><a href="<?= e(url('/admin/accounting.php')) ?>">Vue d’ensemble</a><a href="<?= e(url('/admin/accounting-journal.php')) ?>">Journal</a><a class="active" href="<?= e(url('/admin/accounting-ted.php')) ?>">TED & rentabilité</a><a href="<?= e(url('/admin/accounting-settings.php')) ?>">Comptes & réglages</a></nav>
<?php if (isset($tedError)): ?><section class="admin-panel"><p class="flash flash-error"><?= e($tedError) ?></p></section><?php else: ?>
<section class="admin-period accounting-period"><a class="<?= ($_GET['period'] ?? '30') === 'today' ? 'active' : '' ?>" href="?period=today">Aujourd’hui</a><a class="<?= ($_GET['period'] ?? '30') === '7' ? 'active' : '' ?>" href="?period=7">7 jours</a><a class="<?= ($_GET['period'] ?? '30') === '30' ? 'active' : '' ?>" href="?period=30">30 jours</a><a class="<?= ($_GET['period'] ?? '30') === '90' ? 'active' : '' ?>" href="?period=90">90 jours</a><a class="<?= ($_GET['period'] ?? '30') === 'month' ? 'active' : '' ?>" href="?period=month">Ce mois</a><a class="<?= ($_GET['period'] ?? '30') === 'year' ? 'active' : '' ?>" href="?period=year">Cette année</a><form class="accounting-custom-period" method="get"><input type="hidden" name="period" value="custom"><label>Du<input type="date" name="start" value="<?= e($_GET['start'] ?? '') ?>" required></label><label>Au<input type="date" name="end" value="<?= e($_GET['end'] ?? '') ?>" required></label><button>Appliquer</button></form></section>
<section class="ted-hero"><div><span>Période analysée</span><strong><?= e($periodLabel) ?></strong><small><?= e(date('d/m/Y', strtotime($ted['start_at']))) ?> → <?= e(date('d/m/Y', strtotime($ted['end_at']))) ?></small></div><div><span>Résultat boutique</span><strong><?= money($ted['shop_result_fcfa']) ?></strong><small><?= $ted['is_complete'] ? 'Toutes les écritures sont ventilées' : $ted['unallocated_operation_count'] . ' écriture(s) non ventilée(s)' ?></small></div></section>
<section class="ted-grid"><article><p>CA net montres</p><strong><?= money($ted['net_product_revenue_fcfa']) ?></strong><small>Ventes <?= money($ted['product_revenue_fcfa']) ?> · remboursements <?= money($ted['product_refund_fcfa']) ?></small></article><article><p>Coût des montres vendues</p><strong><?= money($ted['cogs_fcfa']) ?></strong><small>Coûts figés à la sortie de stock</small></article><article><p>Marge brute</p><strong><?= money($ted['gross_margin_fcfa']) ?></strong><small>CA net − coût des montres</small></article><article><p>Charges directes</p><strong><?= money($ted['direct_expense_fcfa']) ?></strong><small>Pub Meta et coûts liés aux produits</small></article><article><p>Contribution montres</p><strong><?= money($ted['product_contribution_fcfa']) ?></strong><small>Marge après charges directes</small></article><article><p>Charges boutique</p><strong><?= money($ted['common_expense_fcfa']) ?></strong><small>Loyer, télécoms, frais bancaires…</small></article></section>
<section class="admin-panel"><div class="admin-panel__head"><div><p class="admin-kicker">Par produit</p><h2>Rentabilité réalisée.</h2></div><a href="<?= e(url('/admin/analysis.php')) ?>">Analyse produits →</a></div><?php if ($products === []): ?><p class="admin-copy">Aucune vente comptabilisée sur cette période.</p><?php else: ?><div class="product-result-list"><?php foreach ($products as $product): ?><article><div><strong><?= e($product['product_name'] ?? 'Produit retiré') ?></strong><small>CA net <?= money($product['net_revenue_fcfa']) ?> · CMV <?= money($product['cogs_fcfa']) ?></small></div><div><span>Contribution</span><strong><?= money($product['contribution_fcfa']) ?></strong></div></article><?php endforeach; ?></div><?php endif; ?></section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
