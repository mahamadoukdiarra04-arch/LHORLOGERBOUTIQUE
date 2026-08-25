<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
try {
    ensure_accounting_schema();
    $filters = accounting_journal_filters($_GET);
    $journal = accounting_journal_page($pdo, $filters);
    $accounts = accounting_list_accounts($pdo, false);
    $categories = accounting_list_categories($pdo, false);
    $products = $pdo->query('SELECT id, name FROM products ORDER BY name ASC, id ASC')->fetchAll();
} catch (Throwable $exception) {
    error_log('L’Horloger: journal comptable indisponible.');
    $journalError = accounting_safe_error_message($exception, 'Le Journal ne peut pas être préparé pour le moment.');
}
$adminPageTitle = 'Journal comptable';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head accounting-head"><div><p class="admin-kicker">Comptabilité · Journal</p><h1>Toutes les traces, dans l’ordre.</h1><p>Les écritures confirmées ne sont jamais modifiées ; une correction passe par une contrepassation visible.</p></div><a class="admin-button" href="<?= e(url('/admin/accounting-action.php')) ?>">+ Nouvelle action</a></header>
<nav class="accounting-tabs" aria-label="Navigation comptabilité"><a href="<?= e(url('/admin/accounting.php')) ?>">Vue d’ensemble</a><a class="active" href="<?= e(url('/admin/accounting-journal.php')) ?>">Journal</a><a href="<?= e(url('/admin/accounting-ted.php')) ?>">TED & rentabilité</a><a href="<?= e(url('/admin/accounting-settings.php')) ?>">Comptes & réglages</a></nav>
<?php if (isset($journalError)): ?><section class="admin-panel"><p class="flash flash-error"><?= e($journalError) ?></p></section><?php else: ?>
<section class="admin-panel accounting-filter-panel"><form class="accounting-filter" method="get">
  <label>Du<input type="date" name="start" value="<?= e($_GET['start'] ?? '') ?>"></label><label>Au<input type="date" name="end" value="<?= e($_GET['end'] ?? '') ?>"></label>
  <label>Compte<select name="account_id"><option value="">Tous</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['name']) ?></option><?php endforeach; ?></select></label>
  <label>Catégorie<select name="category_id"><option value="">Toutes</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $filters['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
  <label>Produit<select name="product_id"><option value="">Tous</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= $filters['product_id'] === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select></label>
  <label>Nature<select name="nature"><option value="">Toutes</option><option value="receipt" <?= $filters['nature'] === 'receipt' ? 'selected' : '' ?>>Encaissement</option><option value="disbursement" <?= $filters['nature'] === 'disbursement' ? 'selected' : '' ?>>Décaissement</option><option value="transfer" <?= $filters['nature'] === 'transfer' ? 'selected' : '' ?>>Transfert</option></select></label>
  <label>État<select name="status"><option value="">Tous</option><option value="confirmed" <?= $filters['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmé</option><option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Brouillon</option></select></label>
  <label class="wide">Recherche<input name="q" maxlength="120" value="<?= e($filters['q'] ?? '') ?>" placeholder="Référence, libellé, client…"></label>
  <button class="admin-button">Filtrer</button><a class="accounting-reset" href="<?= e(url('/admin/accounting-journal.php')) ?>">Réinitialiser</a>
</form></section>
<p class="accounting-result-count"><?= (int) $journal['total'] ?> écriture(s) trouvée(s)</p>
<section class="admin-panel accounting-table-panel"><div class="admin-table-wrap"><table class="admin-table accounting-table"><thead><tr><th>Date</th><th>Écriture</th><th>Type</th><th>Compte</th><th>Catégorie</th><th>Montant</th><th></th></tr></thead><tbody><?php foreach ($journal['rows'] as $operation): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($operation['effective_at']))) ?><small><?= e($operation['status']) ?></small></td><td><strong><?= e($operation['label']) ?></strong><small><?= e($operation['reference']) ?> · <?= e($operation['group_reference']) ?><?= $operation['order_ref'] ? ' · ' . e($operation['order_ref']) : '' ?></small></td><td><span class="status <?= e($operation['nature']) ?>"><?= e($operation['nature'] === 'receipt' ? 'Encaissement' : ($operation['nature'] === 'disbursement' ? 'Décaissement' : 'Transfert')) ?></span></td><td><?= e($operation['account_name']) ?><?php if ($operation['destination_account_name']): ?><small>→ <?= e($operation['destination_account_name']) ?></small><?php endif; ?></td><td><?= e($operation['category_name'] ?? 'Hors résultat') ?></td><td class="<?= $operation['nature'] === 'disbursement' ? 'amount-negative' : '' ?>"><strong><?= $operation['nature'] === 'disbursement' ? '−' : ($operation['nature'] === 'receipt' ? '+' : '↔') ?><?= money($operation['amount_fcfa']) ?></strong></td><td><a class="text-link" href="<?= e(url('/admin/accounting-operation.php?operation=' . (int) $operation['id'])) ?>">Détail</a></td></tr><?php endforeach; ?><?php if ($journal['rows'] === []): ?><tr><td colspan="7" class="admin-table-empty">Aucune écriture ne correspond à ces filtres.</td></tr><?php endif; ?></tbody></table></div>
<div class="journal-cards"><?php foreach ($journal['rows'] as $operation): ?><a class="journal-card" href="<?= e(url('/admin/accounting-operation.php?operation=' . (int) $operation['id'])) ?>"><div><span><?= e(date('d/m/Y H:i', strtotime($operation['effective_at']))) ?> · <?= e($operation['status']) ?></span><strong><?= e($operation['label']) ?></strong><small><?= e($operation['account_name']) ?> · <?= e($operation['group_reference']) ?></small></div><b class="<?= $operation['nature'] === 'disbursement' ? 'amount-negative' : '' ?>"><?= $operation['nature'] === 'disbursement' ? '−' : ($operation['nature'] === 'receipt' ? '+' : '↔') ?><?= money($operation['amount_fcfa']) ?></b></a><?php endforeach; ?></div>
</section>
<?php if ($journal['pages'] > 1): ?><nav class="accounting-pagination" aria-label="Pagination du Journal"><?php for ($page = max(1, $journal['page'] - 2); $page <= min($journal['pages'], $journal['page'] + 2); $page++): ?><a class="<?= $page === $journal['page'] ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page]))) ?>"><?= $page ?></a><?php endfor; ?></nav><?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
