<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();

$pdo = db();
$metaReady = false;
try {
    ensure_meta_capi_schema($pdo);
    $metaReady = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!meta_capi_is_configured()) {
            throw new RuntimeException('Ajoutez d’abord le jeton Conversions API privé dans app/config.php sur Hostinger.');
        }
        $sent = meta_capi_dispatch_pending($pdo, 50);
        flash('success', $sent . ' signal(s) Meta envoyé(s). Les autres restent en file si Meta ne les a pas acceptés.');
        redirect('/admin/meta-capi.php');
    }
} catch (Throwable $exception) {
    error_log('L’Horloger: espace Signaux Meta indisponible.');
    flash('error', $exception->getMessage() ?: 'Les signaux Meta ne peuvent pas être consultés pour le moment.');
}

$summary = $metaReady ? $pdo->query("SELECT event_key, event_name, status, COUNT(*) AS total, MAX(COALESCE(delivered_at, last_attempt_at, created_at)) AS latest_at FROM meta_capi_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY event_key, event_name, status ORDER BY event_key, status")->fetchAll() : [];
$recent = $metaReady ? $pdo->query('SELECT * FROM meta_capi_events ORDER BY id DESC LIMIT 20')->fetchAll() : [];
$adminPageTitle = 'Signaux Meta';
require APP_ROOT . '/templates/admin-header.php';
?>
<section class="admin-hero"><p class="admin-kicker">Optimisation campagnes</p><h1>Signaux Meta.</h1><p>Une demande est un <strong>Lead</strong>, une commande validée devient <strong>ConfirmedOrder</strong>, et <strong>Purchase</strong> est réservé à une livraison réellement encaissée.</p></section>
<section class="admin-panel"><div class="admin-panel__head"><div><p class="admin-kicker">Conversions API</p><h2><?= meta_capi_is_configured() ? 'Connectée et prête à envoyer.' : 'Jeton serveur à ajouter.' ?></h2></div><?php if (meta_capi_is_configured()): ?><form method="post"><?= csrf_field() ?><button class="admin-button" type="submit">Envoyer les signaux en attente</button></form><?php endif; ?></div><p class="admin-copy"><?php if (!meta_capi_is_configured()): ?>Dans le fichier privé <code>app/config.php</code> de Hostinger, ajoutez <code>meta_capi.access_token</code>. Ce jeton ne doit jamais être copié dans Git ni dans le navigateur.<?php else: ?>Les erreurs Meta restent visibles ci-dessous et peuvent être relancées ici, sans bloquer les commandes du site.<?php endif; ?></p></section>
<section class="metric-grid"><?php foreach ($summary as $row): ?><article class="metric"><p><?= e($row['event_name']) ?> · <?= e($row['status']) ?></p><strong><?= (int) $row['total'] ?></strong><span><?= $row['latest_at'] ? 'Dernier : ' . e(date('d/m/Y H:i', strtotime($row['latest_at']))) : 'Aucun envoi' ?></span></article><?php endforeach; ?><?php if ($summary === []): ?><article class="metric"><p>Activité</p><strong>0</strong><span>Les nouveaux signaux apparaîtront ici.</span></article><?php endif; ?></section>
<section class="admin-panel"><div class="admin-panel__head"><div><p class="admin-kicker">File d’envoi</p><h2>Derniers signaux.</h2></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Commande</th><th>Évènement</th><th>État</th><th>Dernier essai</th><th>Diagnostic</th></tr></thead><tbody><?php foreach ($recent as $event): ?><tr><td><strong><?= e($event['order_ref']) ?></strong></td><td><?= e($event['event_name']) ?></td><td><span class="status <?= $event['status'] === 'sent' ? 'delivered' : '' ?>"><?= e($event['status']) ?></span></td><td><?= $event['last_attempt_at'] ? e(date('d/m/Y H:i', strtotime($event['last_attempt_at']))) : '—' ?></td><td><?= e($event['last_error'] ?: ($event['last_http_status'] ? 'HTTP ' . $event['last_http_status'] : 'En attente')) ?></td></tr><?php endforeach; ?><?php if ($recent === []): ?><tr><td colspan="5" class="admin-table-empty">Aucun signal enregistré.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
