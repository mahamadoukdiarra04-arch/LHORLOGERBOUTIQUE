<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();

try { ensure_accounting_schema(); } catch (Throwable $exception) { http_response_code(503); exit('La comptabilité ne peut pas être préparée pour le moment.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_account') {
            $account = accounting_create_account($pdo, $_POST, accounting_current_user_id());
            flash('success', 'Le compte « ' . $account['name'] . ' » a été ajouté.');
        } elseif ($action === 'update_account') {
            $account = accounting_update_account($pdo, accounting_integer($_POST['account_id'] ?? null, 'Le compte', 1), $_POST, accounting_current_user_id());
            flash('success', 'Le compte « ' . $account['name'] . ' » a été mis à jour.');
        } elseif ($action === 'update_category') {
            $category = accounting_update_category($pdo, accounting_integer($_POST['category_id'] ?? null, 'La catégorie', 1), $_POST, accounting_current_user_id());
            flash('success', 'La catégorie « ' . $category['name'] . ' » a été mise à jour.');
        } elseif ($action === 'reconciliation') {
            $result = accounting_with_transaction($pdo, function () use ($pdo): array {
                $reconciliation = accounting_create_reconciliation($pdo, $_POST, accounting_current_user_id());
                $upload = $_FILES['attachment'] ?? null;
                if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    accounting_store_attachment($pdo, $upload, null, (int) $reconciliation['id'], accounting_current_user_id());
                }
                return $reconciliation;
            });
            flash('success', 'Rapprochement enregistré. Écart constaté : ' . money((int) $result['difference_fcfa']) . '.');
        } else {
            throw new RuntimeException('Action de réglage invalide.');
        }
    } catch (Throwable $exception) {
        error_log('L’Horloger: réglage comptable échoué.');
        flash('error', accounting_safe_error_message($exception, 'Le réglage n’a pas pu être enregistré.'));
    }
    redirect('/admin/accounting-settings.php');
}

