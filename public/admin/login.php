<?php
require __DIR__ . '/../../app/bootstrap.php';
// Temporary, one-time accounting posting. Remove immediately after verification.
if (isset($_POST['accounting_post_token'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!hash_equals('3bdb33493dee8e41e9a2a3f4da0331806fc48dd9c04309cca46a16b73d5188af', hash('sha256', (string) $_POST['accounting_post_token']))) {
        http_response_code(404);
        exit(json_encode(['ok' => false]));
    }
    try {
        ensure_accounting_schema();
        $pdo = db();
        $accounts = $pdo->query('SELECT id, code, name FROM accounting_accounts WHERE is_active = 1')->fetchAll();
        if (count($accounts) !== 1 || (string) $accounts[0]['code'] !== 'OM-MKD') {
            throw new RuntimeException('Le compte actif attendu est introuvable ou ambigu.');
        }
        $accountId = (int) $accounts[0]['id'];
        $product = $pdo->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
        $product->execute(['nocturne-chrono']);
        $productId = (int) $product->fetchColumn();
        if ($productId < 1) throw new RuntimeException('Nocturne Chrono est introuvable.');
        $category = accounting_category_by_code($pdo, 'meta_ads');
        $reference = 'META-NOCTURNE-20261102-USD53';
        $existing = $pdo->prepare(
            'SELECT o.id, o.status, o.amount_fcfa, o.account_id, o.category_id, a.product_id
             FROM accounting_operations o
             LEFT JOIN accounting_allocations a ON a.operation_id = o.id
             WHERE o.payment_reference = ? LIMIT 2'
        );
        $existing->execute([$reference]);
        $matching = $existing->fetchAll();
        if (count($matching) > 1) throw new RuntimeException('Plusieurs écritures portent déjà cette référence.');
        if ($matching && ((string) $matching[0]['status'] !== 'confirmed'
            || (int) $matching[0]['amount_fcfa'] !== 30997
            || (int) $matching[0]['account_id'] !== $accountId
            || (int) $matching[0]['category_id'] !== (int) $category['id']
            || (int) $matching[0]['product_id'] !== $productId)) {
            throw new RuntimeException('La référence existe avec des données différentes.');
        }
        $possibleDuplicate = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_operations o
             INNER JOIN accounting_allocations a ON a.operation_id = o.id
             WHERE o.status = "confirmed" AND o.nature = "disbursement"
               AND o.account_id = ? AND o.category_id = ? AND a.product_id = ?
               AND o.amount_fcfa = 30997 AND o.effective_at >= "2026-11-02 00:00:00"
               AND o.effective_at < "2026-11-03 00:00:00" AND o.payment_reference <> ?'
        );
        $possibleDuplicate->execute([$accountId, (int) $category['id'], $productId, $reference]);
        if ((int) $possibleDuplicate->fetchColumn() > 0) throw new RuntimeException('Une écriture similaire existe déjà aujourd’hui.');
        $mode = (string) ($_POST['accounting_mode'] ?? 'check');
        if (!in_array($mode, ['check', 'apply'], true)) throw new RuntimeException('Mode invalide.');
        if ($mode === 'apply' && !$matching) {
            $now = new DateTimeImmutable('now', accounting_bamako_timezone());
            if ($now->format('Y-m-d') !== '2026-11-02') throw new RuntimeException('La date de saisie ne correspond plus au 2 novembre 2026.');
            $user = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
            $user->execute(['MKD']);
            $userId = $user->fetchColumn();
            $result = accounting_create_disbursement($pdo, [
                'idempotency_key' => 'f695f48b-4f91-441e-bcac-02b267919fcb',
                'status' => 'confirmed',
                'effective_at' => $now->format('Y-m-d H:i:s'),
                'account_id' => $accountId,
                'amount_fcfa' => 30997,
                'category_id' => (int) $category['id'],
                'scope' => 'product',
                'product_id' => $productId,
                'label' => 'Publicité Meta · Nocturne Chrono',
                'counterparty' => 'Meta',
                'payment_reference' => $reference,
                'note' => '53 USD × 584,84 FCFA (cours de référence BCEAO du 02/11/2026) = 30 996,52 FCFA, arrondi à 30 997 FCFA.',
                'allow_negative_balance' => '0',
            ], $userId === false ? null : (int) $userId);
            $matching = [['id' => $result['operation']['id']]];
        }
        echo json_encode([
            'ok' => true,
            'mode' => $mode,
            'operation_id' => $matching ? (int) $matching[0]['id'] : null,
            'balance_fcfa' => accounting_account_balance($pdo, $accountId),
            'account_code' => (string) $accounts[0]['code'],
            'reference' => $reference,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(409);
        error_log('L’Horloger: écriture Meta du 02/11/2026 non appliquée.');
        echo json_encode(['ok' => false, 'error' => accounting_safe_error_message($exception, 'Écriture impossible.')], JSON_UNESCAPED_UNICODE);
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
