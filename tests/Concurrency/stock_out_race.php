<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the P0 #8 (Stock Concurrency) test. Run as a
// genuinely separate OS process with its own PDO connection - launched by
// tests/Concurrency/ConcurrencyTest.php via proc_open(), never included
// directly by PHPUnit. Calls the real, unmodified recordStockOut() from
// includes/stock.php - no application-level lock/mutex of any kind is
// added here; whatever safety this test observes comes entirely from the
// guarded UPDATE inside recordStockOut() itself.
//
// Usage: php stock_out_race.php <productId> <qty> <userId> <goAtMicrotime>
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

// Busy-wait until the shared start time the orchestrator computed, so
// both worker processes reach recordStockOut() as close to
// simultaneously as possible - this is scheduling coordination for the
// test harness only, not a lock on the resource under test.
while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $reference = recordStockOut($pdo, [['product_id' => $productId, 'qty' => $qty, 'price' => 1]], date('Y-m-d'), 'concurrency test', $userId);
    echo json_encode(['status' => 'ok', 'reference' => $reference]) . "\n";
} catch (StockConflictException $e) {
    echo json_encode(['status' => 'conflict']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
