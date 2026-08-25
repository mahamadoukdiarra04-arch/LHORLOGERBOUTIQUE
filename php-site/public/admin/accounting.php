<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();

try {
    ensure_accounting_schema();
    [, $periodLabel, $start, $end] = allowed_period();
    $accounts = accounting_account_balances($pdo, null, true);
    $ted = accounting_ted_report($pdo, $start, $end);
    $recent = accounting_journal_page($pdo, accounting_journal_filters(['status' => 'confirmed', 'page' => 1]));
    $openExceptions = (int) $pdo->query('SELECT COUNT(*) FROM accounting_payment_exceptions WHERE status = "open"')->fetchColumn();
    $isInitialized = $accounts !== [];
    $treasury = $isInitialized ? accounting_treasury_total($pdo) : null;
} catch (Throwable $exception) {
    error_log('L’Horloger: tableau comptable indisponible.');
    $accountingError = accounting_safe_error_message($exception, 'La comptabilité ne peut pas être préparée pour le moment. Réessayez dans quelques instants.');
}

$adminPageTitle = 'Comptabilité';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head accounting-head">
  <div>
    <p class="admin-kicker">Comptabilité · Réel</p>
    <h1>La trésorerie, sans approximation.</h1>
    <p>Un Journal pour chaque mouvement, un TED pour comprendre le résultat, et des actions qui restent traçables.</p>
  </div>
  <a class="admin-button" href="<?= e(url('/admin/accounting-action.php')) ?>">+ Enregistrer une action</a>
</header>

<nav class="accounting-tabs" aria-label="Navigation comptabilité">
  <a class="active" href="<?= e(url('/admin/accounting.php')) ?>">Vue d’ensemble</a>
  <a href="<?= e(url('/admin/accounting-journal.php')) ?>">Journal</a>
  <a href="<?= e(url('/admin/accounting-ted.php')) ?>">TED & rentabilité</a>
  <a href="<?= e(url('/admin/accounting-settings.php')) ?>">Comptes & réglages</a>
</nav>

<?php if (isset($accountingError)): ?>
  <section class="admin-panel"><p class="flash flash-error"><?= e($accountingError) ?></p></section>
<?php elseif (!$isInitialized): ?>
  <section class="accounting-empty admin-panel">
    <p class="admin-kicker">Première étape</p>
    <h2>Commencez avec les comptes réellement utilisés.</h2>
    <p class="admin-copy">Ajoutez uniquement la caisse, le compte bancaire ou le Mobile Money existants, avec leur solde d’ouverture réel. Aucun chiffre n’est inventé par le système.</p>
    <a class="admin-button" href="<?= e(url('/admin/accounting-settings.php')) ?>">Configurer les comptes</a>
  </section>
