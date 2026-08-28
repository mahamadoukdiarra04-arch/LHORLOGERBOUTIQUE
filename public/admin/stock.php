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
    $selectedVariantId = (int) ($_POST['variant_id'] ?? 0);
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
                    'variant_id' => $selectedVariantId,
                    'movement_type' => $type,
                    'quantity' => $_POST['quantity'] ?? null,
                    'purchase_price_fcfa' => $_POST['purchase_price'] ?? null,
                    'transit_price_fcfa' => $_POST['transit_price'] ?? null,
                    'note' => $_POST['note'] ?? null,
                    'actor' => admin_identity(),
                ]);
                $variantLabel = $movement['variant_name'] ? ' · ' . $movement['variant_name'] : '';
                log_event('stock', $type . ' · ' . $productName . $variantLabel . ' · ' . abs((int) $movement['quantity']) . ' unité(s)', $productId);
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

    redirect('/admin/stock.php?' . http_build_query(array_filter([
        'product' => $productId ?: null,
        'variant' => $selectedVariantId ?: null,
    ])));
}

$stockQuery = "
    SELECT
        p.id,
        p.name,
        p.slug,
        p.price_fcfa,
        COALESCE(SUM(sm.quantity), 0) AS quantity,
        COALESCE(SUM(CASE WHEN sm.variant_id IS NOT NULL THEN sm.quantity ELSE 0 END), 0) AS classified_quantity,
        COALESCE(SUM(CASE WHEN sm.variant_id IS NULL THEN sm.quantity ELSE 0 END), 0) AS unclassified_quantity,
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
$selectedVariant = (int) ($_GET['variant'] ?? 0);
[$periodKey, $periodLabel, $start, $end] = allowed_period();

$variantsStatement = $pdo->query(
    "SELECT
        pv.id, pv.product_id, pv.name, pv.image_path,
        COALESCE(SUM(sm.quantity), 0) AS quantity,
        (
            SELECT SUM(s2.purchase_price_fcfa + COALESCE(s2.transit_price_fcfa, 0)) / NULLIF(SUM(s2.quantity), 0)
            FROM stock_movements s2
            WHERE s2.variant_id = pv.id
              AND s2.movement_type = 'Réassort'
              AND s2.purchase_price_fcfa IS NOT NULL
        ) AS unit_cost
     FROM product_variants pv
     LEFT JOIN stock_movements sm ON sm.variant_id = pv.id
     WHERE pv.is_active = 1
     GROUP BY pv.id
     ORDER BY pv.product_id ASC, pv.name ASC"
);
$variantsByProduct = [];
foreach ($variantsStatement->fetchAll() as $variant) {
    $variantsByProduct[(int) $variant['product_id']][] = $variant;
}

