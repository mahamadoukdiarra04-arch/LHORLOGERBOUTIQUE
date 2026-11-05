<?php
require dirname(__DIR__) . '/app/meta_capi.php';
function assert_meta(bool $test, string $label): void { if (!$test) throw new RuntimeException($label); echo 'PASS ' . $label . PHP_EOL; }
$now = meta_capi_timestamp('2026-11-05 12:00:00');
assert_meta(meta_capi_is_recent('2026-10-29 12:00:01', $now), 'Within seven days');
assert_meta(!meta_capi_is_recent('2026-10-29 12:00:00', $now), 'Boundary expired');
assert_meta(!meta_capi_is_recent('2026-11-05 12:00:01', $now), 'Future rejected');
try { meta_capi_timestamp('2026-02-30 10:00:00'); $invalid = false; } catch (RuntimeException) { $invalid = true; }
assert_meta($invalid, 'Invalid date rejected, no fallback to now');
$lines = [['id' => 1, 'status' => 'Livrée', 'delivered_at' => '2026-11-01 12:00:00', 'quantity' => 1, 'unit_price_fcfa' => 25000], ['id' => 2, 'status' => 'Livrée', 'delivered_at' => '2026-11-01 12:00:00', 'quantity' => 1, 'unit_price_fcfa' => 20000]];
$receipts = [['order_id' => 1, 'amount_fcfa' => 25000, 'effective_at' => '2026-11-01 12:00:00'], ['order_id' => 2, 'amount_fcfa' => 10000, 'effective_at' => '2026-11-01 12:00:00']];
assert_meta(meta_capi_purchase_from_rows($lines, $receipts) === null, 'Partial collection rejected');
$receipts[] = ['order_id' => 2, 'amount_fcfa' => 10000, 'effective_at' => '2026-11-04 15:00:00'];
$facts = meta_capi_purchase_from_rows($lines, $receipts);
assert_meta($facts === ['occurred_at' => '2026-11-04 15:00:00', 'value_fcfa' => 45000], 'Final balance date and single order value');
$later = $lines; $later[1]['delivered_at'] = '2026-11-05 08:00:00';
assert_meta(meta_capi_purchase_from_rows($later, $receipts)['occurred_at'] === '2026-11-05 08:00:00', 'Wait for delivery after prepayment');
$cancelled = $lines; $cancelled[0]['status'] = 'Annulée';
assert_meta(meta_capi_purchase_from_rows($cancelled, $receipts) === null, 'Cancelled order rejected');
$over = $receipts; $over[] = ['order_id' => 1, 'amount_fcfa' => 100, 'effective_at' => '2026-11-04 16:00:00'];
assert_meta(meta_capi_purchase_from_rows($lines, $over) === null, 'Ambiguous overpayment rejected');
assert_meta(meta_capi_event_id('HOR-261101-123456', 'purchase') === 'hor:HOR-261101-123456:purchase', 'Existing deduplication identity unchanged');
