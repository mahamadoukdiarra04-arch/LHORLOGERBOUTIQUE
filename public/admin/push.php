<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
header('Cache-Control: no-store');
$adminPageTitle = 'Notifications closeuse';
$pushError = null; $summary = [];
try {
    require_once APP_ROOT . '/push.php';
    push_schema(db()); push_keys(db());
    try { $recoveryCheck = count(push_missing_order_refs(db())) . ' commande(s) à récupérer.'; }
    catch (Throwable $error) { $recoveryCheck = push_safe_diagnostic(get_class($error) . ': ' . $error->getMessage()); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $jobId = (int) ($_POST['test_job_id'] ?? 0);
        $testJob = db()->prepare("SELECT id FROM web_push_jobs WHERE id = ? AND event_key LIKE 'test-%' AND state = 'pending'");
        $testJob->execute([$jobId]);
        if (!$testJob->fetchColumn()) throw new RuntimeException('Ce test est déjà envoyé ou indisponible.');
        db()->prepare('UPDATE web_push_jobs SET available_at = NOW() WHERE id = ?')->execute([$jobId]);
        try { $count = push_dispatch(db(), 1, $jobId); flash('success', $count . ' test accepté par le service push.'); }
        catch (Throwable) { flash('error', 'Le test a échoué : voir le diagnostic privé.'); }
        redirect('/admin/push.php');
    }
    $diagnostics = db()->query('SELECT job_id, http_status, diagnostic, created_at FROM web_push_diagnostics ORDER BY id DESC LIMIT 10')->fetchAll();
    $jobs = db()->query("SELECT id, event_key, state, attempts, created_at FROM web_push_jobs ORDER BY id DESC LIMIT 10")->fetchAll();
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
<h2>Derniers essais</h2>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Envoi</th><th>État</th><th>Essais</th><th></th></tr></thead><tbody>
<?php foreach ($jobs as $job): ?><tr><td>PUSH-<?= (int) $job['id'] ?></td><td><?= e($job['state']) ?></td><td><?= (int) $job['attempts'] ?></td><td><?php if (str_starts_with($job['event_key'], 'test-') && $job['state'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="test_job_id" value="<?= (int) $job['id'] ?>"><button class="admin-button">Relancer ce test</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2>Diagnostic privé</h2><ul><?php foreach ($diagnostics as $diagnostic): ?><li style="overflow-wrap:anywhere"><?= e($diagnostic['created_at']) ?> · PUSH-<?= (int) $diagnostic['job_id'] ?> · HTTP <?= e((string) $diagnostic['http_status']) ?> · <?= e($diagnostic['diagnostic']) ?></li><?php endforeach; ?></ul>
<p style="overflow-wrap:anywhere">Contrôle du chemin automatique : <?= e($recoveryCheck) ?></p>
<h2>Reprises automatiques</h2><p>Dans Hostinger, programmer toutes les minutes la commande suivante (PHP 8.2+) :</p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e('/usr/bin/php ' . APP_ROOT . '/push-worker.php') ?></pre>
<p>Sans cette tâche cron, les alertes partent à chaque commande mais les reprises après échec attendent une visite ou une nouvelle commande.</p>
<?php endif; ?>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