$eventsStatement = $pdo->prepare(
    'SELECT sm.*, p.name, pv.name AS variant_name
     FROM stock_movements sm
     JOIN products p ON p.id = sm.product_id
     LEFT JOIN product_variants pv ON pv.id = sm.variant_id
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
$selectedVariants = $selectedItem ? ($variantsByProduct[(int) $selectedItem['id']] ?? []) : [];
$selectedVariantItem = current(array_filter($selectedVariants, fn(array $variant): bool => (int) $variant['id'] === $selectedVariant)) ?: null;

try {
    $accountsReady = accounting_has_active_accounts($pdo);
    $selectedResult = null;
    if ($accountsReady && $selectedItem) {
        foreach (accounting_product_results($pdo, $start, $end) as $result) {
            if ((int) $result['product_id'] === (int) $selectedItem['id']) {
                $selectedResult = $result;
                break;
            }
        }
    }
} catch (Throwable $exception) {
    error_log('L’Horloger: rentabilité produit indisponible dans le stock.');
    $accountsReady = false;
    $selectedResult = null;
    $stockFinanceError = 'La rentabilité comptable ne peut pas être préparée pour le moment.';
}

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
    <a class="stock-card <?= $low ? 'low' : '' ?>" href="?<?= e(http_build_query(['product' => (int) $item['id'], 'period' => $periodKey, 'start' => $start, 'end' => $end])) ?>">
      <p class="stock-card__eyebrow">Modèle · <?= count($variantsByProduct[(int) $item['id']] ?? []) ?> coloris</p>
      <h2><?= e($item['name']) ?></h2>
      <div class="quantity"><?= (int) $item['quantity'] ?></div>
      <p>unités disponibles · seuil : 6</p>
      <p>Prix de vente · <?= e(money((int) $item['price_fcfa'])) ?></p>
      <strong><?= $unitCost !== null ? e(money($unitCost) . ' / unité') : 'Coût à renseigner' ?></strong>
      <span class="stock-card__action"><?= $low ? 'À réassortir' : 'Voir les coloris' ?> →</span>
    </a>
  <?php endforeach; ?>
</section>

<?php if ($selectedItem): ?>
  <?php $selectedUnitCost = $selectedItem['unit_cost'] !== null ? (float) $selectedItem['unit_cost'] : null; ?>
  <section class="admin-panel variant-stock-panel" style="margin-top:15px">
    <div class="admin-panel__head"><div><p class="admin-kicker">Variantes · <?= e($selectedItem['name']) ?></p><h2>Un modèle, tous ses coloris.</h2></div><span class="variant-stock-summary"><?= (int) $selectedItem['quantity'] ?> unité(s) au total</span></div>
    <p class="admin-copy">Choisissez un coloris pour préparer le réassort. Chaque variante conserve son propre stock et son coût unitaire.</p>
    <div class="variant-stock-grid">
      <?php foreach ($selectedVariants as $variant): ?>
        <?php $variantLow = (int) $variant['quantity'] <= 2; ?>
        <a class="variant-stock-card <?= $selectedVariantItem && (int) $selectedVariantItem['id'] === (int) $variant['id'] ? 'active' : '' ?> <?= $variantLow ? 'low' : '' ?>" href="?<?= e(http_build_query(['product' => (int) $selectedItem['id'], 'variant' => (int) $variant['id'], 'period' => $periodKey, 'start' => $start, 'end' => $end])) ?>">
          <?php if ($variant['image_path']): ?><img src="<?= e(url('/' . $variant['image_path'])) ?>" alt="<?= e($variant['name']) ?>"><?php endif; ?>
          <span><strong><?= e($variant['name']) ?></strong><small><?= (int) $variant['quantity'] ?> unité(s) · <?= $variantLow ? 'à surveiller' : 'suivi' ?></small><b><?= $variant['unit_cost'] !== null ? e(money((float) $variant['unit_cost'])) : 'Coût à renseigner' ?></b></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ((int) $selectedItem['unclassified_quantity'] !== 0): ?>
      <p class="admin-copy variant-stock-note"><?= (int) $selectedItem['unclassified_quantity'] ?> unité(s) historique(s) restent au niveau du modèle : elles ne sont volontairement attribuées à aucun coloris sans information fiable.</p>
    <?php endif; ?>
  </section>
  <section class="admin-panel finance-context" style="margin-top:15px">
    <div class="admin-panel__head"><div><p class="admin-kicker">Rentabilité réalisée · <?= e($selectedItem['name']) ?></p><h2>Lecture comptable de la période.</h2></div><a href="<?= e(url('/admin/accounting-ted.php?' . http_build_query(['period' => $periodKey, 'start' => $start, 'end' => $end]))) ?>">Voir le TED →</a></div>
    <form class="admin-period accounting-period" method="get"><input type="hidden" name="product" value="<?= (int) $selectedItem['id'] ?>"><?php foreach (['today' => 'Aujourd’hui', '7' => '7 jours', '30' => '30 jours', '90' => '90 jours', 'month' => 'Ce mois', 'year' => 'Cette année'] as $key => $label): ?><a class="<?= $periodKey === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(['product' => (int) $selectedItem['id'], 'period' => $key])) ?>"><?= e($label) ?></a><?php endforeach; ?><input type="hidden" name="period" value="custom"><span class="custom-period"><input type="date" name="start" value="<?= e($start) ?>"><input type="date" name="end" value="<?= e($end) ?>"><button class="admin-button">Choisir</button></span></form>
    <?php if (isset($stockFinanceError)): ?>
      <p class="flash flash-error"><?= e($stockFinanceError) ?></p>
    <?php elseif (!$accountsReady): ?>
      <p class="admin-copy">Comptabilité à initialiser : aucun montant réalisé n’est affiché avant la création d’un compte réel.</p><a class="text-link" href="<?= e(url('/admin/accounting-settings.php')) ?>">Configurer les comptes →</a>
    <?php elseif (!$selectedResult): ?>
      <p class="admin-copy">Aucune vente ou charge comptable confirmée pour ce produit sur <?= e($periodLabel) ?>.</p><a class="text-link" href="<?= e(url('/admin/accounting-journal.php?product_id=' . (int) $selectedItem['id'] . '&start=' . rawurlencode($start) . '&end=' . rawurlencode($end))) ?>">Voir le Journal filtré →</a>
    <?php else: ?>
      <div class="metric-grid realized-product-metrics"><article class="metric"><p>CA net réalisé</p><strong><?= money($selectedResult['net_revenue_fcfa']) ?></strong><span>Ventes moins remboursements</span></article><article class="metric"><p>Coût des montres vendues</p><strong><?= money($selectedResult['cogs_fcfa']) ?></strong><span>Coût figé à la sortie de stock</span></article><article class="metric"><p>Charges directes</p><strong><?= money($selectedResult['direct_expense_fcfa']) ?></strong><span>Dont Meta comptabilisé : <?= money($selectedResult['meta_ads_fcfa']) ?></span></article><article class="metric"><p>Contribution</p><strong><?= money($selectedResult['contribution_fcfa']) ?></strong><span>Après charges directes</span></article></div>
      <a class="text-link" href="<?= e(url('/admin/accounting-journal.php?product_id=' . (int) $selectedItem['id'] . '&start=' . rawurlencode($start) . '&end=' . rawurlencode($end))) ?>">Voir le Journal filtré de ce produit →</a>
    <?php endif; ?>
  </section>
  <section class="admin-grid" style="margin-top:15px">
    <article class="admin-panel">
      <p class="admin-kicker">Mouvement · <?= e($selectedItem['name']) ?><?= $selectedVariantItem ? ' · ' . e($selectedVariantItem['name']) : '' ?></p>
      <h2>Mettre le stock à jour.</h2>
      <form class="data-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="movement">
        <input type="hidden" name="product_id" value="<?= (int) $selectedItem['id'] ?>">
        <label>Coloris
          <select name="variant_id" required>
            <option value="">Choisir un coloris</option>
            <?php foreach ($selectedVariants as $variant): ?><option value="<?= (int) $variant['id'] ?>" <?= $selectedVariantItem && (int) $selectedVariantItem['id'] === (int) $variant['id'] ? 'selected' : '' ?>><?= e($variant['name']) ?> · <?= (int) $variant['quantity'] ?> unité(s)</option><?php endforeach; ?>
          </select>
        </label>
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
        <?= $selectedVariantItem ? 'Coût unitaire de ce coloris' : 'Coût unitaire moyen du modèle' ?> :
        <strong><?= $selectedVariantItem && $selectedVariantItem['unit_cost'] !== null ? e(money((float) $selectedVariantItem['unit_cost'])) : ($selectedVariantItem ? 'À renseigner' : ($selectedUnitCost !== null ? e(money($selectedUnitCost)) : 'À renseigner')) ?></strong>.
      </p>
    </article>

    <article class="admin-panel">
      <p class="admin-kicker">Suivi Meta marketing · <?= e($selectedItem['name']) ?></p>
      <h2>Renseigner le coût ads.</h2>
      <form class="data-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ads">
        <input type="hidden" name="product_id" value="<?= (int) $selectedItem['id'] ?>">
        <input type="hidden" name="variant_id" value="<?= $selectedVariantItem ? (int) $selectedVariantItem['id'] : '' ?>">
        <label>Du<input type="date" name="start_date" required></label>
        <label>Au<input type="date" name="end_date" required></label>
        <label>Montant FCFA<input type="number" name="amount" min="1" required></label>
        <button class="admin-button">Enregistrer</button>
      </form>
      <p style="color:#60718a;font-size:12px">Le CAC marketing se calcule avec les commandes web livrées sur la même période. Ce suivi n’entre pas encore dans le résultat réalisé : comptabilisez le décaissement Meta depuis Comptabilité.</p>
    </article>
  </section>

  <section class="admin-panel" style="margin-top:15px">
    <p class="admin-kicker">Prix de vente · <?= e($selectedItem['name']) ?></p>
    <h2>Mettre à jour le prix.</h2>
    <form class="data-form" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sale_price">
      <input type="hidden" name="product_id" value="<?= (int) $selectedItem['id'] ?>">
      <input type="hidden" name="variant_id" value="<?= $selectedVariantItem ? (int) $selectedVariantItem['id'] : '' ?>">
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
            <small><?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?><?= $event['variant_name'] ? ' · ' . e($event['variant_name']) : ' · Modèle non ventilé' ?></small>
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
            <strong>Suivi Meta marketing</strong>
            <small><?= e($ad['start_date']) ?> → <?= e($ad['end_date']) ?></small>
          </span>
          <strong><?= e(money((int) $ad['amount_fcfa'])) ?></strong>
          <span>Coût ads suivi · hors trésorerie comptable</span>
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
