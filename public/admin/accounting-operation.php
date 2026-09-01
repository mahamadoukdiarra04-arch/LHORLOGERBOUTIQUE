<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
$pdo = db();
$operationId = (int) ($_POST['operation_id'] ?? $_GET['operation'] ?? 0);
$redirectOperationId = $operationId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $formAction = (string) ($_POST['action'] ?? '');
        if ($formAction === 'reverse') {
            $result = accounting_reverse_operation($pdo, $operationId, (string) ($_POST['idempotency_key'] ?? ''), $_POST['effective_at'] ?? null, $_POST['reason'] ?? null, accounting_current_user_id());
            flash('success', $result['replayed'] ? 'Cette contrepassation existait déjà.' : 'Contrepassation enregistrée : ' . $result['operation']['reference'] . '.');
        } elseif ($formAction === 'reissue_date') {
            $result = accounting_reissue_order_receipt_date(
                $pdo,
                $operationId,
                (string) ($_POST['reversal_idempotency_key'] ?? ''),
                (string) ($_POST['replacement_idempotency_key'] ?? ''),
                $_POST['effective_at'] ?? null,
                $_POST['reason'] ?? null,
                accounting_current_user_id(),
            );
            $redirectOperationId = (int) $result['replacement']['id'];
            flash('success', $result['replayed'] ? 'Cette correction de date existait déjà.' : 'Date corrigée sans effacer l’écriture d’origine.');
        } elseif ($formAction === 'confirm_draft') {
            $result = accounting_confirm_draft_disbursement($pdo, $operationId, $_POST, accounting_current_user_id());
            flash('success', $result['replayed'] ? 'Ce brouillon était déjà confirmé.' : 'Le brouillon est désormais confirmé.');
        } elseif ($formAction === 'attachment') {
            $attachment = accounting_store_attachment($pdo, $_FILES['attachment'] ?? [], $operationId, null, accounting_current_user_id());
            flash('success', 'Pièce jointe : ' . $attachment['original_name'] . '.');
        } else {
            throw new RuntimeException('Action sur écriture invalide.');
        }
    } catch (Throwable $exception) {
        error_log('L’Horloger: action sur écriture échouée.');
        flash('error', accounting_safe_error_message($exception, 'Cette action n’a pas pu être enregistrée.'));
    }
    redirect('/admin/accounting-operation.php?operation=' . $redirectOperationId);
}