<?php else: ?>
  <section class="admin-period accounting-period" aria-label="Période d’analyse">
    <?php foreach (['today' => 'Aujourd’hui', '7' => '7 jours', '30' => '30 jours', '90' => '90 jours', 'month' => 'Ce mois', 'year' => 'Cette année'] as $key => $label): ?>
      <a class="<?= ($_GET['period'] ?? '30') === $key ? 'active' : '' ?>" href="?period=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('/admin/accounting-ted.php?period=' . rawurlencode((string) ($_GET['period'] ?? '30')))) ?>">Période personnalisée →</a>
  </section>

  <section class="metric-grid accounting-metrics">
    <article class="metric"><p>Trésorerie actuelle</p><strong><?= money($treasury) ?></strong><span><?= count($accounts) ?> compte(s) actif(s)</span></article>
    <article class="metric"><p>CA net · <?= e($periodLabel) ?></p><strong><?= money($ted['net_product_revenue_fcfa'] + $ted['net_shop_revenue_fcfa']) ?></strong><span>Ventes moins remboursements</span></article>
    <article class="metric"><p>Résultat boutique</p><strong><?= money($ted['shop_result_fcfa']) ?></strong><span><?= $ted['is_complete'] ? 'Période entièrement ventilée' : $ted['unallocated_operation_count'] . ' écriture(s) à vérifier' ?></span></article>
    <article class="metric"><p>Reliquats à suivre</p><strong><?= $openExceptions ?></strong><span>Commande(s) livrée(s) avec solde</span></article>
  </section>

  <section class="accounting-action-grid">
    <a class="accounting-action-card" href="<?= e(url('/admin/accounting-action.php?action=direct_sale')) ?>"><span>Vente</span><strong>Vente directe</strong><small>Encaisser une vente hors site et traiter le stock.</small></a>
    <a class="accounting-action-card" href="<?= e(url('/admin/accounting-action.php?action=collect_balance')) ?>"><span>Commande</span><strong>Régulariser un reliquat</strong><small>Encaisser une commande déjà livrée.</small></a>
    <a class="accounting-action-card" href="<?= e(url('/admin/accounting-action.php?action=disbursement')) ?>"><span>Dépense</span><strong>Décaisser</strong><small>Enregistrer une charge Produit ou Boutique.</small></a>
    <a class="accounting-action-card" href="<?= e(url('/admin/accounting-action.php?action=transfer')) ?>"><span>Trésorerie</span><strong>Transférer</strong><small>Déplacer des fonds, avec frais si nécessaire.</small></a>
  </section>

  <section class="admin-grid accounting-overview-grid">
    <article class="admin-panel">
      <div class="admin-panel__head"><div><p class="admin-kicker">Comptes réels</p><h2>Où se trouve l’argent ?</h2></div><a href="<?= e(url('/admin/accounting-settings.php')) ?>">Gérer les comptes →</a></div>
      <div class="accounting-account-list">
        <?php foreach ($accounts as $account): ?><div class="accounting-account"><span><strong><?= e($account['name']) ?></strong><small><?= e($account['code']) ?> · <?= e($account['account_type']) ?></small></span><strong><?= money($account['balance_fcfa']) ?></strong></div><?php endforeach; ?>
      </div>
    </article>
    <article class="admin-panel">
      <div class="admin-panel__head"><div><p class="admin-kicker">Période · <?= e($periodLabel) ?></p><h2>Lecture rapide du TED.</h2></div><a href="<?= e(url('/admin/accounting-ted.php?period=' . rawurlencode((string) ($_GET['period'] ?? '30')))) ?>">Voir le TED →</a></div>
      <div class="accounting-summary-list"><div><span>CA net montres</span><strong><?= money($ted['net_product_revenue_fcfa']) ?></strong></div><div><span>Coût des montres vendues</span><strong><?= money($ted['cogs_fcfa']) ?></strong></div><div><span>Charges directes</span><strong><?= money($ted['direct_expense_fcfa']) ?></strong></div><div><span>Charges boutique</span><strong><?= money($ted['common_expense_fcfa']) ?></strong></div></div>
    </article>
  </section>

  <section class="admin-panel accounting-recent">
    <div class="admin-panel__head"><div><p class="admin-kicker">Journal récent</p><h2>Les derniers mouvements confirmés.</h2></div><a href="<?= e(url('/admin/accounting-journal.php')) ?>">Ouvrir le Journal →</a></div>
    <?php if ($recent['rows'] === []): ?><p class="admin-copy">Aucun mouvement confirmé pour le moment.</p><?php else: ?><div class="accounting-account-list"><?php foreach (array_slice($recent['rows'], 0, 5) as $operation): ?><a class="accounting-account" href="<?= e(url('/admin/accounting-operation.php?operation=' . (int) $operation['id'])) ?>"><span><strong><?= e($operation['label']) ?></strong><small><?= e(date('d/m/Y H:i', strtotime($operation['effective_at']))) ?> · <?= e($operation['group_reference']) ?></small></span><strong class="<?= $operation['nature'] === 'disbursement' ? 'amount-negative' : '' ?>"><?= $operation['nature'] === 'disbursement' ? '−' : ($operation['nature'] === 'receipt' ? '+' : '↔') ?><?= money($operation['amount_fcfa']) ?></strong></a><?php endforeach; ?></div><?php endif; ?>
  </section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
