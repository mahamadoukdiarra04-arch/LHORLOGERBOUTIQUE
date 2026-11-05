<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
header('Cache-Control: no-store');
$adminPageTitle = 'Notifications closeuse';
$pushError = null; $summary = [];
try {
    require_once APP_ROOT . '/push.php';
    push_schema(db()); push_keys(db());
    $summary = db()->query("SELECT state, COUNT(*) AS total FROM web_push_jobs GROUP BY state")->fetchAll();
    $devices = (int) db()->query("SELECT COUNT(*) FROM web_push_subscriptions s JOIN admin_users u ON u.id = s.user_id WHERE s.active = 1 AND u.is_active = 1 AND u.role = 'closer'")->fetchColumn();
} catch (Throwable) { $pushError = 'Le service push ne peut pas être préparé. Vérifiez PHP 8.2+, curl, openssl et mbstring.'; }
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head"><h1>Notifications closeuse</h1></header>
<section class="admin-panel">
<?php if ($pushError): ?><p class="flash flash-error"><?= e($pushError) ?></p><?php else: ?>
<h2>Service prêt côté serveur</h2><p><?= $devices ?> appareil(s) closeuse actif(s).</p>
<p>La closeuse doit ouvrir son espace depuis l’écran d’accueil de son iPhone, activer les notifications puis toucher « Tester ».</p>
<ul><?php foreach ($summary as $row): ?><li><?= e($row['state']) ?> : <?= (int) $row['total'] ?></li><?php endforeach; ?></ul>
<h2>Reprises automatiques</h2><p>Dans Hostinger, programmer toutes les minutes la commande suivante (PHP 8.2+) :</p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e('/usr/bin/php ' . APP_ROOT . '/push-worker.php') ?></pre>
<p>Sans cette tâche cron, les alertes partent à chaque commande mais les reprises après échec attendent une visite ou une nouvelle commande.</p>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