try {
    ensure_accounting_schema();
    $detail = accounting_operation_detail($pdo, $operationId);
} catch (Throwable $exception) {
    error_log('L’Horloger: détail écriture indisponible.');
    $operationError = accounting_safe_error_message($exception, 'Cette écriture ne peut pas être affichée pour le moment.');
}
$adminPageTitle = 'Détail écriture';
require APP_ROOT . '/templates/admin-header.php';
$now = (new DateTimeImmutable('now', accounting_bamako_timezone()))->format('Y-m-d\TH:i');
?>
<header class="admin-page-head accounting-head"><div><p class="admin-kicker">Comptabilité · Écriture</p><h1>Détail vérifiable.</h1><p>Chaque ventilation, pièce et correction reste reliée à l’écriture d’origine.</p></div><a class="text-link" href="<?= e(url('/admin/accounting-journal.php')) ?>">← Journal</a></header>
<?php if (isset($operationError)): ?><section class="admin-panel"><p class="flash flash-error"><?= e($operationError) ?></p></section><?php else: $operation = $detail['operation']; ?>
<section class="operation-hero"><div><span><?= e($operation['group_type']) ?> · <?= e($operation['status']) ?></span><strong><?= e($operation['label']) ?></strong><small><?= e($operation['reference']) ?> · groupe <?= e($operation['group_reference']) ?></small></div><b class="<?= $operation['nature'] === 'disbursement' ? 'amount-negative' : '' ?>"><?= $operation['nature'] === 'disbursement' ? '−' : ($operation['nature'] === 'receipt' ? '+' : '↔') ?><?= money($operation['amount_fcfa']) ?></b></section>
<section class="operation-facts"><div><span>Date</span><strong><?= e(date('d/m/Y H:i', strtotime($operation['effective_at']))) ?></strong></div><div><span>Compte</span><strong><?= e($operation['account_name']) ?><?= $operation['destination_account_name'] ? ' → ' . e($operation['destination_account_name']) : '' ?></strong></div><div><span>Catégorie</span><strong><?= e($operation['category_name'] ?? 'Hors résultat') ?></strong></div><div><span>Référence vente</span><strong><?= e($operation['order_ref'] ?? '—') ?></strong></div><div><span>Contrepartie</span><strong><?= e($operation['counterparty'] ?? '—') ?></strong></div><div><span>Référence paiement</span><strong><?= e($operation['payment_reference'] ?? '—') ?></strong></div></section>
<?php if ($operation['note']): ?><section class="admin-panel accounting-section"><p class="admin-kicker">Note</p><p class="admin-copy"><?= nl2br(e($operation['note'])) ?></p></section><?php endif; ?>
<section class="admin-panel accounting-section"><p class="admin-kicker">Ventilation analytique</p><h2>Ce que cette écriture représente.</h2><?php if ($detail['allocations'] === []): ?><p class="admin-copy">Aucune ventilation : transfert ou écriture hors résultat.</p><?php else: ?><div class="allocation-list"><?php foreach ($detail['allocations'] as $allocation): ?><div><span><strong><?= e($allocation['product_name'] ?? 'Boutique') ?></strong><small><?= e($allocation['treatment']) ?><?= $allocation['order_ref'] ? ' · ' . e($allocation['order_ref']) : '' ?><?= $allocation['sale_ref'] ? ' · ' . e($allocation['sale_ref']) : '' ?></small></span><span><strong><?= money($allocation['amount_fcfa']) ?></strong><?php if ((int) $allocation['cogs_amount_fcfa'] > 0): ?><small>CMV <?= money($allocation['cogs_amount_fcfa']) ?></small><?php endif; ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
<section class="admin-grid accounting-operation-grid"><article class="admin-panel"><p class="admin-kicker">Pièces justificatives</p><h2>Ajouter une pièce.</h2><form class="accounting-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="operation_id" value="<?= (int) $operationId ?>"><input type="hidden" name="action" value="attachment"><label class="wide">PDF, JPEG, PNG ou WebP · 10 Mo maximum<input type="file" name="attachment" accept="application/pdf,image/jpeg,image/png,image/webp" required></label><button class="admin-button">Joindre la pièce</button></form><div class="attachment-list"><?php foreach ($detail['attachments'] as $attachment): ?><a href="<?= e(url('/admin/accounting-download.php?attachment=' . (int) $attachment['id'])) ?>"><span><?= e($attachment['original_name']) ?></span><small><?= e($attachment['mime_type']) ?> · <?= number_format((int) $attachment['size_bytes'] / 1024, 0, ',', ' ') ?> Ko</small></a><?php endforeach; ?><?php if ($detail['attachments'] === []): ?><p class="admin-copy">Aucune pièce jointe.</p><?php endif; ?></div></article>
<article class="admin-panel"><p class="admin-kicker"><?= $operation['status'] === 'draft' ? 'Brouillon' : 'Correction' ?></p><h2><?= $operation['status'] === 'draft' ? 'Confirmer ce décaissement.' : 'Contrepasser, sans effacer.' ?></h2><?php if ($operation['status'] === 'draft' && $operation['nature'] === 'disbursement' && $operation['source_type'] === 'manual'): ?><form class="accounting-form" method="post"><?= csrf_field() ?><input type="hidden" name="operation_id" value="<?= (int) $operationId ?>"><input type="hidden" name="action" value="confirm_draft"><input type="hidden" name="allow_negative_balance" value="0"><label class="accounting-check wide"><input type="checkbox" name="allow_negative_balance" value="1"><span>Je confirme l’exception de solde négatif si elle est réelle</span></label><label class="wide">Confirmation de l’exception<input name="negative_balance_acknowledgement" maxlength="500"></label><button class="admin-button">Confirmer le brouillon</button></form><?php elseif ($operation['status'] !== 'confirmed' || $operation['reversal_of_id'] !== null || $operation['reversal_id'] !== null): ?><p class="admin-copy">Cette écriture ne peut pas être contrepassée depuis ici.</p><?php else: ?><form class="accounting-form" method="post"><?= csrf_field() ?><input type="hidden" name="operation_id" value="<?= (int) $operationId ?>"><input type="hidden" name="action" value="reverse"><input type="hidden" name="idempotency_key" value="<?= e(accounting_new_uuid()) ?>"><label>Date de contrepassation<input type="datetime-local" name="effective_at" value="<?= e($now) ?>" required></label><label class="wide">Motif<input name="reason" maxlength="1000" required placeholder="Ex. paiement saisi sur le mauvais compte"></label><button class="admin-button accounting-danger">Créer la contrepassation</button></form><?php endif; ?></article></section>
<?php if ($operation['status'] === 'confirmed' && $operation['nature'] === 'receipt' && $operation['source_type'] === 'order' && $operation['reversal_of_id'] === null && $operation['reversal_id'] === null): ?>
<section class="admin-panel accounting-section"><p class="admin-kicker">Correction de date</p><h2>Réémettre cet encaissement à la bonne date.</h2><p class="admin-copy">L’écriture d’origine sera contrepassée sur sa date actuelle, puis recréée à la date corrigée avec les mêmes montants, ventilations et coûts historiques.</p><form class="accounting-form" method="post"><?= csrf_field() ?><input type="hidden" name="operation_id" value="<?= (int) $operationId ?>"><input type="hidden" name="action" value="reissue_date"><input type="hidden" name="reversal_idempotency_key" value="<?= e(accounting_new_uuid()) ?>"><input type="hidden" name="replacement_idempotency_key" value="<?= e(accounting_new_uuid()) ?>"><label>Date corrigée<input type="datetime-local" name="effective_at" value="<?= e(date('Y-m-d\TH:i', strtotime($operation['effective_at']))) ?>" required></label><label class="wide">Motif de correction<input name="reason" maxlength="1000" required placeholder="Ex. encaissement réellement reçu la veille"></label><button class="admin-button">Corriger la date avec traçabilité</button></form></section>
<?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
