<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_manager();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = db();
    $start = '2026-11-01 00:00:00';
    $end = '2026-11-22 00:00:00';

    $created = $pdo->prepare(
        'SELECT p.name AS product, o.status, COUNT(*) AS line_count,
                COUNT(DISTINCT o.order_ref) AS order_count, COALESCE(SUM(o.quantity), 0) AS unit_count
         FROM orders o JOIN products p ON p.id = o.product_id
         WHERE o.created_at >= ? AND o.created_at < ?
         GROUP BY p.id, p.name, o.status
         ORDER BY p.name, o.status'
    );
    $created->execute([$start, $end]);

    $delivered = $pdo->prepare(
        'SELECT p.name AS product, COALESCE(o.acquisition_channel, "Unspecified") AS channel,
                COUNT(*) AS line_count, COUNT(DISTINCT o.order_ref) AS order_count,
                COALESCE(SUM(o.quantity), 0) AS unit_count,
                COALESCE(SUM(o.quantity * o.unit_price_fcfa), 0) AS order_value_fcfa
         FROM orders o JOIN products p ON p.id = o.product_id
         WHERE o.status = "Livrée"
           AND COALESCE(o.delivered_at, o.created_at) >= ?
           AND COALESCE(o.delivered_at, o.created_at) < ?
         GROUP BY p.id, p.name, o.acquisition_channel
         ORDER BY p.name, o.acquisition_channel'
    );
    $delivered->execute([$start, $end]);

    $createdDaily = $pdo->prepare(
        'SELECT DATE(o.created_at) AS day, o.status, COUNT(DISTINCT o.order_ref) AS order_count
         FROM orders o
         WHERE o.created_at >= ? AND o.created_at < ?
         GROUP BY DATE(o.created_at), o.status
         ORDER BY day, o.status'
    );
    $createdDaily->execute([$start, $end]);

    echo json_encode([
        'period' => ['start' => $start, 'end_exclusive' => $end],
        'created_by_product_and_current_status' => $created->fetchAll(PDO::FETCH_ASSOC),
        'delivered_during_period' => $delivered->fetchAll(PDO::FETCH_ASSOC),
        'created_daily_by_current_status' => $createdDaily->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('KPI audit read failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
