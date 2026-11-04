<?php
require __DIR__ . '/../../app/bootstrap.php';
if (isset($_GET['seed_t04'])) {
    $providedToken = (string) $_GET['seed_t04'];
    $expectedToken = '798a4d8d4fba4a27bf45d2c93c6d6c60';
    if (!hash_equals($expectedToken, $providedToken)) {
        http_response_code(404);
        exit;
    }
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $productStatement = $pdo->prepare(
            "INSERT INTO products (slug, sku, name, price_fcfa)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sku = VALUES(sku), name = VALUES(name), price_fcfa = VALUES(price_fcfa)"
        );
        $productStatement->execute(['carre-heritage', 'T-04', 'Carré Héritage', 56500]);
        $productIdStatement = $pdo->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
        $productIdStatement->execute(['carre-heritage']);
        $productId = (int) $productIdStatement->fetchColumn();
        if ($productId < 1) throw new RuntimeException('Le produit T-04 est introuvable après insertion.');
        $variantStatement = $pdo->prepare(
            "INSERT INTO product_variants (product_id, name, image_path, is_active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE image_path = VALUES(image_path), is_active = 1"
        );
        $variantStatement->execute([$productId, 'Noir & brun', 'products/carre-heritage/carre-heritage-bureau.webp']);
        $pdo->commit();
        header('X-LHorloger-Product-T04: applied');
        exit('Carré Héritage préparé.');
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('L’Horloger: préparation du produit T-04 impossible.');
        header('X-LHorloger-Product-T04: error');
        header('X-LHorloger-Product-T04-Error: ' . rtrim(strtr(base64_encode($exception->getMessage()), '+/', '-_'), '='));
        http_response_code(500);
        exit('Migration produit impossible.');
    }
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
