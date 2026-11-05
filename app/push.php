<?php
declare(strict_types=1);

// Private server-side Web Push. No customer data is placed on the lock screen.
if (PHP_VERSION_ID < 80200) throw new RuntimeException('Les notifications nécessitent PHP 8.2 ou ultérieur.');
require_once dirname(__DIR__) . '/vendor/autoload.php';

function push_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_push_keys (id INT PRIMARY KEY, public_key VARCHAR(120) NOT NULL, private_key VARCHAR(120) NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_push_subscriptions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
        endpoint_hash CHAR(64) NOT NULL UNIQUE, subscription_json TEXT NOT NULL,
        active TINYINT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_push_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subscription_id BIGINT UNSIGNED NOT NULL,
        event_key VARCHAR(120) NOT NULL, payload TEXT NOT NULL, attempts INT NOT NULL DEFAULT 0,
        state VARCHAR(16) NOT NULL DEFAULT 'pending', available_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL,
        UNIQUE KEY unique_delivery (subscription_id, event_key), INDEX due_jobs (state, available_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_push_diagnostics (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NULL,
        http_status SMALLINT UNSIGNED NULL, diagnostic VARCHAR(500) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function push_keys(PDO $pdo): array {
    push_schema($pdo);
    $keys = $pdo->query('SELECT public_key, private_key FROM web_push_keys WHERE id = 1')->fetch();
    if (!$keys) {
        $new = \Minishlink\WebPush\VAPID::createVapidKeys();
        $pdo->prepare('INSERT IGNORE INTO web_push_keys (id, public_key, private_key) VALUES (1, ?, ?)')->execute([$new['publicKey'], $new['privateKey']]);
        $keys = $pdo->query('SELECT public_key, private_key FROM web_push_keys WHERE id = 1')->fetch();
    }
    return $keys;
}

function push_validate_subscription(array $data): array {
    $endpoint = (string) ($data['endpoint'] ?? '');
    $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
    // Prevent server-side requests to arbitrary URLs, private networks or redirects.
    $allowed = $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com'
        || $host === 'web.push.apple.com' || str_ends_with($host, '.push.apple.com')
        || str_ends_with($host, '.notify.windows.com');
    if (!$allowed || parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || strlen($endpoint) > 2048
        || parse_url($endpoint, PHP_URL_USER) !== null || parse_url($endpoint, PHP_URL_PORT) !== null) {
        throw new RuntimeException('Adresse de notification non prise en charge.');
    }
    foreach (['p256dh' => 65, 'auth' => 16] as $name => $length) {
        $raw = (string) ($data['keys'][$name] ?? '');
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if (!preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $raw) || $decoded === false || strlen($decoded) !== $length) {
            throw new RuntimeException('Clé de notification invalide.');
        }
    }
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']], 'contentEncoding' => 'aes128gcm'];
}

function push_schedule_dispatch(): void {
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;
    register_shutdown_function(static function (): void {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try { push_dispatch(db(), 10); } catch (Throwable) { error_log('LHorloger: notifications push en attente.'); }
    });
}

function push_queue_order(PDO $pdo, string $reference): void {
    push_schema($pdo);
    $payload = json_encode([
        'title' => 'Nouvelle commande ⌚', 'body' => 'Une commande attend votre appel dans L’Horloger.',
        'tag' => 'order-' . $reference, 'url' => '/closer/?new_q=' . rawurlencode($reference),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT IGNORE INTO web_push_jobs (subscription_id, event_key, payload, available_at)
        SELECT s.id, ?, ?, NOW() FROM web_push_subscriptions s JOIN admin_users u ON u.id = s.user_id
        WHERE s.active = 1 AND u.is_active = 1 AND u.role = 'closer'")
        ->execute(['order-' . $reference, $payload]);
    push_schedule_dispatch();
}

function push_safe_diagnostic(string $message): string {
    $message = preg_replace('~https?://[^\s"<>]+~i', '[adresse masquée]', $message);
    $message = preg_replace('/[A-Za-z0-9_\\-]{32,}/', '[identifiant masqué]', $message);
    return mb_substr($message, 0, 500);
}

function push_diagnostic(PDO $pdo, ?int $jobId, ?int $httpStatus, string $message): void {
    // Diagnostics must not turn an accepted notification into a retry.
    try {
        $pdo->prepare('INSERT INTO web_push_diagnostics (job_id, http_status, diagnostic) VALUES (?, ?, ?)')
            ->execute([$jobId, $httpStatus, push_safe_diagnostic($message)]);
    } catch (Throwable) { error_log('LHorloger: diagnostic push indisponible.'); }
}

function push_dispatch(PDO $pdo, int $limit = 10, ?int $onlyJobId = null): int {
    try { return push_dispatch_run($pdo, $limit, $onlyJobId); }
    catch (Throwable $error) {
        try { push_diagnostic($pdo, $onlyJobId, null, get_class($error) . ': ' . $error->getMessage()); } catch (Throwable) {}
        throw $error;
    }
}

function push_dispatch_run(PDO $pdo, int $limit = 10, ?int $onlyJobId = null): int {
    push_schema($pdo);
    // Recover a missed queue insertion without notifying about orders preceding activation.
    try {
        $references = $onlyJobId !== null ? [] : push_missing_order_refs($pdo);
        foreach ($references as $reference) {
            // Do not register another shutdown callback from the worker.
            push_queue_order_recovery($pdo, $reference);
        }
    } catch (Throwable $error) {
        push_diagnostic($pdo, null, null, 'Récupération : ' . $error->getMessage());
    }
    return push_dispatch_jobs($pdo, $limit, $onlyJobId);
}

function push_missing_order_refs(PDO $pdo): array {
    return $pdo->query("SELECT DISTINCT o.order_ref FROM orders o
        WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND o.status = 'À confirmer'
        AND EXISTS (SELECT 1 FROM web_push_subscriptions s JOIN admin_users u ON u.id = s.user_id
            WHERE s.active = 1 AND u.is_active = 1 AND u.role = 'closer' AND s.created_at <= o.created_at
            AND NOT EXISTS (SELECT 1 FROM web_push_jobs j WHERE j.subscription_id = s.id AND BINARY j.event_key = BINARY CONCAT('order-', o.order_ref)))
        LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
}

function push_dispatch_jobs(PDO $pdo, int $limit, ?int $onlyJobId): int {
    if (!(int) $pdo->query("SELECT COUNT(*) FROM web_push_jobs WHERE state = 'pending' AND available_at <= NOW()")->fetchColumn()) return 0;
    if (!(int) $pdo->query("SELECT GET_LOCK('lhorloger_web_push', 0)")->fetchColumn()) return 0;
    $sent = 0;
    try {
        $keys = push_keys($pdo);
        $client = new \Minishlink\WebPush\WebPush(['VAPID' => [
            'subject' => 'https://lhorloger.shop', 'publicKey' => $keys['public_key'], 'privateKey' => $keys['private_key'],
        ]], ['TTL' => 86400, 'urgency' => 'high'], 5, ['allow_redirects' => false, 'connect_timeout' => 3]);
        $jobs = $pdo->query("SELECT j.*, s.subscription_json, s.active, u.is_active AS user_active, u.role
            FROM web_push_jobs j JOIN web_push_subscriptions s ON s.id = j.subscription_id
            JOIN admin_users u ON u.id = s.user_id
            WHERE j.state = 'pending' AND j.available_at <= NOW()" . ($onlyJobId !== null ? ' AND j.id = ' . (int) $onlyJobId : '') . " ORDER BY j.id LIMIT " . max(1, min(30, $limit)))->fetchAll();
        foreach ($jobs as $job) {
            if (!$job['active'] || !$job['user_active'] || $job['role'] !== 'closer' || strtotime($job['created_at']) < time() - 86400) {
                $pdo->prepare("UPDATE web_push_jobs SET state = 'expired' WHERE id = ?")->execute([$job['id']]);
                continue;
            }
            try {
                $subscription = \Minishlink\WebPush\Subscription::create(push_validate_subscription(json_decode($job['subscription_json'], true, 16, JSON_THROW_ON_ERROR)));
                $report = $client->sendOneNotification($subscription, $job['payload']);
                if ($report->isSuccess()) {
                    push_diagnostic($pdo, (int) $job['id'], $report->getResponse()?->getStatusCode(), 'Acceptée par le service push');
                    $pdo->prepare("UPDATE web_push_jobs SET state = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = ?")->execute([$job['id']]);
                    $sent++;
                    continue;
                }
                if ($report->isSubscriptionExpired()) {
                    $pdo->prepare('UPDATE web_push_subscriptions SET active = 0 WHERE id = ?')->execute([$job['subscription_id']]);
                }
                $body = json_decode((string) $report->getResponseContent(), true);
                $reason = is_array($body) ? ($body['reason'] ?? 'Réponse non acceptée') : 'Réponse non acceptée';
                push_diagnostic($pdo, (int) $job['id'], $report->getResponse()?->getStatusCode(), is_string($reason) ? $reason : 'Réponse non acceptée');
            } catch (Throwable $error) {
                push_diagnostic($pdo, (int) $job['id'], null, get_class($error) . ': ' . $error->getMessage());
            }
            $delay = min(3600, 60 * (2 ** min(6, (int) $job['attempts'])));
            $pdo->prepare("UPDATE web_push_jobs SET attempts = attempts + 1, available_at = DATE_ADD(NOW(), INTERVAL $delay SECOND), state = IF(attempts >= 8, 'failed', 'pending') WHERE id = ?")->execute([$job['id']]);
        }
    } finally { $pdo->query("SELECT RELEASE_LOCK('lhorloger_web_push')"); }
    return $sent;
}

function push_queue_order_recovery(PDO $pdo, string $reference): void {
    $payload = json_encode(['title' => 'Nouvelle commande ⌚', 'body' => 'Une commande attend votre appel dans L’Horloger.',
        'tag' => 'order-' . $reference, 'url' => '/closer/?new_q=' . rawurlencode($reference)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT IGNORE INTO web_push_jobs (subscription_id, event_key, payload, available_at)
        SELECT s.id, ?, ?, NOW() FROM web_push_subscriptions s JOIN admin_users u ON u.id = s.user_id
        WHERE s.active = 1 AND u.is_active = 1 AND u.role = 'closer'
        AND s.created_at <= (SELECT MIN(created_at) FROM orders WHERE order_ref = ?)")
        ->execute(['order-' . $reference, $payload, $reference]);
}
