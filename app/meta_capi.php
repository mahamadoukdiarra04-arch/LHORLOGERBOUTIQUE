<?php
declare(strict_types=1);

/**
 * Meta Conversions API is intentionally kept behind an outbox.  A temporary
 * Meta/network outage must never prevent a cash-on-delivery order from being
 * recorded or a closer from confirming it.
 */
function meta_capi_config(): array {
    global $config;
    $settings = (array) ($config['meta_capi'] ?? []);
    return [
        'pixel_id' => trim((string) ($settings['pixel_id'] ?? '1719750622625367')),
        'access_token' => trim((string) ($settings['access_token'] ?? '')),
        'api_version' => trim((string) ($settings['api_version'] ?? 'v25.0')) ?: 'v25.0',
        'test_event_code' => trim((string) ($settings['test_event_code'] ?? '')),
    ];
}

function meta_capi_is_configured(): bool {
    $settings = meta_capi_config();
    return $settings['pixel_id'] !== ''
        && $settings['access_token'] !== ''
        && !str_contains($settings['access_token'], 'PASTE_');
}

function meta_capi_column_exists(PDO $pdo, string $table, string $column): bool {
    $statement = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
    $statement->execute([$column]);
    return (bool) $statement->fetch();
}

function ensure_meta_capi_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS meta_capi_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_ref VARCHAR(32) NOT NULL,
            event_key VARCHAR(40) NOT NULL,
            event_name VARCHAR(80) NOT NULL,
            event_id VARCHAR(120) NOT NULL,
            value_fcfa BIGINT UNSIGNED NULL,
            occurred_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NULL,
            delivered_at DATETIME NULL,
            last_http_status SMALLINT UNSIGNED NULL,
            last_error VARCHAR(1000) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_meta_capi_order_event (order_ref, event_key),
            INDEX idx_meta_capi_status_created (status, created_at),
            INDEX idx_meta_capi_order_ref (order_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columns = [
        'meta_fbc' => 'VARCHAR(255) NULL AFTER delivered_at',
        'meta_fbp' => 'VARCHAR(255) NULL AFTER meta_fbc',
        'meta_client_ip' => 'VARCHAR(45) NULL AFTER meta_fbp',
        'meta_client_user_agent' => 'VARCHAR(500) NULL AFTER meta_client_ip',
        'meta_landing_url' => 'VARCHAR(2048) NULL AFTER meta_client_user_agent',
    ];
    foreach ($columns as $column => $definition) {
        if (!meta_capi_column_exists($pdo, 'orders', $column)) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
    $ready = true;
}

function meta_capi_event_id(string $orderRef, string $eventKey): string {
    return 'hor:' . preg_replace('/[^A-Za-z0-9-]/', '', $orderRef) . ':' . $eventKey;
}

function meta_capi_event_name(string $eventKey): string {
    return match ($eventKey) {
        'lead' => 'Lead',
        'confirmed' => 'ConfirmedOrder',
        'purchase' => 'Purchase',
        default => throw new InvalidArgumentException('Évènement Meta invalide.'),
    };
}

function meta_capi_cookie(string $name): string {
    return substr(trim((string) ($_COOKIE[$name] ?? '')), 0, 255);
}

function meta_capi_capture_landing_context(): void {
    $fbc = meta_capi_cookie('_fbc');
    $fbp = meta_capi_cookie('_fbp');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    if ($host !== '' && $uri !== '') $_SESSION['meta_landing_url'] ??= substr($scheme . '://' . $host . $uri, 0, 2048);
    if ($fbc !== '') $_SESSION['meta_fbc'] = $fbc;
    if ($fbp !== '') $_SESSION['meta_fbp'] = $fbp;
}

function meta_capi_order_attribution(array $input): array {
    meta_capi_capture_landing_context();
    $fbc = substr(trim((string) ($input['meta_fbc'] ?? $_SESSION['meta_fbc'] ?? meta_capi_cookie('_fbc'))), 0, 255);
    $fbp = substr(trim((string) ($input['meta_fbp'] ?? $_SESSION['meta_fbp'] ?? meta_capi_cookie('_fbp'))), 0, 255);
    $landing = substr(trim((string) ($_SESSION['meta_landing_url'] ?? $input['meta_landing_url'] ?? '')), 0, 2048);
    return [
        'fbc' => $fbc !== '' ? $fbc : null,
        'fbp' => $fbp !== '' ? $fbp : null,
        'ip' => substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45) ?: null,
        'user_agent' => substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500) ?: null,
        'landing_url' => $landing !== '' ? $landing : null,
    ];
}

