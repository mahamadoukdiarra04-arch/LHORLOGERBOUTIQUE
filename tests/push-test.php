<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/push.php';
function check(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
}
$encode = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$subscription = ['endpoint' => 'https://web.push.apple.com/Q/test', 'keys' => [
    'p256dh' => $encode("\x04" . random_bytes(64)), 'auth' => $encode(random_bytes(16)),
]];
check(push_validate_subscription($subscription)['contentEncoding'] === 'aes128gcm', 'Apple subscription accepted');
foreach (['https://fcm.googleapis.com/fcm/send/test', 'https://updates.push.services.mozilla.com/wpush/v2/test'] as $endpoint) {
    check(push_validate_subscription(array_replace($subscription, ['endpoint' => $endpoint]))['endpoint'] === $endpoint, 'Browser endpoint accepted');
}
foreach (['https://127.0.0.1/test', 'http://web.push.apple.com/test', 'https://web.push.apple.com.evil.test/test', 'https://user@web.push.apple.com/test', 'https://web.push.apple.com:8443/test'] as $endpoint) {
    try { push_validate_subscription(array_replace($subscription, ['endpoint' => $endpoint])); $rejected = false; }
    catch (RuntimeException) { $rejected = true; }
    check($rejected, 'Unsafe endpoint rejected');
}
$bad = $subscription; $bad['keys']['auth'] = 'bad';
try { push_validate_subscription($bad); $rejected = false; } catch (RuntimeException) { $rejected = true; }
check($rejected, 'Invalid encryption key rejected');
$checkout = file_get_contents(dirname(__DIR__) . '/public/checkout.php');
check(strpos($checkout, 'push_schema($pdo)') < strpos($checkout, '$pdo->beginTransaction()'), 'Schema prepared before transaction');
check(strpos($checkout, 'push_queue_order($pdo, $ref)') < strpos($checkout, '$pdo->commit()'), 'Outbox queued before commit');
check(substr_count($checkout, 'push_queue_order($pdo, $ref)') === 1, 'One event per checkout');
$worker = file_get_contents(dirname(__DIR__) . '/public/closer/sw.js');
check(!str_contains($worker, "addEventListener('fetch'"), 'No private-page caching');
