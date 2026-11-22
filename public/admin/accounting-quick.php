<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
$now = (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d\TH:i');
$values = $_POST;
$plan = null;
$result = null;
try {
    ensure_accounting_schema();
    ensure_closer_schema();
    $accounts = accounting_list_accounts($pdo, true);
    $_SESSION['quick_plans'] ??= [];
    foreach ($_SESSION['quick_plans'] as $key => $saved) {
        if (($saved['expires'] ?? 0) < time()) unset($_SESSION['quick_plans'][$key]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        try {
            if (($_POST['step'] ?? '') === 'confirm') {
                $token = accounting_uuid($_POST['plan_token'] ?? null);
                $saved = $_SESSION['quick_plans'][$token] ?? null;
                if (!$saved || $saved['user'] !== admin_identity()) throw new RuntimeException('Ce récapitulatif a expiré. Recommencez votre sélection.');
                $plan = $saved['plan'];
                $plan = accounting_quick_apply_dates($plan, $_POST['dates'] ?? []);
                $result = accounting_quick_execute($pdo, $plan, accounting_current_user_id());
                $_SESSION['quick_last_result'] = $result;
                // Keep the plan until expiry: a retry must return the recorded result.
                redirect('/admin/accounting-quick.php?done=' . rawurlencode($result['key']));
            } elseif (($_POST['step'] ?? '') === 'preview') {
                $plan = accounting_quick_prepare($pdo, $_POST);
                $token = accounting_new_uuid();
                $_SESSION['quick_plans'][$token] = ['plan' => $plan, 'user' => admin_identity(), 'expires' => time() + 7200];
                while (count($_SESSION['quick_plans']) > 5) array_shift($_SESSION['quick_plans']);
            }
        } catch (Throwable $exception) {
            error_log('L’Horloger : saisie rapide non confirmée.');
            $quickError = accounting_safe_error_message($exception, 'Le lot n’a pas pu être enregistré. Aucune modification du lot n’a été conservée. Réessayez.');
        }
    }
    if (isset($_GET['done'], $_SESSION['quick_last_result']) && hash_equals((string) $_SESSION['quick_last_result']['key'], (string) $_GET['done'])) {
        $result = $_SESSION['quick_last_result'];
    }
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 160);
    $state = (string) ($_GET['status'] ?? 'active');
    if (!in_array($state, ['active', 'À confirmer', 'Confirmée', 'En livraison', 'balance'], true)) $state = 'active';
    $start = (string) ($_GET['start'] ?? '');
    $end = (string) ($_GET['end'] ?? '');
    $where = [];
    $params = [];
    if ($state === 'balance') {
        $where[] = "o.status = 'Livrée' AND EXISTS (SELECT 1 FROM accounting_payment_exceptions x WHERE BINARY x.order_ref = BINARY o.order_ref AND x.status = 'open')";
    } elseif ($state === 'active') {
        $where[] = "o.status IN ('À confirmer', 'Confirmée', 'En livraison')";
    } else { $where[] = 'o.status = ?'; $params[] = $state; }
    // Date/search predicates select whole references, never just part of a basket.
    if ($q !== '') {
        $where[] = "EXISTS (SELECT 1 FROM orders s WHERE s.order_ref = o.order_ref AND CONCAT(s.order_ref,' ',s.customer_first_name,' ',s.customer_last_name,' ',s.phone,' ',s.district,' ',s.product_name,' ',s.variant) LIKE ?)";
        $params[] = '%' . $q . '%';
    }
    foreach (['start' => $start, 'end' => $end] as $kind => $date) {
        if ($date === '') continue;
        $valid = accounting_effective_at($date, 'La date du filtre');
        $where[] = 'o.created_at ' . ($kind === 'start' ? '>=' : '<=') . ' ?';
        $params[] = substr($valid, 0, 10) . ($kind === 'start' ? ' 00:00:00' : ' 23:59:59');
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(DISTINCT o.order_ref) FROM orders o WHERE ' . $whereSql);
    $count->execute($params);
    $totalRows = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($totalRows / 25));
    $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    $statement = $pdo->prepare('SELECT MIN(o.id) AS id, o.order_ref, MAX(o.created_at) AS ordered_at FROM orders o WHERE ' . $whereSql . ' GROUP BY o.order_ref ORDER BY ordered_at DESC, id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25));
    $statement->execute($params);
    $orders = [];
    foreach ($statement->fetchAll() as $order) {
        $order['lines'] = accounting_delivery_order_lines($pdo, $order['order_ref']);
        $order['amount_fcfa'] = $state === 'balance' ? accounting_quick_remaining($pdo, $order['order_ref'], $order['lines']) : accounting_delivery_order_total($order['lines']);
        $orders[] = $order;
    }
} catch (Throwable $exception) {
    error_log('L’Horloger : préparation saisie rapide indisponible.');
    $pageError = accounting_safe_error_message($exception, 'La saisie rapide ne peut pas être préparée pour le moment.');
}
$adminPageTitle = 'Saisie rapide';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head accounting-head"><div><p class="admin-kicker">Comptabilité · Saisie rapide</p><h1>Rattraper plusieurs commandes à la fois.</h1><p>Sélectionnez, vérifiez le récapitulatif, puis validez une seule fois.</p></div><a class="text-link" href="<?= e(url('/admin/orders.php')) ?>">Toutes les commandes</a></header>
<nav class="accounting-tabs" aria-label="Navigation comptabilité"><a href="<?= e(url('/admin/accounting.php')) ?>">Vue d’ensemble</a><a class="active" href="<?= e(url('/admin/accounting-quick.php')) ?>">Saisie rapide</a><a href="<?= e(url('/admin/accounting-action.php?action=disbursement')) ?>">Dépense</a><a href="<?= e(url('/admin/accounting-journal.php')) ?>">Journal</a><a href="<?= e(url('/admin/accounting-ted.php')) ?>">Rentabilité</a></nav>
<?php if (isset($quickError)): ?><p class="flash flash-error" role="alert"><?= e($quickError) ?></p><?php endif; ?>
<?php if (isset($pageError)): ?><p class="flash flash-error" role="alert"><?= e($pageError) ?></p>
<?php elseif ($result): ?>
<section class="admin-panel quick-success"><p class="admin-kicker">Lot enregistré</p><h2><?= (int) $result['count'] ?> commande(s) traitée(s).</h2><p><?= $result['total_fcfa'] > 0 ? money($result['total_fcfa']) . ' encaissés et inscrits au journal.' : 'Les statuts sont à jour, y compris dans le suivi closeuse.' ?></p><p>Aucun double encaissement ni double retrait de stock en cas de nouvel envoi du même lot.</p><div class="quick-links"><a class="admin-button" href="<?= e(url('/admin/accounting-quick.php')) ?>">Traiter d’autres commandes</a><a class="text-link" href="<?= e(url('/admin/accounting-journal.php')) ?>">Consulter le journal</a></div><details><summary>Voir les commandes traitées</summary><ul><?php foreach ($result['orders'] as $entry): ?><li><?= e($entry['order_ref']) ?><?= $entry['amount_fcfa'] ? ' · ' . money($entry['amount_fcfa']) : '' ?><?= $entry['group_reference'] ? ' · ' . e($entry['group_reference']) : '' ?></li><?php endforeach; ?></ul></details></section>
<?php elseif ($plan): $financial = in_array($plan['action'], ['deliver', 'collect'], true); ?>
<section class="admin-panel quick-review"><p class="admin-kicker">Dernière vérification</p><h2><?= e(accounting_quick_actions()[$plan['action']]) ?>.</h2><p><?= count($plan['entries']) ?> commande(s), avec toutes leurs lignes. <?= $financial ? 'Le stock et le journal seront mis à jour ensemble. Corrigez les dates si nécessaire.' : 'Cette action ne crée aucun encaissement et ne retire pas de stock.' ?></p>
<?php if ($financial): ?><div class="quick-review-total"><span>Total à encaisser</span><strong><?= money($plan['total_fcfa']) ?></strong><span>Sur <?= e(array_column($accounts, 'name', 'id')[$plan['account_id']] ?? 'Compte sélectionné') ?></span></div><?php endif; ?>
<form method="post" class="quick-review-form" data-quick-confirm><?= csrf_field() ?><input type="hidden" name="step" value="confirm"><input type="hidden" name="plan_token" value="<?= e($token) ?>">
<div class="quick-review-list"><?php foreach ($plan['entries'] as $entry): ?><article class="quick-review-order"><div><strong><?= e($entry['order_ref']) ?></strong><span><?= e(trim($entry['lines'][0]['customer_first_name'] . ' ' . $entry['lines'][0]['customer_last_name'])) ?></span><ul><?php foreach ($entry['lines'] as $line): ?><li><?= (int) $line['quantity'] ?> × <?= e($line['product_name']) ?> · <?= e($line['variant']) ?></li><?php endforeach; ?></ul></div><div><?php if ($financial): ?><strong><?= money($entry['amount_fcfa']) ?></strong><label>Date réelle <?= $plan['action'] === 'collect' ? 'd’encaissement' : 'de livraison et encaissement' ?><input type="datetime-local" name="dates[<?= (int) $entry['order_id'] ?>]" value="<?= e(str_replace(' ', 'T', substr($entry['effective_at'], 0, 16))) ?>" required></label><?php else: ?><span><?= e(accounting_quick_actions()[$plan['action']]) ?></span><?php endif; ?></div></article><?php endforeach; ?></div>
<p class="quick-explanation"><?= $financial ? 'À valider uniquement si ces montants ont réellement été reçus. Les paiements partiels ou répartis sur plusieurs comptes restent disponibles dans le détail individuel.' : 'Les commandes déjà annulées ou livrées sont protégées. Une modification intervenue depuis ce récapitulatif bloquera tout le lot.' ?></p>
<div class="quick-links"><button class="admin-button" type="submit"><?= $financial ? 'Confirmer ' . money($plan['total_fcfa']) . ' encaissés' : 'Confirmer pour ' . count($plan['entries']) . ' commande(s)' ?></button><a class="text-link" href="<?= e(url('/admin/accounting-quick.php')) ?>">Revenir à la sélection</a></div><p data-quick-submit-status role="status"></p></form></section>
<?php else: ?>
<form class="accounting-filter admin-panel quick-filter" method="get"><label>Rechercher<input name="q" value="<?= e($q) ?>" placeholder="Client, référence, téléphone, produit…"></label><label>Commandes<select name="status"><option value="active" <?= $state === 'active' ? 'selected' : '' ?>>Toutes à traiter</option><?php foreach (['À confirmer', 'Confirmée', 'En livraison'] as $option): ?><option <?= $state === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?><option value="balance" <?= $state === 'balance' ? 'selected' : '' ?>>Livrées avec solde à encaisser</option></select></label><label>Commandées du<input type="date" name="start" value="<?= e($start) ?>"></label><label>Au<input type="date" name="end" value="<?= e($end) ?>"></label><button class="admin-button">Filtrer</button></form>
<form method="post" class="quick-selection" data-quick-selection><?= csrf_field() ?><input type="hidden" name="step" value="preview">
<section class="admin-panel quick-toolbar"><div class="quick-toolbar-fields"><label>Action<select name="batch_action" data-quick-action><?php foreach (accounting_quick_actions() as $key => $label): ?><option value="<?= e($key) ?>" <?= ($values['batch_action'] ?? ($state === 'balance' ? 'collect' : 'deliver')) === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label data-quick-financial>Compte de réception<select name="account_id"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) ($values['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['name']) ?></option><?php endforeach; ?></select></label><label data-quick-financial>Date réelle<input type="datetime-local" name="effective_at" value="<?= e($values['effective_at'] ?? $now) ?>"></label><label data-quick-channel>Canal si non renseigné<select name="channel"><option value="">Garder les canaux existants</option><?php foreach (['Meta', 'Réachat'] as $channel): ?><option <?= ($values['channel'] ?? '') === $channel ? 'selected' : '' ?>><?= e($channel) ?></option><?php endforeach; ?></select></label></div><p class="quick-explanation">Le montant est repris automatiquement depuis chaque commande. Les canaux déjà renseignés sont conservés. Une date différente peut être précisée par commande au récapitulatif.</p></section>
<div class="quick-selection-head"><label class="quick-select-all"><input type="checkbox" data-quick-all><span>Sélectionner les <?= count($orders) ?> commandes de cette page</span></label><span><?= $totalRows ?> commande(s) · page <?= $page ?> / <?= $pages ?></span></div>
<div class="quick-order-list"><?php foreach ($orders as $order): $first = $order['lines'][0]; ?><label class="quick-order-card"><input type="checkbox" name="order_ids[]" value="<?= (int) $order['id'] ?>" data-quick-order data-amount="<?= (int) $order['amount_fcfa'] ?>" <?= in_array((string) $order['id'], array_map('strval', (array) ($values['order_ids'] ?? [])), true) ? 'checked' : '' ?>><span class="quick-order-main"><strong><?= e(trim($first['customer_first_name'] . ' ' . $first['customer_last_name'])) ?></strong><span><?= e($first['phone']) ?> · <?= e($first['district']) ?></span><span class="quick-order-reference"><?= e($order['order_ref']) ?> · <?= e(date('d/m/Y H:i', strtotime($order['ordered_at']))) ?></span><span class="quick-order-products"><?php foreach ($order['lines'] as $line): ?><span><?= (int) $line['quantity'] ?> × <?= e($line['product_name']) ?> · <?= e($line['variant']) ?></span><?php endforeach; ?></span></span><span class="quick-order-side"><strong><?= money($order['amount_fcfa']) ?></strong><span class="status"><?= e($first['status']) ?></span><small><?= e($first['acquisition_channel'] ?: 'Canal à renseigner') ?></small></span></label><?php endforeach; ?><?php if (!$orders): ?><section class="admin-panel"><p>Aucune commande à traiter pour ces filtres.</p></section><?php endif; ?></div>
<div class="quick-bottom-bar"><div aria-live="polite"><strong data-quick-count>0 commande sélectionnée</strong><span data-quick-total>0 FCFA</span></div><button class="admin-button" data-quick-preview <?= !$orders ? 'disabled' : '' ?>>Vérifier la sélection</button></div>
<p class="quick-explanation">La sélection concerne cette page uniquement. Validez ce lot avant de changer de page ou de filtre.</p></form>
<?php if ($pages > 1): ?><nav class="accounting-pagination quick-pagination" aria-label="Pages de la saisie rapide"><?php foreach (array_unique([1, max(1, $page - 1), $page, min($pages, $page + 1), $pages]) as $number): ?><a class="<?= $number === $page ? 'active' : '' ?>" <?= $number === $page ? 'aria-current="page"' : '' ?> href="?<?= e(http_build_query(['q' => $q, 'status' => $state, 'start' => $start, 'end' => $end, 'page' => $number])) ?>"><?= $number ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php endif; ?>
<script src="<?= e(url('/assets/js/accounting-quick.js?v=20261122')) ?>" defer></script>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
