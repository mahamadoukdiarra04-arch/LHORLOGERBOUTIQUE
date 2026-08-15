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
function is_admin(): bool { return isset($_SESSION['admin_identity'], $_SESSION['admin_expires']) && $_SESSION['admin_expires'] > time(); }
function require_admin(): void {
    if (!is_admin()) redirect('/admin/login.php');
    remove_placeholder_initial_stock();
}
function admin_identity(): string { return (string) ($_SESSION['admin_identity'] ?? ''); }
function admin_login(string $username, string $password): bool {
    global $config;
    $identity = strtoupper(trim($username));
    $hash = $config['admin_users'][$identity] ?? null;
    if (!is_string($hash) || !password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['admin_identity'] = $identity;
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
