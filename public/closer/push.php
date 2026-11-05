<?php
require __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!is_closer()) { http_response_code(403); echo json_encode(['error' => 'Connectez-vous avec votre compte closeuse.']); exit; }
require_once APP_ROOT . '/push.php';
try {
    $pdo = db(); push_schema($pdo);
    $userId = (int) ($_SESSION['admin_user_id'] ?? 0);
    if (!$userId) throw new RuntimeException('Session à renouveler.');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['publicKey' => push_keys($pdo)['public_key'], 'csrf' => csrf_token()]);
        push_schedule_dispatch(); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $data = json_decode((string) ($_POST['subscription'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
    $subscription = push_validate_subscription($data);
    $hash = hash('sha256', $subscription['endpoint']);
    if ($action === 'subscribe') {
        $pdo->prepare('INSERT INTO web_push_subscriptions (user_id, endpoint_hash, subscription_json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), subscription_json = VALUES(subscription_json), active = 1')->execute([$userId, $hash, json_encode($subscription, JSON_THROW_ON_ERROR)]);
    } elseif ($action === 'unsubscribe') {
        $pdo->prepare('UPDATE web_push_subscriptions SET active = 0 WHERE user_id = ? AND endpoint_hash = ?')->execute([$userId, $hash]);
    } elseif ($action === 'test') {
        $query = $pdo->prepare('SELECT id FROM web_push_subscriptions WHERE user_id = ? AND endpoint_hash = ? AND active = 1');
        $query->execute([$userId, $hash]); $id = $query->fetchColumn();
        if (!$id) throw new RuntimeException('Activez les notifications sur cet appareil.');
        if (($_SESSION['push_test_at'] ?? 0) > time() - 60) throw new RuntimeException('Attendez une minute avant un nouveau test.');
        $_SESSION['push_test_at'] = time();
        $pdo->prepare('INSERT INTO web_push_jobs (subscription_id, event_key, payload, available_at) VALUES (?, ?, ?, NOW())')->execute([$id, 'test-' . bin2hex(random_bytes(8)), json_encode(['title' => 'L’Horloger · Test', 'body' => 'Les notifications sont bien activées.', 'tag' => 'push-test', 'url' => '/closer/'], JSON_THROW_ON_ERROR)]);
        session_write_close();
        if (push_dispatch($pdo) < 1) throw new RuntimeException('Le test est en attente. Réessayez dans une minute.');
    } else { throw new RuntimeException('Action inconnue.'); }
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['error' => $error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'Notifications momentanément indisponibles. Réessayez.']);
}
