<?php
require __DIR__ . '/../../app/bootstrap.php';
require_manager();
ensure_admin_users_schema();
$pdo = db();
$roles = ['manager' => 'Gestionnaire', 'closer' => 'Closeuse'];

function access_username(string $value): string {
    return strtoupper(trim($value));
}
function access_password_is_valid(string $value): bool {
    return strlen($value) >= 10;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $username = access_username((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $username)) throw new RuntimeException('L’identifiant doit contenir 3 à 32 lettres, chiffres, tirets ou underscores.');
            if (!access_password_is_valid($password)) throw new RuntimeException('Le mot de passe doit contenir au moins 10 caractères.');
            if (!isset($roles[$role])) throw new RuntimeException('Rôle invalide.');
            $existing = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
            $existing->execute([$username]);
            if ($existing->fetchColumn()) throw new RuntimeException('Cet identifiant existe déjà.');
            $create = $pdo->prepare('INSERT INTO admin_users (username, password_hash, role, is_active, created_by) VALUES (?, ?, ?, 1, ?)');
            $create->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, admin_identity()]);
            log_event('accès', 'Compte créé · ' . $username . ' · ' . $roles[$role]);
            flash('success', 'Accès créé pour ' . $username . '.');
        } elseif ($action === 'update') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $username = access_username((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($userId < 1) throw new RuntimeException('Utilisateur invalide.');
            if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $username)) throw new RuntimeException('L’identifiant doit contenir 3 à 32 lettres, chiffres, tirets ou underscores.');
            if ($password !== '' && !access_password_is_valid($password)) throw new RuntimeException('Le nouveau mot de passe doit contenir au moins 10 caractères.');
            if (!isset($roles[$role])) throw new RuntimeException('Rôle invalide.');

            $pdo->beginTransaction();
            $currentStatement = $pdo->prepare('SELECT * FROM admin_users WHERE id = ? FOR UPDATE');
            $currentStatement->execute([$userId]);
            $current = $currentStatement->fetch();
            if (!$current) throw new RuntimeException('Utilisateur introuvable.');
            $duplicate = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? AND id <> ?');
            $duplicate->execute([$username, $userId]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('Cet identifiant est déjà utilisé.');

            $isSelf = (int) ($_SESSION['admin_user_id'] ?? 0) === $userId;
            if ($isSelf && (!$isActive || $role !== 'manager')) {
                throw new RuntimeException('Vous ne pouvez pas suspendre votre propre accès ni vous retirer le rôle gestionnaire.');
            }
            $removesManager = $current['role'] === 'manager' && (bool) $current['is_active'] && (!$isActive || $role !== 'manager');
            if ($removesManager) {
                $activeManagers = $pdo->query("SELECT id FROM admin_users WHERE is_active = 1 AND role = 'manager' FOR UPDATE")->fetchAll();
                if (count($activeManagers) < 2) throw new RuntimeException('Conservez au moins un gestionnaire actif.');
            }

            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $current['password_hash'];
            $update = $pdo->prepare('UPDATE admin_users SET username = ?, password_hash = ?, role = ?, is_active = ? WHERE id = ?');
            $update->execute([$username, $hash, $role, $isActive, $userId]);
            if ($isSelf) {
                $_SESSION['admin_identity'] = $username;
                $_SESSION['admin_role'] = $role;
            }
            log_event('accès', 'Compte modifié · ' . $username . ' · ' . $roles[$role] . ' · ' . ($isActive ? 'actif' : 'suspendu'));
            $pdo->commit();
            flash('success', 'Accès mis à jour pour ' . $username . '.');
        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $exception->getMessage());
    }
    redirect('/admin/users.php');
}

$users = $pdo->query('SELECT id, username, role, is_active, created_by, created_at, updated_at FROM admin_users ORDER BY is_active DESC, role ASC, username ASC')->fetchAll();
$adminPageTitle = 'Utilisateurs';
require APP_ROOT . '/templates/admin-header.php';
?>
<header class="admin-page-head">
  <div>
    <p class="admin-kicker">Accès équipe</p>
    <h1>Gérer les utilisateurs.</h1>
    <p>Créez l’accès de la closeuse ici, attribuez son rôle, puis suspendez un compte dès qu’il ne doit plus accéder à l’administration.</p>
  </div>
</header>

<section class="admin-grid">
  <article class="admin-panel">
    <p class="admin-kicker">Nouvel accès</p>
    <h2>Ajouter un utilisateur.</h2>
    <form class="data-form access-form" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label>Identifiant<input name="username" maxlength="32" pattern="[A-Za-z0-9_-]{3,32}" autocomplete="username" placeholder="Ex. AMINATA" required></label>
      <label>Rôle<select name="role"><option value="closer">Closeuse</option><option value="manager">Gestionnaire</option></select></label>
      <label class="wide">Mot de passe<input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
      <button class="admin-button" type="submit">Créer l’accès</button>
    </form>
  </article>
  <article class="admin-panel">
    <p class="admin-kicker">Droits</p>
    <h2>Deux interfaces, deux périmètres.</h2>
    <p class="admin-copy"><strong>Gestionnaire</strong> : administration complète, commandes, stock, analyse et suivi closeuse. <strong>Closeuse</strong> : seulement son espace d’appels, confirmations, WhatsApp et bordereaux PDF.</p>
    <p class="admin-copy">La suspension coupe les prochaines requêtes de l’utilisateur, même si sa session est encore ouverte.</p>
  </article>
</section>

<section class="admin-panel" style="margin-top:15px">
  <p class="admin-kicker">Comptes existants</p>
  <h2>Modifier ou suspendre un accès.</h2>
  <div class="user-grid">
    <?php foreach ($users as $user): $isSelf = (int) ($_SESSION['admin_user_id'] ?? 0) === (int) $user['id']; ?>
      <article class="user-card <?= !(bool) $user['is_active'] ? 'is-suspended' : '' ?>">
        <div class="user-card__head"><div><strong><?= e($user['username']) ?></strong><span><?= e($roles[$user['role']] ?? $user['role']) ?><?= $isSelf ? ' · Vous' : '' ?></span></div><span class="user-state <?= (bool) $user['is_active'] ? 'is-active' : '' ?>"><?= (bool) $user['is_active'] ? 'Actif' : 'Suspendu' ?></span></div>
        <form class="data-form user-card__form" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
          <label>Identifiant<input name="username" maxlength="32" pattern="[A-Za-z0-9_-]{3,32}" value="<?= e($user['username']) ?>" required></label>
          <label>Rôle<select name="role"><option value="manager" <?= $user['role'] === 'manager' ? 'selected' : '' ?>>Gestionnaire</option><option value="closer" <?= $user['role'] === 'closer' ? 'selected' : '' ?>>Closeuse</option></select></label>
          <label class="wide">Nouveau mot de passe <small>Laissez vide pour le conserver.</small><input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="Ne pas modifier"></label>
          <label class="user-toggle wide"><input type="checkbox" name="is_active" value="1" <?= (bool) $user['is_active'] ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>><span><?= $isSelf ? 'Votre compte reste actif' : 'Accès actif' ?></span></label>
          <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
          <button class="admin-button" type="submit">Enregistrer</button>
        </form>
        <small class="user-card__meta">Créé le <?= e(date('d/m/Y', strtotime($user['created_at']))) ?><?= $user['created_by'] ? ' · par ' . e($user['created_by']) : '' ?></small>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php require APP_ROOT . '/templates/admin-footer.php'; ?>
