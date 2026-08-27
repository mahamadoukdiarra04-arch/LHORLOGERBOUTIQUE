<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_manager();

/**
 * This protected maintenance page is intentionally not linked in the navigation.
 * It removes only old test orders that have never affected stock or accounting.
 */
function cleanup_placeholders(int $count): string {
    return implode(',', array_fill(0, $count, '?'));
}

function cleanup_table_exists(PDO $pdo, string $table): bool {
    $statement = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $statement->execute([$table]);
    return (bool) $statement->fetchColumn();
}

/** @return array<int,array{order_ref:string,line_count:int,created_at:string,last_created_at:string,ids:array<int,int>}> */
function cleanup_test_order_targets(PDO $pdo, string $cutoff): array {
    $statement = $pdo->prepare(
        'SELECT order_ref, COUNT(*) AS line_count, MIN(created_at) AS created_at, MAX(created_at) AS last_created_at,
                GROUP_CONCAT(id ORDER BY id SEPARATOR ",") AS order_ids
         FROM orders
         GROUP BY order_ref
         HAVING MAX(created_at) <= ?
         ORDER BY MAX(created_at) ASC, order_ref ASC'
    );
    $statement->execute([$cutoff]);

    $targets = [];
    foreach ($statement->fetchAll() as $row) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $row['order_ids']))));
        if (!$ids) continue;
        $targets[] = [
            'order_ref' => (string) $row['order_ref'],
            'line_count' => (int) $row['line_count'],
            'created_at' => (string) $row['created_at'],
            'last_created_at' => (string) $row['last_created_at'],
            'ids' => $ids,
        ];
    }
    return $targets;
}

/** @param array<int,int> $orderIds */
function cleanup_count_by_order_id(PDO $pdo, string $table, array $orderIds): int {
    if (!$orderIds || !cleanup_table_exists($pdo, $table)) return 0;
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `' . $table . '` WHERE order_id IN (' . cleanup_placeholders(count($orderIds)) . ')'
    );
    $statement->execute($orderIds);
    return (int) $statement->fetchColumn();
}

/** @param array<int,string> $orderRefs */
function cleanup_count_by_order_ref(PDO $pdo, string $table, array $orderRefs): int {
    if (!$orderRefs || !cleanup_table_exists($pdo, $table)) return 0;
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `' . $table . '` WHERE order_ref IN (' . cleanup_placeholders(count($orderRefs)) . ')'
    );
    $statement->execute($orderRefs);
    return (int) $statement->fetchColumn();
}

/** @param array<int,array{order_ref:string,line_count:int,created_at:string,last_created_at:string,ids:array<int,int>}> $targets */
function cleanup_dependency_summary(PDO $pdo, array $targets): array {
    $orderIds = [];
    foreach ($targets as $target) $orderIds = array_merge($orderIds, $target['ids']);
    $orderRefs = array_map(static fn(array $target): string => $target['order_ref'], $targets);
    return [
        'closer_tracking' => cleanup_count_by_order_id($pdo, 'order_closer_tracking', $orderIds),
        'closer_events' => cleanup_count_by_order_id($pdo, 'closer_events', $orderIds),
        'admin_events' => cleanup_count_by_order_id($pdo, 'admin_events', $orderIds),
        'stock_movements' => cleanup_count_by_order_id($pdo, 'stock_movements', $orderIds),
        'accounting_allocations' => cleanup_count_by_order_id($pdo, 'accounting_allocations', $orderIds),
        'accounting_groups' => cleanup_count_by_order_ref($pdo, 'accounting_operation_groups', $orderRefs),
        'payment_exceptions' => cleanup_count_by_order_ref($pdo, 'accounting_payment_exceptions', $orderRefs),
    ];
}

/** @param array<int,int> $orderIds */
function cleanup_delete_by_order_id(PDO $pdo, string $table, array $orderIds): void {
    if (!$orderIds || !cleanup_table_exists($pdo, $table)) return;
    $statement = $pdo->prepare('DELETE FROM `' . $table . '` WHERE order_id IN (' . cleanup_placeholders(count($orderIds)) . ')');
    $statement->execute($orderIds);
}

