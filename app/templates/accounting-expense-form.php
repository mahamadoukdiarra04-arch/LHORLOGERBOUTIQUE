<?php $input = $_POST; ?>
<section class="admin-panel accounting-action-panel">
  <p class="admin-kicker">Dépense</p><h2>Enregistrer une dépense.</h2>
  <p class="admin-copy">Montant, catégorie et date. Le libellé et le classement comptable sont préparés automatiquement.</p>
  <form class="quick-money-form" method="post" data-simple-expense>
    <?= csrf_field() ?><input type="hidden" name="form_action" value="disbursement"><input type="hidden" name="simple_expense" value="1"><input type="hidden" name="idempotency_key" value="<?= e($input['idempotency_key'] ?? accounting_new_uuid()) ?>">
    <label>Montant FCFA<input type="number" name="amount_fcfa" value="<?= e($input['amount_fcfa'] ?? '') ?>" min="1" inputmode="numeric" required autofocus></label>
    <label>Catégorie<select name="category_id" data-expense-category required><option value="">Choisir la dépense</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" data-scope="<?= e($category['default_scope']) ?>" <?= (int) ($input['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
    <label data-expense-product>Produit concerné<select name="product_id"><option value="">Choisir le produit</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (int) ($input['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select></label>
    <label>Payé depuis<select name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) ($input['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['name']) ?></option><?php endforeach; ?></select></label>
    <label>Date réelle<input type="datetime-local" name="effective_at" value="<?= e($input['effective_at'] ?? $now) ?>" required></label>
    <p class="quick-explanation" data-expense-summary>La dépense sera inscrite au journal dès la validation.</p>
    <details><summary>Ajouter une précision ou une référence</summary><div>
      <label>Libellé personnalisé (facultatif)<input name="label" value="<?= e($input['label'] ?? '') ?>" maxlength="180" placeholder="Automatique si laissé vide"></label>
      <label>Payé à (facultatif)<input name="counterparty" value="<?= e($input['counterparty'] ?? '') ?>" maxlength="180" placeholder="Ex. ABOU, Meta"></label>
      <label>Référence de paiement (facultative)<input name="payment_reference" value="<?= e($input['payment_reference'] ?? '') ?>" maxlength="120"></label>
      <label>Enregistrement<select name="status"><option value="confirmed">Enregistrer maintenant</option><option value="draft" <?= ($input['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Garder en brouillon</option></select></label>
      <label class="wide">Note (facultative)<textarea name="note" maxlength="5000" rows="2"><?= e($input['note'] ?? '') ?></textarea></label>
    </div></details>
    <details><summary>Exception : paiement réel malgré un solde insuffisant</summary><div>
      <label class="accounting-check wide"><input type="checkbox" name="allow_negative_balance" value="1" <?= !empty($input['allow_negative_balance']) ? 'checked' : '' ?>><span>J’autorise exceptionnellement un solde négatif</span></label>
      <label class="wide">Justification<input name="negative_balance_acknowledgement" value="<?= e($input['negative_balance_acknowledgement'] ?? '') ?>" maxlength="500" placeholder="À renseigner seulement pour cette exception"></label>
    </div><p class="quick-explanation">Ajoutez aussi une note dans les précisions. Aucune autorisation de découvert n’est activée automatiquement.</p></details>
    <button class="admin-button" type="submit">Enregistrer la dépense</button>
  </form>
</section>