try {
    $accounts = accounting_account_balances($pdo, null, false);
    $categories = accounting_list_categories($pdo, false);
    $reconciliations = $pdo->query(
        'SELECT r.*, a.name AS account_name,
                (SELECT at.id FROM accounting_attachments at WHERE at.reconciliation_id = r.id ORDER BY at.id DESC LIMIT 1) AS attachment_id,
                (SELECT at.original_name FROM accounting_attachments at WHERE at.reconciliation_id = r.id ORDER BY at.id DESC LIMIT 1) AS attachment_name
         FROM accounting_reconciliations r INNER JOIN accounting_accounts a ON a.id = r.account_id ORDER BY r.reconciled_at DESC, r.id DESC LIMIT 20'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('L’Horloger: réglages comptables indisponibles.');
    $settingsError = accounting_safe_error_message($exception, 'Les réglages ne peuvent pas être préparés pour le moment.');
}
$adminPageTitle = 'Comptes & réglages';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head accounting-head"><div><p class="admin-kicker">Comptabilité · Réglages</p><h1>Des comptes qui correspondent au réel.</h1><p>Les comptes utilisés ne sont jamais supprimés. Leur type et leur solde d’ouverture deviennent verrouillés après la première opération.</p></div></header>
<nav class="accounting-tabs" aria-label="Navigation comptabilité"><a href="<?= e(url('/admin/accounting.php')) ?>">Vue d’ensemble</a><a href="<?= e(url('/admin/accounting-journal.php')) ?>">Journal</a><a href="<?= e(url('/admin/accounting-ted.php')) ?>">TED & rentabilité</a><a class="active" href="<?= e(url('/admin/accounting-settings.php')) ?>">Comptes & réglages</a></nav>
<?php if (isset($settingsError)): ?><section class="admin-panel"><p class="flash flash-error"><?= e($settingsError) ?></p></section><?php else: ?>
<section class="admin-grid accounting-settings-grid"><article class="admin-panel"><p class="admin-kicker">Nouveau compte réel</p><h2>Ajouter un compte.</h2><form class="accounting-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_account"><label>Code unique<input name="code" maxlength="40" pattern="[A-Za-z0-9_-]{2,40}" placeholder="Ex. OM-MKD" required></label><label>Nom du compte<input name="name" maxlength="120" placeholder="Ex. Orange Money MKD" required></label><label>Type<select name="account_type" required><option value="cash">Caisse</option><option value="bank">Banque</option><option value="mobile_money">Mobile Money</option></select></label><label>Solde d’ouverture FCFA<input type="number" name="opening_balance_fcfa" inputmode="numeric" value="0" required></label><label>Date d’ouverture<input type="datetime-local" name="opening_at" value="<?= e((new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d\TH:i')) ?>" required></label><label class="wide">Description (facultative)<textarea name="description" maxlength="500" rows="2"></textarea></label><button class="admin-button">Ajouter ce compte</button></form></article>
<article class="admin-panel"><p class="admin-kicker">Rapprochement</p><h2>Comparer avec un relevé.</h2><?php if ($accounts === []): ?><p class="admin-copy">Ajoutez d’abord un compte réel.</p><?php else: ?><form class="accounting-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="reconciliation"><label>Compte<select name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= money($account['balance_fcfa']) ?></option><?php endforeach; ?></select></label><label>Date du relevé<input type="datetime-local" name="reconciled_at" value="<?= e((new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d\TH:i')) ?>" required></label><label>Solde relevé FCFA<input type="number" name="statement_balance_fcfa" inputmode="numeric" required></label><label class="wide">Note (facultative)<textarea name="note" maxlength="1000" rows="2"></textarea></label><label class="wide">Pièce (PDF, JPEG, PNG ou WebP · 10 Mo)<input type="file" name="attachment" accept="application/pdf,image/jpeg,image/png,image/webp"></label><button class="admin-button">Enregistrer le rapprochement</button></form><?php endif; ?></article></section>

<section class="admin-panel accounting-section"><div class="admin-panel__head"><div><p class="admin-kicker">Comptes</p><h2>Soldes et statut.</h2></div><span class="accounting-note">Désactiver conserve tout l’historique.</span></div><div class="account-cards"><?php foreach ($accounts as $account): ?><article class="account-card <?= !(bool) $account['is_active'] ? 'is-disabled' : '' ?>"><div><span><?= e($account['account_type']) ?></span><strong><?= e($account['name']) ?></strong><small><?= e($account['code']) ?> · solde <?= money($account['balance_fcfa']) ?></small></div><form class="accounting-form compact" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_account"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><label>Nom<input name="name" maxlength="120" value="<?= e($account['name']) ?>" required></label><label>Description<input name="description" maxlength="500" value="<?= e($account['description'] ?? '') ?>"></label><input type="hidden" name="is_active" value="0"><label class="accounting-check"><input type="checkbox" name="is_active" value="1" <?= (bool) $account['is_active'] ? 'checked' : '' ?>><span>Compte actif</span></label><button class="admin-button">Mettre à jour</button></form></article><?php endforeach; ?><?php if ($accounts === []): ?><p class="admin-copy">Aucun compte réel n’est encore configuré.</p><?php endif; ?></div></section>

<section class="admin-panel accounting-section"><p class="admin-kicker">Catégories système</p><h2>Libellés et affichage.</h2><p class="admin-copy">Le code et le traitement comptable restent immuables : seul le libellé, l’ordre et l’état peuvent évoluer.</p><div class="category-list"><?php foreach ($categories as $category): ?><form class="category-card" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><div><strong><?= e($category['code']) ?></strong><small><?= e($category['treatment']) ?> · <?= e($category['default_scope']) ?></small></div><label>Libellé<input name="name" maxlength="120" value="<?= e($category['name']) ?>" required></label><label>Ordre<input type="number" name="sort_order" min="0" max="65535" value="<?= (int) $category['sort_order'] ?>" required></label><input type="hidden" name="is_active" value="0"><label class="accounting-check"><input type="checkbox" name="is_active" value="1" <?= (bool) $category['is_active'] ? 'checked' : '' ?>><span>Active</span></label><button class="admin-button">Enregistrer</button></form><?php endforeach; ?></div></section>

<section class="admin-panel accounting-section"><p class="admin-kicker">Derniers rapprochements</p><h2>Écarts constatés.</h2><div class="accounting-account-list"><?php foreach ($reconciliations as $reconciliation): ?><div class="accounting-account"><span><strong><?= e($reconciliation['account_name']) ?></strong><small><?= e(date('d/m/Y H:i', strtotime($reconciliation['reconciled_at']))) ?><?= $reconciliation['note'] ? ' · ' . e($reconciliation['note']) : '' ?><?php if ($reconciliation['attachment_id']): ?> · <a href="<?= e(url('/admin/accounting-download.php?attachment=' . (int) $reconciliation['attachment_id'])) ?>"><?= e($reconciliation['attachment_name']) ?></a><?php endif; ?></small></span><strong class="<?= (int) $reconciliation['difference_fcfa'] < 0 ? 'amount-negative' : '' ?>"><?= money((int) $reconciliation['difference_fcfa']) ?></strong></div><?php endforeach; ?><?php if ($reconciliations === []): ?><p class="admin-copy">Aucun rapprochement enregistré.</p><?php endif; ?></div></section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
