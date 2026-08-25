<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
try {
    ensure_accounting_schema();
} catch (Throwable $exception) {
    error_log('L’Horloger: initialisation du stock comptable échouée.');
    http_response_code(503);
    exit('Le stock comptable ne peut pas être préparé pour le moment. Réessayez dans quelques instants.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);
    $productStatement = $pdo->prepare('SELECT name FROM products WHERE id = ?');
    $productStatement->execute([$productId]);
    $productName = $productStatement->fetchColumn();

    try {
        if (!$productName) throw new RuntimeException('Produit invalide.');

        if ($action === 'movement') {
            $type = (string) ($_POST['movement_type'] ?? '');
            try {
                $pdo->beginTransaction();
                $movement = accounting_stock_record_movement($pdo, [
                    'product_id' => $productId,
                    'movement_type' => $type,
                    'quantity' => $_POST['quantity'] ?? null,
                    'purchase_price_fcfa' => $_POST['purchase_price'] ?? null,
                    'transit_price_fcfa' => $_POST['transit_price'] ?? null,
                    'note' => $_POST['note'] ?? null,
                    'actor' => admin_identity(),
                ]);
                log_event('stock', $type . ' · ' . $productName . ' · ' . abs((int) $movement['quantity']) . ' unité(s)', $productId);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            flash('success', 'Mouvement de stock enregistré.');
        } elseif ($action === 'ads') {
            $start = (string) ($_POST['start_date'] ?? '');
            $end = (string) ($_POST['end_date'] ?? '');
            $amount = (int) ($_POST['amount'] ?? 0);

            if (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
                || $start > $end
                || $amount < 1
            ) {
                throw new RuntimeException('Renseignez une période et un coût publicitaire valides.');
            }

            $insert = $pdo->prepare(
                "INSERT INTO ad_costs(product_id, channel, start_date, end_date, amount_fcfa, actor)
                 VALUES (?, 'Meta', ?, ?, ?, ?)"
            );
            $insert->execute([$productId, $start, $end, $amount, admin_identity()]);
            log_event('publicité', 'Coût Meta · ' . $productName . ' · ' . money($amount), $productId);
            flash('success', 'Coût publicitaire enregistré.');
        } elseif ($action === 'sale_price') {
            $salePrice = (int) ($_POST['sale_price'] ?? 0);
            if ($salePrice < 1) {
                throw new RuntimeException('Indiquez un prix de vente valide.');
            }

            $update = $pdo->prepare('UPDATE products SET price_fcfa = ? WHERE id = ?');
            $update->execute([$salePrice, $productId]);
            log_event('prix', 'Prix de vente · ' . $productName . ' · ' . money($salePrice), $productId);
            flash('success', 'Prix de vente mis à jour.');
        }
    } catch (Throwable $exception) {
        error_log('L’Horloger: mise à jour de stock échouée.');
        flash('error', accounting_safe_error_message($exception, 'Le mouvement de stock n’a pas pu être enregistré. Réessayez dans quelques instants.'));
    }

    redirect('/admin/stock.php?product=' . $productId);
}

$stockQuery = "
    SELECT
        p.id,
        p.name,
        p.slug,
        p.price_fcfa,
        COALESCE(SUM(sm.quantity), 0) AS quantity,
        (
            SELECT
                SUM(s2.purchase_price_fcfa + COALESCE(s2.transit_price_fcfa, 0))
                / NULLIF(SUM(s2.quantity), 0)
            FROM stock_movements s2
            WHERE s2.product_id = p.id
              AND s2.movement_type = 'Réassort'
              AND s2.purchase_price_fcfa IS NOT NULL
        ) AS unit_cost
    FROM products p
    LEFT JOIN stock_movements sm ON sm.product_id = p.id
    GROUP BY p.id
    ORDER BY p.id
";
$stock = $pdo->query($stockQuery)->fetchAll();
$selected = (int) ($_GET['product'] ?? ($stock[0]['id'] ?? 0));

$eventsStatement = $pdo->prepare(
    'SELECT sm.*, p.name
     FROM stock_movements sm
     JOIN products p ON p.id = sm.product_id
     WHERE sm.product_id = ?
     ORDER BY sm.created_at DESC
     LIMIT 100'
);
$eventsStatement->execute([$selected]);
$events = $eventsStatement->fetchAll();

$adsStatement = $pdo->prepare('SELECT * FROM ad_costs WHERE product_id = ? ORDER BY start_date DESC');
$adsStatement->execute([$selected]);
$ads = $adsStatement->fetchAll();

$selectedItem = current(array_filter($stock, fn(array $item): bool => (int) $item['id'] === $selected))
    ?: ($stock[0] ?? null);

$adminPageTitle = 'Stock & coûts';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Stock & réassort</p>
    <h1>La rentabilité commence ici.</h1>
    <p>Un réassort renseigne la quantité, le prix d’achat et le transit. Le coût unitaire est calculé automatiquement.</p>
  </div>
</header>

<section class="stock-grid">
  <?php foreach ($stock as $item): ?>
    <?php $low = (int) $item['quantity'] <= 6; ?>
    <?php $unitCost = $item['unit_cost'] !== null ? (float) $item['unit_cost'] : null; ?>
    <a class="stock-card <?= $low ? 'low' : '' ?>" href="?product=<?= (int) $item['id'] ?>">
      <p><?= e($item['name']) ?></p>
      <div class="quantity"><?= (int) $item['quantity'] ?></div>
      <p>unités disponibles · seuil : 6</p>
      <p>Prix de vente · <?= e(money((int) $item['price_fcfa'])) ?></p>
      <strong><?= $unitCost !== null ? e(money($unitCost) . ' / unité') : 'Coût à renseigner' ?></strong>
      <p><?= $low ? 'À réassortir' : 'Stock suivi' ?></p>
    </a>
  <?php endforeach; ?>
</section>

<?php if ($selectedItem): ?>
  <?php $selectedUnitCost = $selectedItem['unit_cost'] !== null ? (float) $selectedItem['unit_cost'] : null; ?>
  <section class="admin-grid" style="margin-top:15px">
    <article class="admin-panel">
      <p class="admin-kicker">Mouvement · <?= e($selectedItem['name']) ?></p>
      <h2>Mettre le stock à jour.</h2>
      <form class="data-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="movement">
        <input type="hidden" name="product_id" value="<?= (int) $selectedItem['id'] ?>">
        <label>Mouvement
          <select name="movement_type">
            <option>Réassort</option>
            <option>Sortie</option>
            <option>Ajustement</option>
          </select>
        </label>
        <label>Quantité<input type="number" name="quantity" min="1" required></label>
        <label>Prix d’achat<input type="number" name="purchase_price" min="0" placeholder="Réassort"></label>
        <label>Prix de transit<input type="number" name="transit_price" min="0" placeholder="Réassort"></label>
        <label class="wide">Note (facultatif)<input name="note" maxlength="255" placeholder="Ex. arrivage d’août"></label>
        <button class="admin-button">Enregistrer</button>
      </form>
      <p style="color:#60718a;font-size:12px">
        Coût unitaire moyen actuel :
        <strong><?= $selectedUnitCost !== null ? e(money($selectedUnitCost)) : 'À renseigner' ?></strong>.
      </p>
    </article>

    <article class="admin-panel">
      <p class="admin-kicker">Publicité Meta · <?= e($selectedItem['name']) ?></p>
      <h2>Renseigner le coût ads.</h2>
      <form class="data-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ads">
        <input type="hidden" name="product_id" value="<?= (int) $selectedItem['id'] ?>">
        <label>Du<input type="date" name="start_date" required></label>
        <label>Au<input type="date" name="end_date" required></label>
        <label>Montant FCFA<input type="number" name="amount" min="1" required></label>
        <button class="admin-button">Enregistrer</button>
      </form>
      <p style="color:#60718a;font-size:12px">Le CAC se calcule avec les ventes de ce produit sur la même période.</p>
    </article>
  </section>

  <section class="admin-panel" style="margin-top:15px">
    <p class="admin-kicker">Prix de vente · <?= e($selectedItem['name']) ?></p>
    <h2>Mettre à jour le prix.</h2>
    <form class="data-form" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sale_price">
      <input type="hidden" name="product_id" value="<?= (int) $selectedItem['id'] ?>">
      <label>Prix de vente (FCFA)
        <input type="number" name="sale_price" min="1" value="<?= (int) $selectedItem['price_fcfa'] ?>" required>
      </label>
      <button class="admin-button">Enregistrer le prix</button>
    </form>
    <p style="color:#60718a;font-size:12px">
      Le nouveau prix est appliqué à la boutique, au panier et aux futures commandes. Les commandes existantes gardent leur prix d’origine.
    </p>
  </section>

  <section class="admin-panel" style="margin-top:15px">
    <p class="admin-kicker">Historique filtré · <?= e($selectedItem['name']) ?></p>
    <h2>Évènements et coûts.</h2>
    <div class="events">
      <?php foreach ($events as $event): ?>
        <div class="event">
          <span>
            <strong><?= e($event['movement_type']) ?></strong>
            <small><?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?></small>
          </span>
          <strong><?= $event['quantity'] > 0 ? '+' : '' ?><?= (int) $event['quantity'] ?> unité(s)</strong>
          <span>
            <?php if ($event['purchase_price_fcfa'] !== null): ?>
              Achat <?= e(money((int) $event['purchase_price_fcfa'])) ?> · Transit <?= e(money((int) $event['transit_price_fcfa'])) ?>
            <?php else: ?>
              <?= e($event['note'] ?? 'Coût non renseigné') ?>
            <?php endif; ?>
          </span>
          <small><?= e($event['actor'] ?? '') ?></small>
        </div>
      <?php endforeach; ?>
      <?php foreach ($ads as $ad): ?>
        <div class="event">
          <span>
            <strong>Publicité Meta</strong>
            <small><?= e($ad['start_date']) ?> → <?= e($ad['end_date']) ?></small>
          </span>
          <strong><?= e(money((int) $ad['amount_fcfa'])) ?></strong>
          <span>Coût ads renseigné</span>
          <small><?= e($ad['actor'] ?? '') ?></small>
        </div>
      <?php endforeach; ?>
      <?php if (!$events && !$ads): ?>
        <div class="event"><span>Aucun évènement.</span></div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
