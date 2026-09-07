<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the K3-1 Stock Out FEFO concurrency tests
// (tests/Concurrency/ConcurrencyTest.php). Run as a genuinely separate OS
// process with its own PDO connection - launched via proc_open(), never
// included directly by PHPUnit. Calls the real, unmodified
// recordStockOut(..., consumeBatches: true) from includes/stock.php - no
// application-level lock/mutex of any kind is added here; whatever
// safety this test observes comes entirely from the product-row lock
// inside insertStockOutLines() itself. Same shape as stock_out_race.php
// and stock_in_batch_race.php.
//
// Usage: php stock_out_batch_race.php <productId> <qty> <userId> <goAtMicrotime>
// Prints one JSON line to stdout: {"status":"ok","reference":"..."}
// or {"status":"conflict"} or {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';

[$script, $productId, $qty, $userId, $goAt] = $argv;
$productId = (int) $productId;
$qty = (float) $qty;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $reference = recordStockOut($pdo, [['product_id' => $productId, 'qty' => $qty, 'price' => 1]], date('Y-m-d'), 'concurrency test', $userId, 'out', null, null, true);
    echo json_encode(['status' => 'ok', 'reference' => $reference]) . "\n";
} catch (StockConflictException $e) {
    echo json_encode(['status' => 'conflict']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
