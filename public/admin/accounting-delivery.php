<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
$orderId = (int) ($_POST['order_id'] ?? $_GET['order'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $result = accounting_confirm_delivery($pdo, $orderId, $_POST, accounting_current_user_id());
        flash('success', $result['replayed']
            ? 'Cette livraison avait déjà été enregistrée. Aucun second encaissement ni aucune seconde sortie de stock n’a été créé.'
            : 'Référence ' . $result['order_ref'] . ' livrée et encaissement enregistré.');
        redirect('/admin/orders.php?order=' . $orderId);
    } catch (Throwable $exception) {
        error_log('L’Horloger: livraison comptable échouée.');
        flash('error', accounting_safe_error_message($exception, 'La livraison n’a pas pu être enregistrée. Réessayez dans quelques instants.'));
        redirect('/admin/accounting-delivery.php?order=' . $orderId);
    }
}

try {
    $delivery = accounting_delivery_preview($pdo, $orderId);
} catch (Throwable $exception) {
    error_log('L’Horloger: préparation de la livraison comptable échouée.');
    $deliveryError = accounting_safe_error_message($exception, 'La livraison ne peut pas être préparée pour le moment. Réessayez dans quelques instants.');
}

$adminPageTitle = 'Encaisser & livrer';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Livraison & encaissement</p>
    <h1>Confirmer toute la référence.</h1>
    <p>Le statut Livrée, les encaissements et les sorties de stock sont enregistrés ensemble. Une actualisation ne pourra pas les dupliquer.</p>
  </div>
  <a class="text-link" href="<?= e(url('/admin/orders.php?order=' . $orderId)) ?>">← Retour aux commandes</a>
</header>

<?php if (isset($deliveryError)): ?>
  <section class="admin-panel"><p class="flash flash-error"><?= e($deliveryError) ?></p></section>
<?php else: ?>
  <section class="metric-grid delivery-metrics">
    <article class="metric"><p>Référence</p><strong><?= e($delivery['order_ref']) ?></strong><span><?= count($delivery['lines']) ?> ligne(s) de commande</span></article>
    <article class="metric"><p>Total à recevoir</p><strong><?= money($delivery['total_fcfa']) ?></strong><span>Livraison offerte</span></article>
    <article class="metric"><p>Comptes actifs</p><strong><?= count($delivery['accounts']) ?></strong><span>Choisissez où l’argent est reçu</span></article>
  </section>

  <section class="admin-panel delivery-lines">
    <p class="admin-kicker">Contenu de la référence</p>
    <h2>Les lignes qui seront livrées ensemble.</h2>
    <div class="events">
      <?php foreach ($delivery['lines'] as $line): ?>
        <div class="event"><span><strong><?= e($line['product_name']) ?></strong><small><?= e($line['variant']) ?></small></span><strong>Qté <?= (int) $line['quantity'] ?></strong><span><?= money($line['unit_price_fcfa']) ?> / unité</span><small><?= e($line['status']) ?></small></div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if (!$delivery['accounts']): ?>
    <section class="admin-panel" style="margin-top:15px"><p class="admin-kicker">Compte requis</p><h2>Configurez d’abord un compte réel.</h2><p class="admin-copy">Aucun compte de trésorerie actif n’est encore configuré. La livraison reste volontairement bloquée pour ne pas créer d’encaissement fictif.</p></section>
  <?php elseif (array_filter($delivery['lines'], static fn (array $line): bool => $line['status'] === 'Livrée')): ?>
    <section class="admin-panel" style="margin-top:15px"><p class="admin-kicker">Déjà livrée</p><h2>Cette référence est déjà protégée.</h2><p class="admin-copy">Ouvrez son futur Journal comptable pour consulter ou contrepasser son écriture ; elle ne peut pas être livrée une seconde fois.</p></section>
  <?php else: ?>
    <section class="admin-panel delivery-form-panel" style="margin-top:15px">
      <p class="admin-kicker">Encaissements</p>
      <h2>Où le paiement a-t-il été reçu ?</h2>
      <form method="post" class="delivery-form">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">
        <input type="hidden" name="idempotency_key" value="<?= e(accounting_new_uuid()) ?>">
        <label class="delivery-date">Date de livraison<input type="datetime-local" name="effective_at" value="<?= e((new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d\TH:i')) ?>" required></label>
        <div class="delivery-payments">
          <?php for ($index = 0; $index < 3; $index++): ?>
            <fieldset><legend>Encaissement <?= $index + 1 ?><?= $index === 0 ? ' · requis' : ' · facultatif' ?></legend>
              <label>Compte<select name="payments[<?= $index ?>][account_id]" <?= $index === 0 ? 'required' : '' ?>><option value="">Choisir</option><?php foreach ($delivery['accounts'] as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= e($account['code']) ?></option><?php endforeach; ?></select></label>
              <label>Montant FCFA<input type="number" name="payments[<?= $index ?>][amount_fcfa]" min="1" inputmode="numeric" <?= $index === 0 ? 'required' : '' ?>></label>
              <label>Référence <small>facultatif</small><input name="payments[<?= $index ?>][payment_reference]" maxlength="120" placeholder="Ex. reçu ou transfert"></label>
            </fieldset>
          <?php endfor; ?>
        </div>
        <label class="delivery-exception"><input type="checkbox" name="exception_mode" value="1"><span>Livrer avec un reliquat de paiement</span></label>
        <label>Motif du reliquat <small>obligatoire seulement si le total encaissé est incomplet</small><textarea name="exception_reason" maxlength="500" rows="3" placeholder="Ex. solde à récupérer mardi"></textarea></label>
        <button class="admin-button" type="submit">Confirmer l’encaissement & la livraison</button>
      </form>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
