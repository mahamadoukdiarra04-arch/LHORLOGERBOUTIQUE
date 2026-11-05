<?php
require dirname(__DIR__) . '/app/push.php';
// Real VAPID signatures and payload encryption, fake HTTP transport: no device contacted.
$keys = Minishlink\WebPush\VAPID::createVapidKeys();
$receiver = Minishlink\WebPush\VAPID::createVapidKeys();
$auth = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
$subscription = Minishlink\WebPush\Subscription::create(push_validate_subscription([
    'endpoint' => 'https://web.push.apple.com/Q/offline-test',
    'keys' => ['p256dh' => $receiver['publicKey'], 'auth' => $auth],
]));
$history = [];
$handler = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(201)]));
$handler->push(GuzzleHttp\Middleware::history($history));
$client = new Minishlink\WebPush\WebPush(['VAPID' => ['subject' => 'https://lhorloger.shop'] + $keys], ['TTL' => 86400, 'urgency' => 'high'], 5, ['handler' => $handler]);
$report = $client->sendOneNotification($subscription, json_encode(['title' => 'Test ⌚', 'body' => 'Test hors ligne']));
if (!$report->isSuccess()) throw new RuntimeException('Transport mock failed');
$request = $history[0]['request'];
if ($request->getHeaderLine('Content-Encoding') !== 'aes128gcm') throw new RuntimeException('Wrong encoding');
if (!str_starts_with($request->getHeaderLine('Authorization'), 'vapid ')) throw new RuntimeException('Missing VAPID');
if ($request->getHeaderLine('Urgency') !== 'high') throw new RuntimeException('Missing urgency');
$safe = push_safe_diagnostic('Failed https://web.push.apple.com/secret-endpoint ' . str_repeat('a', 65));
if (str_contains($safe, 'secret-endpoint') || str_contains($safe, str_repeat('a', 65))) throw new RuntimeException('Sensitive diagnostic');
echo "PASS: real VAPID signing, encryption, aes128gcm, urgency, redacted diagnostics; no network\n";
