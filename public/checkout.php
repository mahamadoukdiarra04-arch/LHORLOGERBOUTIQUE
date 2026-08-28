<?php
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/catalog.php';

function checkout_lines(): array {
    $raw = (string) ($_POST['cart'] ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    $slug = (string) ($_GET['product'] ?? '');
    $variant = (string) ($_GET['variant'] ?? '');
    return $slug ? [['slug' => $slug, 'variant' => $variant, 'quantity' => (int) ($_GET['quantity'] ?? 1)]] : [];
}

$lines = checkout_lines();
$catalog = catalog();
$validated = [];
foreach ($lines as $line) {
    $slug = (string) ($line['slug'] ?? ''); $product = $catalog[$slug] ?? null;
    $quantity = min(10, max(1, (int) ($line['quantity'] ?? 1)));
    $variant = (string) ($line['variant'] ?? '');
    if (!$product || !isset($product['variants'][$variant])) continue;
    $validated[] = ['slug' => $slug, 'product' => $product, 'variant' => $variant, 'quantity' => $quantity];
}
if (!$validated) redirect('/catalog.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $first = trim((string) ($_POST['first_name'] ?? ''));
    $last = trim((string) ($_POST['last_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $district = trim((string) ($_POST['district'] ?? ''));
    if (mb_strlen($first) < 2 || mb_strlen($last) < 2 || mb_strlen($phone) < 7 || mb_strlen($district) < 2) {
        flash('error', 'Renseignez correctement votre nom, téléphone et quartier.');
    } else {
        $ref = 'HOR-' . date('ymd') . '-' . random_int(100000, 999999);
        try {
            $pdo = db(); $pdo->beginTransaction();
            $find = $pdo->prepare('SELECT id FROM products WHERE slug = ?');
            $insert = $pdo->prepare('INSERT INTO orders (order_ref, customer_first_name, customer_last_name, phone, district, product_id, product_name, variant, quantity, unit_price_fcfa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($validated as $line) {
                $find->execute([$line['slug']]); $id = $find->fetchColumn();
                if (!$id) throw new RuntimeException('Produit indisponible.');
                $p = $line['product'];
                $insert->execute([$ref, $first, $last, $phone, $district, $id, $p['name'], $line['variant'], $line['quantity'], $p['price']]);
            }
            $pdo->commit();
            $_SESSION['latest_order_ref'] = $ref; $_SESSION['latest_first_name'] = $first;
            redirect('/order-success.php?ref=' . rawurlencode($ref));
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'La commande ne peut pas être enregistrée pour le moment. Réessayez dans quelques instants.');
        }
    }
}
$total = array_sum(array_map(fn($line) => $line['product']['price'] * $line['quantity'], $validated));
$pageTitle = 'Finaliser votre commande · L’Horloger'; require APP_ROOT . '/templates/store-header.php';
?>
<main class="container checkout-page"><p class="eyebrow">Finaliser votre commande</p><h1>Vos informations de livraison.</h1><div class="checkout-layout"><section><p class="product-description">Nous vous contacterons pour confirmer votre quartier et votre créneau de livraison.</p><?php if ($message = flash('error')): ?><p class="flash flash-error"><?= e($message) ?></p><?php endif; ?><form class="checkout-form" method="post"><?= csrf_field() ?><input type="hidden" name="cart" id="checkout-cart"><div class="form-row"><label>Prénom<input name="first_name" autocomplete="given-name" required></label><label>Nom<input name="last_name" autocomplete="family-name" required></label></div><div class="form-row"><label>Téléphone<input name="phone" inputmode="tel" autocomplete="tel" placeholder="+223 XX XX XX XX" required></label><label>Quartier<input name="district" autocomplete="address-level3" required></label></div><button class="button" type="submit">Confirmer ma commande</button></form></section><aside class="checkout-summary"><h2>Votre commande</h2><?php foreach ($validated as $line): ?><div class="checkout-summary__line"><span><?= e($line['product']['name']) ?> · <?= e($line['variant']) ?> × <?= $line['quantity'] ?></span><strong><?= money($line['product']['price'] * $line['quantity']) ?></strong></div><?php endforeach; ?><div class="checkout-summary__line"><span>Livraison</span><strong>Offerte</strong></div><div class="checkout-summary__total"><span>Total à la réception</span><strong><?= money($total) ?></strong></div></aside></div></main><script>const query=new URLSearchParams(location.search);const direct=query.get('product');if(direct){document.getElementById('checkout-cart').value=JSON.stringify([{slug:direct,variant:query.get('variant'),quantity:Number(query.get('quantity')||1)}])}else document.getElementById('checkout-cart').value=JSON.stringify(LHorlogerCart.read());</script><?php require APP_ROOT . '/templates/store-footer.php'; ?>
