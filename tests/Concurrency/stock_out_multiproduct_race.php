<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the K3-1 multi-product deadlock-hardening
// concurrency test. Submits a two-line Stock Out in the exact [A, B]
// order given on the command line - two concurrent invocations of this
// script with OPPOSITE product order (A,B then B,A) are what would risk
// the classic opposite-order lock deadlock if insertStockOutLines()
// didn't sort its lines by product_id ASC internally before locking.
// This worker itself does no sorting or coordination of any kind - it
// only proves the real, unmodified recordStockOut() call handles it.
//
// Usage: php stock_out_multiproduct_race.php <productIdA> <qtyA> <productIdB> <qtyB> <userId> <goAtMicrotime>
// Prints one JSON line to stdout: {"status":"ok","reference":"..."}
// or {"status":"conflict"} or {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';

[$script, $productIdA, $qtyA, $productIdB, $qtyB, $userId, $goAt] = $argv;
$productIdA = (int) $productIdA;
$qtyA = (float) $qtyA;
$productIdB = (int) $productIdB;
$qtyB = (float) $qtyB;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $reference = recordStockOut($pdo, [
        ['product_id' => $productIdA, 'qty' => $qtyA, 'price' => 1],
        ['product_id' => $productIdB, 'qty' => $qtyB, 'price' => 1],
    ], date('Y-m-d'), 'concurrency test', $userId, 'out', null, null, true);
    echo json_encode(['status' => 'ok', 'reference' => $reference]) . "\n";
} catch (StockConflictException $e) {
    echo json_encode(['status' => 'conflict']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
