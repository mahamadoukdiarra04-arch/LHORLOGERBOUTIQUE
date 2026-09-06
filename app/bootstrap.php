<?php
declare(strict_types=1);

const APP_ROOT = __DIR__;

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Configuration requise. Créez app/config.php à partir de app/config.example.php.');
}

$config = require $configFile;
if (!is_array($config) || empty($config['db']) || empty($config['admin_users']) || empty($config['app_key'])) {
    http_response_code(503);
    exit('Configuration invalide.');
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('lhorloger_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $db = $config['db'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/**
 * The first version of the admin included opening movements for demonstration.
 * They are not business data and must never be counted as real inventory.
 */
function remove_placeholder_initial_stock(): void {
    static $completed = false;
    if ($completed) return;
    $completed = true;

    try {
        $statement = db()->prepare(
            'DELETE FROM stock_movements
             WHERE note = ? AND actor = ? AND (
                 (product_id = 1 AND quantity = 18)
                 OR (product_id = 2 AND quantity = 8)
                 OR (product_id = 3 AND quantity = 4)
             )'
        );
        $statement->execute(['Stock initial', 'Système']);
    } catch (Throwable) {
        // The cleanup is only relevant once the inventory tables exist.
    }
}

function e(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function money(float|int|null $value): string {
    return $value === null ? 'À renseigner' : number_format(round($value), 0, ',', ' ') . ' FCFA';
}
function url(string $path = ''): string { global $config; return rtrim((string) ($config['base_url'] ?? ''), '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('La demande a expiré. Rechargez la page puis réessayez.');
    }
}
function flash(string $key, ?string $message = null): ?string {
    if ($message !== null) { $_SESSION['flash'][$key] = $message; return null; }
    $value = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $value;
}
function configured_admin_users(): array {
    global $config;
    $users = [];
    foreach ((array) ($config['admin_users'] ?? []) as $username => $entry) {
        $identity = strtoupper(trim((string) $username));
        $hash = is_array($entry) ? ($entry['password_hash'] ?? $entry['hash'] ?? null) : $entry;
        $role = is_array($entry) ? (string) ($entry['role'] ?? 'manager') : 'manager';
        if ($identity !== '' && is_string($hash) && in_array($role, ['manager', 'closer'], true)) {
            $users[$identity] = ['password_hash' => $hash, 'role' => $role];
        }
    }
    return $users;
}
function ensure_admin_users_schema(): void {
    static $ready = false;
    if ($ready) return;
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('manager','closer') NOT NULL DEFAULT 'manager',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_admin_users_active (is_active, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $seed = $pdo->prepare(
        'INSERT IGNORE INTO admin_users (username, password_hash, role, is_active, created_by) VALUES (?, ?, ?, 1, ?)'
    );
    foreach (configured_admin_users() as $username => $user) {
        $seed->execute([$username, $user['password_hash'], $user['role'], 'Configuration']);
    }
    $ready = true;
}
function admin_session_is_active(): bool {
    static $checked = null;
    if ($checked !== null) return $checked;
    $checked = true;
    $identity = (string) ($_SESSION['admin_identity'] ?? '');
    if ($identity === '') return false;
    try {
        ensure_admin_users_schema();
        $userId = (int) ($_SESSION['admin_user_id'] ?? 0);
        $statement = $userId > 0
            ? db()->prepare('SELECT id, username, role, is_active FROM admin_users WHERE id = ?')
            : db()->prepare('SELECT id, username, role, is_active FROM admin_users WHERE username = ?');
        $statement->execute([$userId > 0 ? $userId : $identity]);
        $user = $statement->fetch();
        if (!$user) return $checked = false;
        if (!(bool) $user['is_active']) return $checked = false;
        $_SESSION['admin_user_id'] = (int) $user['id'];
        $_SESSION['admin_identity'] = (string) $user['username'];
        $_SESSION['admin_role'] = (string) $user['role'];
    } catch (Throwable) {
        // A pre-existing configuration account remains usable if MySQL is temporarily unavailable.
        $legacy = configured_admin_users()[$identity] ?? null;
        $checked = $legacy !== null;
    }
    return $checked;
}
function is_admin(): bool {
    return isset($_SESSION['admin_identity'], $_SESSION['admin_expires'])
        && $_SESSION['admin_expires'] > time()
        && admin_session_is_active();
}
function admin_role(): string { return (string) ($_SESSION['admin_role'] ?? 'manager'); }
function is_manager(): bool { return is_admin() && admin_role() === 'manager'; }
function is_closer(): bool { return is_admin() && admin_role() === 'closer'; }
function admin_landing_path(): string { return is_closer() ? '/closer/' : '/admin/'; }
function require_admin(): void {
    if (!is_admin()) redirect('/admin/login.php');
    remove_placeholder_initial_stock();
}
function require_manager(): void {
    require_admin();
    if (!is_manager()) redirect('/closer/');
}
function require_closer(): void {
    require_admin();
    if (!is_closer()) redirect('/admin/closer.php');
}
function admin_identity(): string { return (string) ($_SESSION['admin_identity'] ?? ''); }
function admin_login(string $username, string $password): bool {
    $identity = strtoupper(trim($username));
    $account = null;
    try {
        ensure_admin_users_schema();
        $statement = db()->prepare('SELECT id, username, password_hash, role, is_active FROM admin_users WHERE username = ? LIMIT 1');
        $statement->execute([$identity]);
        $account = $statement->fetch();
    } catch (Throwable) {
        $legacy = configured_admin_users()[$identity] ?? null;
        if ($legacy) $account = ['id' => null, 'username' => $identity, 'password_hash' => $legacy['password_hash'], 'role' => $legacy['role'], 'is_active' => 1];
    }
    if (!$account || !(bool) $account['is_active'] || !password_verify($password, (string) $account['password_hash'])) return false;
    $role = (string) $account['role'];
    if (!in_array($role, ['manager', 'closer'], true)) return false;
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = $account['id'] !== null ? (int) $account['id'] : null;
    $_SESSION['admin_identity'] = (string) $account['username'];
    $_SESSION['admin_role'] = $role;
    $_SESSION['admin_expires'] = time() + 12 * 60 * 60;
    return true;
}
function admin_logout(): void { $_SESSION = []; session_destroy(); }
function allowed_period(): array {
    $key = (string) ($_GET['period'] ?? '30');
    $ranges = [
        'today' => ['Aujourd’hui', date('Y-m-d'), date('Y-m-d')],
        '7' => ['7 derniers jours', date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
        '14' => ['14 derniers jours', date('Y-m-d', strtotime('-13 days')), date('Y-m-d')],
        '30' => ['30 derniers jours', date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
        '90' => ['90 derniers jours', date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
        'month' => ['Ce mois-ci', date('Y-m-01'), date('Y-m-d')],
        'quarter' => ['Ce trimestre', date('Y-m-d', strtotime(date('Y-m-01') . ' -2 months')), date('Y-m-d')],
        'year' => ['Depuis le 1er janvier', date('Y-01-01'), date('Y-m-d')],
    ];
    if ($key === 'custom') {
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['start'] ?? '')) ? $_GET['start'] : date('Y-m-01');
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['end'] ?? '')) ? $_GET['end'] : date('Y-m-d');
        return ['custom', 'Période personnalisée', $start, $end];
    }
    [$label, $start, $end] = $ranges[$key] ?? $ranges['30'];
    return [$key, $label, $start, $end];
}
function log_event(string $type, string $message, ?int $productId = null, ?int $orderId = null): void {
    $stmt = db()->prepare('INSERT INTO admin_events (event_type, message, product_id, order_id, actor) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$type, $message, $productId, $orderId, admin_identity() ?: null]);
}
function ensure_closer_schema(): void {
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $schemaVersion = '20260906_whatsapp_delivery';
    // The closer opens this page many times a day. The version flag avoids DDL
    // checks after this migration has been applied once.
    try {
        $versionStatement = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'closer_schema_version'");
        $versionStatement->execute();
        if ((string) $versionStatement->fetchColumn() === $schemaVersion) {
            $pdo->query('SELECT 1 FROM closer_delivery_batches LIMIT 1');
            $pdo->query('SELECT 1 FROM closer_delivery_batch_orders LIMIT 1');
            $statusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch();
            $sentColumn = $pdo->query("SHOW COLUMNS FROM order_closer_tracking LIKE 'whatsapp_sent_at'")->fetch();
            if ($sentColumn && $statusColumn && !str_contains((string) ($statusColumn['Type'] ?? ''), 'Injoignable')) {
                $ready = true;
                return;
            }
        }
    } catch (PDOException $exception) {
        // Only a missing table requires the idempotent creation below.
        if ((string) $exception->getCode() !== '42S02') throw $exception;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_closer_tracking (
            order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            closer_identity VARCHAR(50) NOT NULL,
            follow_up_status VARCHAR(32) NOT NULL DEFAULT 'À appeler',
            follow_up_at DATETIME NULL,
            note TEXT NULL,
            whatsapp_prepared_at DATETIME NULL,
            whatsapp_sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_closer_status (closer_identity, follow_up_status),
            INDEX idx_closer_follow_up (follow_up_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS closer_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            closer_identity VARCHAR(50) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            note VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_closer_events_order (order_id, created_at),
            INDEX idx_closer_events_identity (closer_identity, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS closer_delivery_batches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            closer_identity VARCHAR(50) NOT NULL,
            draft_owner VARCHAR(50) NULL,
            delivery_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            downloaded_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_closer_delivery_draft (draft_owner),
            INDEX idx_closer_delivery_history (closer_identity, status, downloaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS closer_delivery_batch_orders (
            batch_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (batch_id, order_id),
            UNIQUE KEY uq_closer_delivery_order (order_id),
            CONSTRAINT fk_closer_delivery_batch FOREIGN KEY (batch_id) REFERENCES closer_delivery_batches(id) ON DELETE CASCADE,
            CONSTRAINT fk_closer_delivery_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $sentColumn = $pdo->query("SHOW COLUMNS FROM order_closer_tracking LIKE 'whatsapp_sent_at'")->fetch();
    if (!$sentColumn) {
        $pdo->exec('ALTER TABLE order_closer_tracking ADD COLUMN whatsapp_sent_at DATETIME NULL AFTER whatsapp_prepared_at');
    }
    // Les anciens brouillons restent conservés pour l'historique mais ne sont
    // plus actifs depuis le remplacement des bordereaux par les messages.
    $pdo->exec("UPDATE closer_delivery_batches SET status = 'replaced', draft_owner = NULL WHERE status = 'draft'");
    $statusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch();
    if ($statusColumn && str_contains((string) ($statusColumn['Type'] ?? ''), 'Injoignable')) {
        // "Injoignable" is a call outcome, not a commercial order state.
        // Existing rows created before this rule are safely normalized first.
        $pdo->exec("UPDATE orders SET status = 'Annulée' WHERE status = 'Injoignable'");
        $pdo->exec(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('À confirmer','Confirmée','En livraison','Livrée','Annulée')
             NOT NULL DEFAULT 'À confirmer'"
        );
    }
    $pdo->exec(
        "DELETE batch_item
         FROM closer_delivery_batch_orders batch_item
         JOIN closer_delivery_batches batch ON batch.id = batch_item.batch_id
         JOIN orders o ON o.id = batch_item.order_id
         WHERE o.status IN ('Annulée', 'Livrée')
           AND batch.status = 'draft'"
    );
    $versionStatement = $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES ('closer_schema_version', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $versionStatement->execute([$schemaVersion]);
    $ready = true;
}

/** Return the unique open delivery sheet for a closer, creating it if needed. */
function closer_delivery_draft(PDO $pdo, string $closer, bool $forUpdate = false): array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $select = $pdo->prepare("SELECT * FROM closer_delivery_batches WHERE draft_owner = ? AND status = 'draft' LIMIT 1" . $lock);
    $select->execute([$closer]);
    $batch = $select->fetch();
    if ($batch) return $batch;

    $insert = $pdo->prepare(
        "INSERT INTO closer_delivery_batches (closer_identity, draft_owner, status)
         VALUES (?, ?, 'draft')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = updated_at"
    );
    $insert->execute([$closer, $closer]);
    $select->execute([$closer]);
    $batch = $select->fetch();
    if (!$batch) throw new RuntimeException('Le bordereau en cours ne peut pas être préparé.');
    return $batch;
}

function closer_delivery_order_ids(PDO $pdo, int $batchId): array {
    $statement = $pdo->prepare('SELECT order_id FROM closer_delivery_batch_orders WHERE batch_id = ? ORDER BY added_at, order_id');
    $statement->execute([$batchId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function closer_safe_error_message(Throwable $exception): string {
    if ($exception instanceof PDOException || str_contains($exception->getMessage(), 'SQLSTATE')) {
        return 'La connexion est momentanément indisponible. Votre action n’a pas été enregistrée. Réessayez dans quelques instants.';
    }
    return $exception->getMessage();
}
function app_setting(string $key, ?string $default = null): ?string {
    $statement = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string) $value;
}
function save_app_setting(string $key, string $value): void {
    $statement = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([$key, $value]);
}
function log_closer_event(int $orderId, string $type, ?string $note = null): void {
    $statement = db()->prepare(
        'INSERT INTO closer_events (order_id, closer_identity, event_type, note) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$orderId, admin_identity(), $type, $note]);
}

/**
 * Keep the operational follow-up aligned with the order status shared by
 * management and the closer. Active call states all represent an order that
 * is still "À confirmer"; terminal states must never remain in an active list.
 */
function sync_closer_tracking_for_order_ref(PDO $pdo, string $orderRef): void {
    $statement = $pdo->prepare(
        "UPDATE order_closer_tracking t
         JOIN orders o ON o.id = t.order_id
         SET t.follow_up_status = CASE
             WHEN o.status = 'Annulée' AND t.follow_up_status = 'Injoignable' THEN 'Injoignable'
             WHEN o.status = 'Annulée' THEN 'Annulée'
             WHEN o.status = 'Livrée' THEN 'Livrée'
             WHEN o.status IN ('Confirmée', 'En livraison') THEN 'Confirmée'
             WHEN o.status = 'À confirmer' AND t.follow_up_status IN ('Confirmée', 'Annulée', 'Injoignable', 'Livrée') THEN 'À appeler'
             ELSE t.follow_up_status
         END,
         t.follow_up_at = CASE
             WHEN o.status IN ('Annulée', 'Livrée') THEN NULL
             ELSE t.follow_up_at
         END
         WHERE o.order_ref = ?
           AND (
             (o.status = 'Annulée' AND t.follow_up_status NOT IN ('Annulée', 'Injoignable'))
             OR (o.status = 'Livrée' AND t.follow_up_status <> 'Livrée')
             OR (o.status IN ('Confirmée', 'En livraison') AND t.follow_up_status <> 'Confirmée')
             OR (o.status = 'À confirmer' AND t.follow_up_status IN ('Confirmée', 'Annulée', 'Injoignable', 'Livrée'))
             OR (o.status IN ('Annulée', 'Livrée') AND t.follow_up_at IS NOT NULL)
           )"
    );
    $statement->execute([$orderRef]);

    // A terminal order must not remain inside a draft that has not yet been
    // downloaded. This keeps the counter and the generated PDF in sync even
    // when management changes the status from its own interface.
    $removeFromDraft = $pdo->prepare(
        "DELETE batch_item
         FROM closer_delivery_batch_orders batch_item
         JOIN closer_delivery_batches batch ON batch.id = batch_item.batch_id
         JOIN orders o ON o.id = batch_item.order_id
         WHERE o.order_ref = ?
           AND o.status IN ('Annulée', 'Livrée')
           AND batch.status = 'draft'"
    );
    $removeFromDraft->execute([$orderRef]);
}

function sync_all_closer_tracking(PDO $pdo): void {
    $statement = $pdo->prepare(
        "UPDATE order_closer_tracking t
         JOIN orders o ON o.id = t.order_id
         SET t.follow_up_status = CASE
             WHEN o.status = 'Annulée' AND t.follow_up_status = 'Injoignable' THEN 'Injoignable'
             WHEN o.status = 'Annulée' THEN 'Annulée'
             WHEN o.status = 'Livrée' THEN 'Livrée'
             WHEN o.status IN ('Confirmée', 'En livraison') THEN 'Confirmée'
             WHEN o.status = 'À confirmer' AND t.follow_up_status IN ('Confirmée', 'Annulée', 'Injoignable', 'Livrée') THEN 'À appeler'
             ELSE t.follow_up_status
         END,
         t.follow_up_at = CASE
             WHEN o.status IN ('Annulée', 'Livrée') THEN NULL
             ELSE t.follow_up_at
         END
         WHERE (o.status = 'Annulée' AND t.follow_up_status NOT IN ('Annulée', 'Injoignable'))
            OR (o.status = 'Livrée' AND t.follow_up_status <> 'Livrée')
            OR (o.status IN ('Confirmée', 'En livraison') AND t.follow_up_status <> 'Confirmée')
            OR (o.status = 'À confirmer' AND t.follow_up_status IN ('Confirmée', 'Annulée', 'Injoignable', 'Livrée'))
            OR (o.status IN ('Annulée', 'Livrée') AND t.follow_up_at IS NOT NULL)"
    );
    $statement->execute();

    $pdo->exec(
        "DELETE batch_item
         FROM closer_delivery_batch_orders batch_item
         JOIN closer_delivery_batches batch ON batch.id = batch_item.batch_id
         JOIN orders o ON o.id = batch_item.order_id
         WHERE o.status IN ('Annulée', 'Livrée')
           AND batch.status = 'draft'"
    );
}

// Accounting services are inert until a protected manager route invokes them.
// Loading the definitions here keeps all future admin entry points on the same
// server-side rules without initializing accounts or financial data.
require_once APP_ROOT . '/accounting.php';
