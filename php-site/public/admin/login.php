<?php
require __DIR__ . '/../../app/bootstrap.php';
if (is_admin()) redirect('/admin/');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $attempt = $_SESSION['login_attempt'] ?? ['count' => 0, 'until' => 0];
    if ($attempt['until'] > time()) flash('error', 'Trop de tentatives. Réessayez dans quelques minutes.');
    elseif (admin_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) { unset($_SESSION['login_attempt']); redirect('/admin/'); }
    else { $count = $attempt['count'] + 1; $_SESSION['login_attempt'] = ['count' => $count, 'until' => $count >= 5 ? time() + 900 : 0]; flash('error', 'Identifiants invalides.'); }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion · L’Horloger</title><link rel="icon" type="image/png" sizes="192x192" href="<?= e(url('/favicon-192.png')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>"></head><body class="admin-login"><section class="login-card"><p class="admin-kicker">L’Horloger · Administration</p><h1>Piloter la boutique.</h1><p style="color:#60718a;line-height:1.5">Connectez-vous pour suivre les commandes, le stock et la rentabilité.</p><?php if ($message=flash('error')): ?><p class="flash flash-error"><?= e($message) ?></p><?php endif; ?><form method="post"><?= csrf_field() ?><label>Identifiant<input name="username" autocomplete="username" required autofocus></label><label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label><button>Se connecter</button></form></section></body></html>
