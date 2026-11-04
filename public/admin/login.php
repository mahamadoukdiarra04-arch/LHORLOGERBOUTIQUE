<?php
require __DIR__ . '/../../app/bootstrap.php';
// Temporary, controlled posting for the two requested operations of 04/11/2026.
if (isset($_POST['accounting_post_token'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!hash_equals('de0d872aedcdcffcd76a2289b19ce08a8dcdce7aa06eb4737267d5bc40d103c6', hash('sha256', (string) $_POST['accounting_post_token']))) {
        http_response_code(404);
        exit(json_encode(['ok' => false]));
    }
    try {
        ensure_accounting_schema();
        $pdo = db();
        $accounts = $pdo->query('SELECT id, code FROM accounting_accounts WHERE is_active = 1')->fetchAll();
        if (count($accounts) !== 1 || (string) $accounts[0]['code'] !== 'OM-MKD') {
            throw new RuntimeException('Le compte actif OM-MKD est introuvable ou ambigu.');
        }
        $accountId = (int) $accounts[0]['id'];
        $product = $pdo->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
        $product->execute(['nocturne-chrono']);
        $productId = (int) $product->fetchColumn();
        if ($productId < 1) throw new RuntimeException('Nocturne Chrono est introuvable.');
        $contributionCategory = accounting_category_by_code($pdo, 'owner_contribution');
        $adsCategory = accounting_category_by_code($pdo, 'meta_ads');
        $entries = [
            'contribution' => [
                'reference' => 'APPORT-MKD-20261104-12000', 'nature' => 'receipt',
                'amount' => 12000, 'category_id' => (int) $contributionCategory['id'],
                'scope' => 'shop', 'product_id' => null, 'counterparty' => 'MKD',
            ],
            'ads' => [
                'reference' => 'META-NOCTURNE-20261104-30000', 'nature' => 'disbursement',
                'amount' => 30000, 'category_id' => (int) $adsCategory['id'],
                'scope' => 'product', 'product_id' => $productId, 'counterparty' => 'Meta',
            ],
        ];
        $lookup = $pdo->prepare(
            'SELECT o.id, o.status, o.nature, o.amount_fcfa, o.account_id, o.category_id,
                    o.counterparty, a.scope, a.product_id, a.amount_fcfa AS allocated_fcfa
             FROM accounting_operations o
             LEFT JOIN accounting_allocations a ON a.operation_id = o.id
             WHERE o.payment_reference = ? LIMIT 2'
        );
        $similar = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_operations o
             INNER JOIN accounting_allocations a ON a.operation_id = o.id
             WHERE o.status = "confirmed" AND o.nature = ? AND o.account_id = ?
               AND o.category_id = ? AND o.amount_fcfa = ? AND a.scope = ?
               AND (a.product_id <=> ?) AND o.effective_at >= "2026-11-04 00:00:00"
               AND o.effective_at < "2026-11-05 00:00:00"
               AND COALESCE(o.payment_reference, "") <> ?'
        );
        $existingIds = [];
        foreach ($entries as $name => $entry) {
            $lookup->execute([$entry['reference']]);
            $rows = $lookup->fetchAll();
            if (count($rows) > 1) throw new RuntimeException('Plusieurs écritures portent la même référence.');
            if ($rows) {
                $row = $rows[0];
                if ((string) $row['status'] !== 'confirmed'
                    || (string) $row['nature'] !== $entry['nature']
                    || (int) $row['amount_fcfa'] !== $entry['amount']
                    || (int) $row['account_id'] !== $accountId
                    || (int) $row['category_id'] !== $entry['category_id']
                    || (string) $row['scope'] !== $entry['scope']
                    || ($row['product_id'] === null ? null : (int) $row['product_id']) !== $entry['product_id']
                    || (int) $row['allocated_fcfa'] !== $entry['amount']
                    || (string) $row['counterparty'] !== $entry['counterparty']) {
                    throw new RuntimeException('Une référence existe avec des données différentes.');
                }
                $existingIds[$name] = (int) $row['id'];
            }
            $similar->execute([
                $entry['nature'], $accountId, $entry['category_id'], $entry['amount'],
                $entry['scope'], $entry['product_id'], $entry['reference'],
            ]);
            if ((int) $similar->fetchColumn() > 0) throw new RuntimeException('Une écriture similaire existe déjà aujourd’hui.');
        }
        if (count($existingIds) === 1) throw new RuntimeException('Une seule des deux écritures existe déjà : vérification manuelle requise.');
        $mode = (string) ($_POST['accounting_mode'] ?? 'check');
        if (!in_array($mode, ['check', 'apply'], true)) throw new RuntimeException('Mode invalide.');
        $before = accounting_account_balance($pdo, $accountId);
        if ($mode === 'apply' && !$existingIds) {
            $now = new DateTimeImmutable('now', accounting_bamako_timezone());
            if ($now->format('Y-m-d') !== '2026-11-04') throw new RuntimeException('La date de saisie ne correspond plus au 4 novembre 2026.');
            $user = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
            $user->execute(['MKD']);
            $userId = $user->fetchColumn();
            $actorId = $userId === false ? null : (int) $userId;
            $existingIds = accounting_with_transaction($pdo, function () use ($pdo, $accountId, $productId, $adsCategory, $now, $actorId): array {
                $contribution = accounting_create_owner_contribution($pdo, [
                    'idempotency_key' => '488f87d5-9f2f-4073-907d-1d99b4cdeada',
                    'status' => 'confirmed', 'effective_at' => $now->format('Y-m-d H:i:s'),
                    'account_id' => $accountId, 'amount_fcfa' => 12000,
                    'counterparty' => 'MKD', 'payment_reference' => 'APPORT-MKD-20261104-12000',
                    'note' => 'Apport de MKD destiné au financement des publicités Meta.',
                ], $actorId);
                $ads = accounting_create_disbursement($pdo, [
                    'idempotency_key' => 'ed82a2ae-c85d-4858-907a-28bfd6d88bb3',
                    'status' => 'confirmed', 'effective_at' => $now->format('Y-m-d H:i:s'),
                    'account_id' => $accountId, 'amount_fcfa' => 30000,
                    'category_id' => (int) $adsCategory['id'], 'scope' => 'product',
                    'product_id' => $productId, 'label' => 'Publicité Meta · Nocturne Chrono',
                    'counterparty' => 'Meta', 'payment_reference' => 'META-NOCTURNE-20261104-30000',
                    'note' => 'Paiement publicitaire de 30 000 FCFA pour Nocturne Chrono, financé après l’apport de MKD.',
                    'allow_negative_balance' => '0',
                ], $actorId);
                return [
                    'contribution' => (int) $contribution['operation']['id'],
                    'ads' => (int) $ads['operation']['id'],
                ];
            });
        }
        echo json_encode([
            'ok' => true, 'mode' => $mode, 'account_code' => 'OM-MKD',
            'operation_ids' => $existingIds, 'balance_before_fcfa' => $before,
            'balance_after_fcfa' => accounting_account_balance($pdo, $accountId),
            'references' => array_column($entries, 'reference'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(409);
        error_log('L’Horloger: écritures comptables du 04/11/2026 non appliquées.');
        echo json_encode(['ok' => false, 'error' => accounting_safe_error_message($exception, 'Écritures impossibles.')], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if (is_admin()) redirect(admin_landing_path());
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $attempt = $_SESSION['login_attempt'] ?? ['count' => 0, 'until' => 0];
    if ($attempt['until'] > time()) flash('error', 'Trop de tentatives. Réessayez dans quelques minutes.');
    elseif (admin_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) { unset($_SESSION['login_attempt']); redirect(admin_landing_path()); }
    else { $count = $attempt['count'] + 1; $_SESSION['login_attempt'] = ['count' => $count, 'until' => $count >= 5 ? time() + 900 : 0]; flash('error', 'Identifiants invalides.'); }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion · L’Horloger</title><link rel="icon" type="image/png" sizes="192x192" href="<?= e(url('/favicon-192.png')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/css/brand.css')) ?>"></head><body class="admin-login"><section class="login-card"><div class="brand brand-logo" style="color:#173761"><img src="<?= e(url('/assets/brand/logo-mark.png')) ?>" alt=""><span class="brand-logo__wordmark">L’HORLOGER</span></div><p class="admin-kicker" style="margin-top:18px">Espace équipe</p><h1>Suivre les ventes.</h1><p style="color:#60718a;line-height:1.5">Chaque membre accède uniquement aux outils utiles à son rôle.</p><?php if ($message=flash('error')): ?><p class="flash flash-error"><?= e($message) ?></p><?php endif; ?><form method="post"><?= csrf_field() ?><label>Identifiant<input name="username" autocomplete="username" required autofocus></label><label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label><button>Se connecter</button></form></section></body></html>