function meta_capi_queue_order_event(PDO $pdo, string $orderRef, string $eventKey, ?int $valueFcfa = null): void {
    ensure_meta_capi_schema($pdo);
    $eventName = meta_capi_event_name($eventKey);
    $statement = $pdo->prepare(
        'INSERT INTO meta_capi_events (order_ref, event_key, event_name, event_id, value_fcfa, occurred_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE value_fcfa = COALESCE(VALUES(value_fcfa), value_fcfa), updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([$orderRef, $eventKey, $eventName, meta_capi_event_id($orderRef, $eventKey), $valueFcfa]);
    if (meta_capi_is_configured()) meta_capi_dispatch_pending($pdo, 4);
}

function meta_capi_hash(?string $value): ?string {
    $normalized = mb_strtolower(trim((string) $value), 'UTF-8');
    return $normalized === '' ? null : hash('sha256', $normalized);
}

function meta_capi_phone(?string $phone): ?string {
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') return null;
    if (strlen($digits) === 8) $digits = '223' . $digits;
    return hash('sha256', $digits);
}

function meta_capi_payload_for_event(PDO $pdo, array $event): array {
    $orders = $pdo->prepare(
        'SELECT o.*, p.sku FROM orders o JOIN products p ON p.id = o.product_id WHERE o.order_ref = ? ORDER BY o.id ASC'
    );
    $orders->execute([$event['order_ref']]);
    $lines = $orders->fetchAll();
    if ($lines === []) throw new RuntimeException('Commande Meta introuvable.');
    $first = $lines[0];
    $contents = [];
    $total = 0;
    foreach ($lines as $line) {
        $quantity = max(1, (int) $line['quantity']);
        $unitPrice = max(0, (int) $line['unit_price_fcfa']);
        $total += $quantity * $unitPrice;
        $contents[] = [
            'id' => (string) $line['sku'],
            'quantity' => $quantity,
            'item_price' => $unitPrice,
        ];
    }
    $userData = array_filter([
        'fn' => meta_capi_hash((string) $first['customer_first_name']),
        'ln' => meta_capi_hash((string) $first['customer_last_name']),
        'ph' => meta_capi_phone((string) $first['phone']),
        'external_id' => meta_capi_hash((string) $event['order_ref']),
        'fbp' => $first['meta_fbp'] ?: null,
        'fbc' => $first['meta_fbc'] ?: null,
        'client_ip_address' => $first['meta_client_ip'] ?: null,
        'client_user_agent' => $first['meta_client_user_agent'] ?: null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
    $value = $event['value_fcfa'] !== null ? (int) $event['value_fcfa'] : $total;
    return [
        'event_name' => (string) $event['event_name'],
        'event_time' => max(1, strtotime((string) $event['occurred_at']) ?: time()),
        'event_id' => (string) $event['event_id'],
        'action_source' => 'website',
        'event_source_url' => $first['meta_landing_url'] ?: url('/'),
        'user_data' => $userData,
        'custom_data' => [
            'currency' => 'XOF', 'value' => $value, 'content_type' => 'product',
            'content_ids' => array_map(static fn (array $content): string => $content['id'], $contents),
            'contents' => $contents,
        ],
    ];
}

function meta_capi_dispatch_pending(PDO $pdo, int $limit = 20): int {
    ensure_meta_capi_schema($pdo);
    if (!meta_capi_is_configured()) return 0;
    $settings = meta_capi_config();
    $limit = max(1, min(50, $limit));
    $events = $pdo->query("SELECT * FROM meta_capi_events WHERE status IN ('pending', 'failed') ORDER BY id ASC LIMIT " . $limit)->fetchAll();
    $delivered = 0;
    foreach ($events as $event) {
        $httpStatus = null; $error = null; $successful = false;
        try {
            if (!function_exists('curl_init')) throw new RuntimeException('cURL n’est pas disponible sur le serveur.');
            $payload = ['data' => [meta_capi_payload_for_event($pdo, $event)]];
            if ($settings['test_event_code'] !== '') $payload['test_event_code'] = $settings['test_event_code'];
            $curl = curl_init('https://graph.facebook.com/' . rawurlencode($settings['api_version']) . '/' . rawurlencode($settings['pixel_id']) . '/events?access_token=' . rawurlencode($settings['access_token']));
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6]);
            $response = curl_exec($curl);
            $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($response === false) $error = curl_error($curl) ?: 'Échec de connexion à Meta.';
            curl_close($curl);
            $decoded = is_string($response) ? json_decode($response, true) : null;
            if ($httpStatus >= 200 && $httpStatus < 300 && is_array($decoded) && (int) ($decoded['events_received'] ?? 0) > 0) $successful = true;
            elseif ($error === null) $error = substr((string) (($decoded['error']['message'] ?? '') ?: 'Réponse Meta non acceptée.'), 0, 1000);
        } catch (Throwable $exception) {
            $error = substr($exception->getMessage(), 0, 1000);
        }
        $update = $pdo->prepare('UPDATE meta_capi_events SET status = ?, attempts = attempts + 1, last_attempt_at = NOW(), delivered_at = ?, last_http_status = ?, last_error = ? WHERE id = ?');
        $update->execute([$successful ? 'sent' : 'failed', $successful ? date('Y-m-d H:i:s') : null, $httpStatus ?: null, $successful ? null : $error, (int) $event['id']]);
        if ($successful) $delivered++;
    }
    return $delivered;
}
