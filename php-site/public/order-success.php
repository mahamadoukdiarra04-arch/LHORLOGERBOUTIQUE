<?php
require __DIR__ . '/../app/bootstrap.php';

$sessionReference = (string) ($_SESSION['latest_order_ref'] ?? '');
$queryReference = trim((string) ($_GET['ref'] ?? ''));

if ($sessionReference !== '') {
    $reference = $sessionReference;
} elseif (preg_match('/^HOR-\d{6}-\d{6}$/', $queryReference)) {
    try {
        $orderStatement = db()->prepare('SELECT 1 FROM orders WHERE order_ref = ? LIMIT 1');
        $orderStatement->execute([$queryReference]);
        if (!$orderStatement->fetchColumn()) redirect('/');
        $reference = $queryReference;
    } catch (Throwable) {
        redirect('/');
    }
} else {
    redirect('/');
}

$first = (string) ($_SESSION['latest_first_name'] ?? '');
unset($_SESSION['latest_order_ref'], $_SESSION['latest_first_name']);

$pageTitle = 'Merci pour votre commande · L’Horloger';
require APP_ROOT . '/templates/store-header.php';
?>
<main class="container checkout-page">
  <section class="success">
    <p class="eyebrow">Commande enregistrée</p>
    <h1><?= $first !== '' ? 'Merci, ' . e($first) . '.' : 'Merci pour votre commande.' ?></h1>
    <p><strong>Référence <?= e($reference) ?></strong></p>
    <p>L’équipe L’Horloger vous contactera pour confirmer le quartier et le créneau de livraison.</p>
    <p><a class="button" href="<?= e(url('/catalog.php')) ?>">Retour aux montres</a></p>
  </section>
</main>
<script>LHorlogerCart.write([]);</script>
<?php require APP_ROOT . '/templates/store-footer.php'; ?>
