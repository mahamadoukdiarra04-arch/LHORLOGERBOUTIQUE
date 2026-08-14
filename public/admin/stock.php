<?php
require __DIR__ . '/../../app/bootstrap.php'; require_admin(); $pdo=db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);
    $exists = $pdo->prepare('SELECT name FROM products WHERE id = ?');
    $exists->execute([$productId]);
    $name = $exists->fetchColumn();
    try {
        if (!$name) throw new RuntimeException('Produit invalide.');
        if ($action === 'movement') {
            $type = (string) ($_POST['movement_type'] ?? '');
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $purchase = max(0, (int) ($_POST['purchase_price'] ?? 0));
            $transit = max(0, (int) ($_POST['transit_price'] ?? 0));
            $note = trim((string) ($_POST['note'] ?? ''));
            if (!in_array($type, ['Réassort', 'Sortie', 'Ajustement'], true) || $quantity < 1) throw new RuntimeException('Quantité ou mouvement invalide.');
            if ($type === 'Réassort' && $purchase < 1) throw new RuntimeException('Indiquez le prix d’achat du réassort.');
            if ($type === 'Sortie') {
                $check = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements WHERE product_id = ?');
                $check->execute([$productId]);
                if ((int) $check->fetchColumn() < $quantity) throw new RuntimeException('La sortie dépasse le stock disponible.');
                $quantity = -$quantity;
            }
            $unit = $type === 'Réassort' ? ($purchase + $transit) / $quantity : null;
            $stmt = $pdo->prepare('INSERT INTO stock_movements(product_id, movement_type, quantity, purchase_price_fcfa, transit_price_fcfa, unit_cost_fcfa, note, actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$productId, $type, $quantity, $type === 'Réassort' ? $purchase : null, $type === 'Réassort' ? $transit : null, $unit, $note !== '' ? $note : null, admin_identity()]);
            log_event('stock', $type . ' · ' . $name . ' · ' . abs($quantity) . ' unité(s)', $productId);
            flash('success', 'Mouvement de stock enregistré.');
        } elseif ($action === 'ads') {
            $start = (string) ($_POST['start_date'] ?? '');
            $end = (string) ($_POST['end_date'] ?? '');
            $amount = (int) ($_POST['amount'] ?? 0);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end || $amount < 1) throw new RuntimeException('Renseignez une période et un coût publicitaire valides.');
            $stmt = $pdo->prepare("INSERT INTO ad_costs(product_id, channel, start_date, end_date, amount_fcfa, actor) VALUES (?, 'Meta', ?, ?, ?, ?)");
            $stmt->execute([$productId, $start, $end, $amount, admin_identity()]);
            log_event('publicité', 'Coût Meta · ' . $name . ' · ' . money($amount), $productId);
            flash('success', 'Coût publicitaire enregistré.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/stock.php?product=' . $productId);
}
$stock=$pdo->query("SELECT p.id,p.name,p.slug,p.price_fcfa,COALESCE(SUM(sm.quantity),0) quantity,COALESCE((SELECT SUM(COALESCE(s2.purchase_price_fcfa,0)+COALESCE(s2.transit_price_fcfa,0))/NULLIF(SUM(s2.quantity),0) FROM stock_movements s2 WHERE s2.product_id=p.id AND s2.movement_type='Réassort'),0) unit_cost FROM products p LEFT JOIN stock_movements sm ON sm.product_id=p.id GROUP BY p.id ORDER BY p.id")->fetchAll();$selected=(int)($_GET['product']??($stock[0]['id']??0));$events=$pdo->prepare('SELECT sm.*,p.name FROM stock_movements sm JOIN products p ON p.id=sm.product_id WHERE sm.product_id=? ORDER BY sm.created_at DESC LIMIT 100');$events->execute([$selected]);$events=$events->fetchAll();$ads=$pdo->prepare('SELECT * FROM ad_costs WHERE product_id=? ORDER BY start_date DESC');$ads->execute([$selected]);$ads=$ads->fetchAll();$selectedItem=current(array_filter($stock,fn($x)=>(int)$x['id']===$selected))?:$stock[0]??null;
$adminPageTitle='Stock & coûts';require APP_ROOT.'/templates/admin-header.php';
?>
<header class="admin-page-head"><div><p class="admin-kicker">Stock & réassort</p><h1>La rentabilité commence ici.</h1><p>Un réassort renseigne la quantité, le prix d’achat et le transit. Le coût unitaire est calculé automatiquement.</p></div></header><section class="stock-grid"><?php foreach($stock as $item): $low=(int)$item['quantity']<=6; ?><a class="stock-card <?= $low?'low':'' ?>" href="?product=<?= (int)$item['id'] ?>"><p><?= e($item['name']) ?></p><div class="quantity"><?= (int)$item['quantity'] ?></div><p>unités disponibles · seuil : 6</p><strong><?= money((float)$item['unit_cost']) ?> / unité</strong><p><?= $low?'À réassortir':'Stock suivi' ?></p></a><?php endforeach; ?></section><?php if($selectedItem): ?><section class="admin-grid" style="margin-top:15px"><article class="admin-panel"><p class="admin-kicker">Mouvement · <?= e($selectedItem['name']) ?></p><h2>Mettre le stock à jour.</h2><form class="data-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="movement"><input type="hidden" name="product_id" value="<?= (int)$selectedItem['id'] ?>"><label>Mouvement<select name="movement_type"><option>Réassort</option><option>Sortie</option><option>Ajustement</option></select></label><label>Quantité<input type="number" name="quantity" min="1" required></label><label>Prix d’achat<input type="number" name="purchase_price" min="0" placeholder="Réassort"></label><label>Prix de transit<input type="number" name="transit_price" min="0" placeholder="Réassort"></label><label class="wide">Note (facultatif)<input name="note" maxlength="255" placeholder="Ex. arrivage d’août"></label><button class="admin-button">Enregistrer</button></form><p style="color:#60718a;font-size:12px">Coût unitaire moyen actuel : <strong><?= money((float)$selectedItem['unit_cost']) ?></strong>.</p></article><article class="admin-panel"><p class="admin-kicker">Publicité Meta · <?= e($selectedItem['name']) ?></p><h2>Renseigner le coût ads.</h2><form class="data-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ads"><input type="hidden" name="product_id" value="<?= (int)$selectedItem['id'] ?>"><label>Du<input type="date" name="start_date" required></label><label>Au<input type="date" name="end_date" required></label><label>Montant FCFA<input type="number" name="amount" min="1" required></label><button class="admin-button">Enregistrer</button></form><p style="color:#60718a;font-size:12px">Le CAC se calcule avec les ventes de ce produit sur la même période.</p></article></section><section class="admin-panel" style="margin-top:15px"><p class="admin-kicker">Historique filtré · <?= e($selectedItem['name']) ?></p><h2>Évènements et coûts.</h2><div class="events"><?php foreach($events as $event): ?><div class="event"><span><strong><?= e($event['movement_type']) ?></strong><small><?= e(date('d/m/Y H:i',strtotime($event['created_at']))) ?></small></span><strong><?= $event['quantity']>0?'+':'' ?><?= (int)$event['quantity'] ?> unité(s)</strong><span><?= $event['purchase_price_fcfa']!==null?'Achat '.money((int)$event['purchase_price_fcfa']).' · Transit '.money((int)$event['transit_price_fcfa']):e($event['note']??'') ?></span><small><?= e($event['actor']??'') ?></small></div><?php endforeach; ?><?php foreach($ads as $ad): ?><div class="event"><span><strong>Publicité Meta</strong><small><?= e($ad['start_date']) ?> → <?= e($ad['end_date']) ?></small></span><strong><?= money((int)$ad['amount_fcfa']) ?></strong><span>Coût ads renseigné</span><small><?= e($ad['actor']??'') ?></small></div><?php endforeach; ?><?php if(!$events&&!$ads): ?><div class="event"><span>Aucun évènement.</span></div><?php endif; ?></div></section><?php endif; ?>
<?php require APP_ROOT.'/templates/admin-footer.php'; ?>
