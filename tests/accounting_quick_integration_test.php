<?php
declare(strict_types=1);
// Explicit opt-in; this test cannot connect to the production configuration.
if (getenv('LHORLOGER_QUICK_TEST') !== '1') { echo "SKIP — LHORLOGER_QUICK_TEST=1 required (isolated MySQL :3308)\n"; exit; }
putenv('LHORLOGER_LOCAL_CONFIG=' . __DIR__ . '/fixtures/quick-config.php');
$server = new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS lhorloger_quick_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
require __DIR__ . '/../app/bootstrap.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'lhorloger_quick_test') throw new RuntimeException('Test database required.');
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
ensure_admin_users_schema();
ensure_accounting_schema();
ensure_closer_schema();
ensure_meta_capi_schema($pdo);
$userId = (int) $pdo->query("SELECT id FROM admin_users WHERE username = 'QA'")->fetchColumn();
$_SESSION['admin_user_id'] = $userId;
$_SESSION['admin_identity'] = 'QA';
$suffix = strtoupper(bin2hex(random_bytes(4)));
function qa_assert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function qa_throws(callable $call, string $message): void { try { $call(); } catch (RuntimeException $error) { if ($error instanceof PDOException) throw $error; echo "PASS $message\n"; return; } throw new RuntimeException($message); }
$account = accounting_create_account($pdo, ['code' => 'QA-' . $suffix, 'name' => 'Orange Money QA ' . $suffix, 'account_type' => 'mobile_money', 'opening_balance_fcfa' => 500000, 'opening_at' => '2026-01-01'], $userId);
$productIds = [];
$variantIds = [];
foreach (['Nocturne Chrono QA', 'Azur Squelette QA', 'Sans stock QA'] as $index => $name) {
    $pdo->prepare('INSERT INTO products (slug, sku, name, price_fcfa) VALUES (?, ?, ?, 25000)')->execute(['qa-' . $suffix . '-' . $index, 'QA-' . $suffix . '-' . $index, $name]);
    $productIds[] = $pid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_variants (product_id, name) VALUES (?, ?)')->execute([$pid, $index === 0 ? 'Noir & brun' : 'Bleu signature']);
    $variantIds[] = $vid = (int) $pdo->lastInsertId();
    if ($index < 2) accounting_with_transaction($pdo, fn() => accounting_stock_record_movement($pdo, ['product_id' => $pid, 'variant_id' => $vid, 'movement_type' => 'Réassort', 'quantity' => 40, 'purchase_price_fcfa' => 320000, 'transit_price_fcfa' => 0, 'effective_at' => '2026-11-01T10:00', 'actor' => 'QA']));
}
$yesterday = (new DateTimeImmutable('yesterday', accounting_bamako_timezone()))->format('Y-m-d');
function qa_order(PDO $pdo, string $ref, int $product, int $variant, string $date, string $channel = 'Meta'): int {
    $p = $pdo->prepare('SELECT p.name AS product_name, v.name AS variant FROM products p JOIN product_variants v ON v.product_id = p.id WHERE p.id = ? AND v.id = ?');
    $p->execute([$product, $variant]); $row = $p->fetch();
    $pdo->prepare("INSERT INTO orders (order_ref, customer_first_name, customer_last_name, phone, district, product_id, variant_id, product_name, variant, quantity, unit_price_fcfa, acquisition_channel, created_at) VALUES (?, 'Client QA', 'Test local', '+22370000000', 'Quartier QA', ?, ?, ?, ?, 1, 25000, ?, ?)")
        ->execute([$ref, $product, $variant, $row['product_name'], $row['variant'], $channel ?: null, $date . ' 08:00:00']);
    return (int) $pdo->lastInsertId();
}
$refA = 'QA-' . $suffix . '-A'; $refB = 'QA-' . $suffix . '-B';
$a = qa_order($pdo, $refA, $productIds[0], $variantIds[0], $yesterday);
$a2 = qa_order($pdo, $refA, $productIds[1], $variantIds[1], $yesterday);
$b = qa_order($pdo, $refB, $productIds[0], $variantIds[0], $yesterday);
$data = ['order_ids' => [$a, $a2, $b], 'batch_action' => 'deliver', 'effective_at' => $yesterday . 'T12:00', 'account_id' => $account['id']];
$plan = accounting_quick_prepare($pdo, $data);
qa_assert(count($plan['entries']) === 2 && $plan['total_fcfa'] === 75000, 'One reference per basket; all lines included');
$plan = accounting_quick_apply_dates($plan, [$a => $yesterday . 'T12:00', $b => $yesterday . 'T14:30']);
$done = accounting_quick_execute($pdo, $plan, $userId);
qa_assert($done['count'] === 2 && $done['total_fcfa'] === 75000, 'Two deliveries collected together');
qa_assert(accounting_account_balance($pdo, (int) $account['id']) === 575000, 'Correct combined treasury balance');
qa_assert(accounting_stock_available($pdo, $productIds[0]) === 38 && accounting_stock_available($pdo, $productIds[1]) === 39, 'Stock deducted for all lines once');
$firstCount = (int) $pdo->query('SELECT COUNT(*) FROM accounting_operations')->fetchColumn();
$replay = accounting_quick_execute($pdo, $plan, $userId);
qa_assert($replay['replayed'] && $firstCount === (int) $pdo->query('SELECT COUNT(*) FROM accounting_operations')->fetchColumn(), 'Retry does not duplicate ledger or stock');
$changedPlan = $plan; $changedPlan['entries'][0]['effective_at'] = $yesterday . ' 13:00:00';
qa_throws(fn() => accounting_quick_execute($pdo, $changedPlan, $userId), 'Same key cannot confirm a changed payload');
$stmt = $pdo->prepare('SELECT delivered_at FROM orders WHERE id = ?'); $stmt->execute([$b]);
qa_assert($stmt->fetchColumn() === $yesterday . ' 14:30:00', 'Real date retained per order');
$stmt = $pdo->prepare('SELECT effective_at FROM stock_movements WHERE order_id = ?'); $stmt->execute([$b]);
qa_assert($stmt->fetchColumn() === $yesterday . ' 14:30:00', 'Stock movement has the real sale date');
$c = qa_order($pdo, 'QA-' . $suffix . '-C', $productIds[0], $variantIds[0], $yesterday);
$d = qa_order($pdo, 'QA-' . $suffix . '-D', $productIds[2], $variantIds[2], $yesterday);
$badPlan = accounting_quick_prepare($pdo, array_replace($data, ['order_ids' => [$c, $d]]));
$balance = accounting_account_balance($pdo, (int) $account['id']);
qa_throws(fn() => accounting_quick_execute($pdo, $badPlan, $userId), 'Failure on second order rolls back the whole lot');
qa_assert(accounting_account_balance($pdo, (int) $account['id']) === $balance && accounting_stock_available($pdo, $productIds[0]) === 38, 'No money or stock changed by failed lot');
$stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?'); $stmt->execute([$c]);
qa_assert($stmt->fetchColumn() === 'À confirmer', 'First order status rolled back too');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM meta_capi_events WHERE order_ref IN (?, ?)'); $stmt->execute(['QA-' . $suffix . '-C', 'QA-' . $suffix . '-D']);
qa_assert((int) $stmt->fetchColumn() === 0, 'No Meta event queued before batch commit');
$stale = accounting_quick_prepare($pdo, array_replace($data, ['order_ids' => [$c]]));
$pdo->prepare('UPDATE orders SET unit_price_fcfa = 20000 WHERE id = ?')->execute([$c]);
qa_throws(fn() => accounting_quick_execute($pdo, $stale, $userId), 'Concurrent price edit rejects stale preview');
$e = qa_order($pdo, 'QA-' . $suffix . '-E', $productIds[0], $variantIds[0], $yesterday, 'Réachat');
$f = qa_order($pdo, 'QA-' . $suffix . '-F', $productIds[0], $variantIds[0], $yesterday, '');
$pdo->prepare("INSERT INTO order_closer_tracking (order_id, closer_identity, follow_up_status) VALUES (?, 'QA', 'À appeler'), (?, 'QA', 'À appeler')")->execute([$e, $f]);
$statusPlan = accounting_quick_prepare($pdo, ['order_ids' => [$e, $f], 'batch_action' => 'confirm', 'channel' => 'Meta']);
accounting_quick_execute($pdo, $statusPlan, $userId);
$stmt = $pdo->prepare('SELECT acquisition_channel FROM orders WHERE id IN (?, ?) ORDER BY id'); $stmt->execute([$e, $f]);
qa_assert($stmt->fetchAll(PDO::FETCH_COLUMN) === ['Réachat', 'Meta'], 'Known channels preserved, missing channels filled explicitly');
$stmt = $pdo->prepare('SELECT follow_up_status FROM order_closer_tracking WHERE order_id IN (?, ?) ORDER BY order_id'); $stmt->execute([$e, $f]);
qa_assert($stmt->fetchAll(PDO::FETCH_COLUMN) === ['Confirmée', 'Confirmée'], 'Closer tracking synchronized');
$cancel = accounting_quick_prepare($pdo, ['order_ids' => [$e, $f], 'batch_action' => 'cancel']);
accounting_quick_execute($pdo, $cancel, $userId);
$stmt->execute([$e, $f]); qa_assert($stmt->fetchAll(PDO::FETCH_COLUMN) === ['Annulée', 'Annulée'], 'Cancellation synchronized without new money');
$g = qa_order($pdo, 'QA-' . $suffix . '-G', $productIds[0], $variantIds[0], $yesterday);
accounting_confirm_delivery($pdo, $g, ['idempotency_key' => accounting_new_uuid(), 'effective_at' => $yesterday . 'T10:00', 'exception_mode' => '1', 'exception_reason' => 'QA solde', 'payments' => [['account_id' => $account['id'], 'amount_fcfa' => 10000]]], $userId);
$stock = accounting_stock_available($pdo, $productIds[0]);
$collect = accounting_quick_prepare($pdo, ['order_ids' => [$g], 'batch_action' => 'collect', 'effective_at' => $yesterday . 'T13:00', 'account_id' => $account['id']]);
qa_assert($collect['total_fcfa'] === 15000, 'Only unpaid balance is collected');
accounting_quick_execute($pdo, $collect, $userId);
qa_assert(!accounting_order_has_open_payment_exception($pdo, 'QA-' . $suffix . '-G') && accounting_stock_available($pdo, $productIds[0]) === $stock, 'Balance closed without a second stock deduction');
$category = accounting_category_by_code($pdo, 'meta_ads');
$expense = accounting_simple_expense($pdo, ['category_id' => $category['id'], 'product_id' => $productIds[0], 'label' => '']);
qa_assert($expense['scope'] === 'product' && str_contains($expense['label'], 'Nocturne'), 'Expense label and product scope inferred on server');
// Enough harmless local fixtures for pagination and visual testing.
for ($i = 0; $i < 31; $i++) qa_order($pdo, 'QA-' . $suffix . '-UI' . $i, $productIds[0], $variantIds[0], $yesterday, $i % 2 ? 'Meta' : '');
echo "OK — isolated MySQL integration; production untouched\n";
