<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
header('Cache-Control: no-store');
$pdo = db(); ensure_accounting_schema(); ensure_closer_schema(); ensure_meta_capi_schema($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!meta_capi_is_configured()) throw new RuntimeException('Conversions API non configurée.');
        if (meta_capi_config()['test_event_code'] !== '') throw new RuntimeException('Mode test Meta actif : aucun achat réel envoyé.');
        $corrected = meta_capi_repair_unsent_dates($pdo);
        $rows = meta_capi_purchase_audit($pdo); $queued = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'sent') continue;
            meta_capi_queue_order_event($pdo, $row['order_ref'], 'purchase', $row['value_fcfa'], false);
            $queued++;
        }
        $sent = meta_capi_dispatch_pending($pdo, 50, 'purchase');
        log_event('meta_recovery', "$corrected signaux vérifiés/corrigés ; $queued achats récents à transmettre ; $sent acceptés par Meta.");
        flash('success', "$corrected signaux vérifiés/corrigés. $queued achats récents non transmis. $sent Purchase acceptés par Meta. Objectif de campagne inchangé.");
    } catch (Throwable $error) { flash('error', $error->getMessage()); }
    redirect('/admin/meta-recovery.php');
}
$auditError = null;
try { $rows = meta_capi_purchase_audit($pdo); }
catch (Throwable $error) { $rows = []; $auditError = $error->getMessage(); }
$states = $pdo->query('SELECT event_name, status, COUNT(*) AS total FROM meta_capi_events GROUP BY event_name, status ORDER BY event_name, status')->fetchAll();
$adminPageTitle = 'Contrôle des dates et achats Meta';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head"><div><p class="admin-kicker">Meta · Contrôle</p><h1>Achats réels des sept derniers jours</h1></div><a class="text-link" href="/admin/meta-capi.php">Signaux Meta</a></header>
<section class="admin-panel">
<?php if ($auditError): ?><p class="flash flash-error"><?= e($auditError) ?></p><?php endif; ?>
<p>Seules les commandes entièrement livrées et encaissées, sans remboursement actif, sont éligibles. La date est le plus tardif entre la livraison et l’encaissement intégral réel. Les encaissements contrepassés sont exclus ; leurs remplacements datés sont pris en compte.</p>
<p>Les signaux déjà acceptés ne sont ni modifiés ni renvoyés. Les signaux non transmis sans preuve ou hors délai sont bloqués. Aucun statut, stock ou compte comptable ne change. ConfirmedOrder reste l’objectif de campagne.</p>
<form method="post"><?= csrf_field() ?><button class="admin-button" type="submit">Corriger les dates et envoyer les achats récents manquants</button></form>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Commande</th><th>Date réelle UTC</th><th>Encaissé</th><th>Transmission</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= e($row['order_ref']) ?></td><td><?= e($row['occurred_at']) ?></td><td><?= money($row['value_fcfa']) ?></td><td><?= e($row['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4">Aucun achat récent entièrement livré et encaissé vérifiable.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="admin-panel"><h2>État des signaux</h2><ul><?php foreach ($states as $row): ?><li><?= e($row['event_name']) ?> · <?= e($row['status']) ?> : <?= (int) $row['total'] ?></li><?php endforeach; ?></ul></section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