$pdo = db();
$cutoff = gmdate('Y-m-d H:i:s', time() - (11 * 24 * 60 * 60));
$targets = cleanup_test_order_targets($pdo, $cutoff);
$orderIds = $targets ? array_merge(...array_map(static fn(array $target): array => $target['ids'], $targets)) : [];
$dependencies = cleanup_dependency_summary($pdo, $targets);
$blockedBy = array_filter([
    'mouvements de stock' => $dependencies['stock_movements'],
    'écritures comptables détaillées' => $dependencies['accounting_allocations'],
    'groupes d’opérations comptables' => $dependencies['accounting_groups'],
    'exceptions de paiement' => $dependencies['payment_exceptions'],
]);
$targetCount = count($targets);
$lineCount = count($orderIds);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $confirmation = trim((string) ($_POST['confirmation'] ?? ''));
    $expectedConfirmation = 'SUPPRIMER ' . $targetCount;

    if (!$targets) {
        flash('error', 'Aucune commande de test ne correspond encore au seuil sélectionné.');
    } elseif ($blockedBy) {
        flash('error', 'Suppression bloquée : des données de stock ou de comptabilité sont liées à ces commandes.');
    } elseif (!hash_equals($expectedConfirmation, $confirmation)) {
        flash('error', 'Saisissez exactement « ' . $expectedConfirmation . ' » pour confirmer.');
    } else {
        try {
            $pdo->beginTransaction();
            cleanup_delete_by_order_id($pdo, 'closer_events', $orderIds);
            cleanup_delete_by_order_id($pdo, 'order_closer_tracking', $orderIds);
            cleanup_delete_by_order_id($pdo, 'admin_events', $orderIds);
            $deleteOrders = $pdo->prepare('DELETE FROM orders WHERE id IN (' . cleanup_placeholders(count($orderIds)) . ')');
            $deleteOrders->execute($orderIds);
            $pdo->commit();
            log_event('maintenance', $targetCount . ' commande(s) de test supprimée(s) après contrôle des dépendances.');
            flash('success', $targetCount . ' commande(s) de test et leurs traces de suivi ont été supprimées.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('L’Horloger: purge contrôlée des commandes de test échouée.');
            flash('error', 'La suppression n’a pas abouti. Aucune commande n’a été supprimée.');
        }
    }
    redirect('/admin/order-cleanup.php');
}

$adminPageTitle = 'Nettoyage des commandes test';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Maintenance protégée</p>
    <h1>Nettoyer les commandes de test.</h1>
    <p>Seules les références dont la dernière ligne a été créée avant le <?= e(date('d/m/Y H:i', strtotime($cutoff))) ?> sont ciblées. Les commandes ayant touché le stock ou la comptabilité restent protégées.</p>
  </div>
  <div class="metric"><p>Références ciblées</p><strong><?= $targetCount ?></strong></div>
</header>

<?php if ($message = flash('success')): ?><p class="flash flash-success"><?= e($message) ?></p><?php endif; ?>
<?php if ($message = flash('error')): ?><p class="flash flash-error"><?= e($message) ?></p><?php endif; ?>

<section class="admin-panel">
  <div class="admin-panel__head"><div><p class="admin-kicker">Contrôle avant suppression</p><h2><?= $lineCount ?> ligne(s) répartie(s) sur <?= $targetCount ?> référence(s).</h2></div></div>
  <div class="order-detail-grid">
    <div class="fact"><span>Suivi closeuse</span><b><?= (int) $dependencies['closer_tracking'] ?></b></div>
    <div class="fact"><span>Historique closeuse</span><b><?= (int) $dependencies['closer_events'] ?></b></div>
    <div class="fact"><span>Évènements admin</span><b><?= (int) $dependencies['admin_events'] ?></b></div>
    <div class="fact"><span>Stock ou comptabilité</span><b><?= array_sum($blockedBy) ?></b></div>
  </div>

  <?php if ($targets): ?>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Référence</th><th>Créée le</th><th>Lignes</th></tr></thead><tbody>
      <?php foreach ($targets as $target): ?><tr><td><strong><?= e($target['order_ref']) ?></strong></td><td><?= e(date('d/m/Y H:i', strtotime($target['last_created_at']))) ?></td><td><?= (int) $target['line_count'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

  <?php if ($blockedBy): ?>
    <p class="flash flash-error">Suppression verrouillée : <?= e(implode(', ', array_keys($blockedBy))) ?> sont liées à ces références. Elles ne seront pas touchées.</p>
  <?php elseif ($targets): ?>
    <form class="inline-form" method="post">
      <?= csrf_field() ?>
      <label>Confirmation définitive<input name="confirmation" autocomplete="off" placeholder="<?= e('SUPPRIMER ' . $targetCount) ?>" required></label>
      <button class="admin-button">Supprimer les <?= $targetCount ?> commandes</button>
    </form>
  <?php else: ?>
    <p class="admin-copy">Aucune commande n’atteint encore le seuil de 11 jours.</p>
  <?php endif; ?>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
