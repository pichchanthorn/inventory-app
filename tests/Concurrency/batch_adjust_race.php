<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the K4-6-1 batch-specific Stock Adjustment
// concurrency tests (tests/Concurrency/ConcurrencyTest.php). Run as a
// genuinely separate OS process with its own PDO connection - launched
// via proc_open(), never included directly by PHPUnit. Calls the real,
// unmodified batchAdjustStock() from includes/stock.php - no
// application-level lock/mutex of any kind is added here; whatever
// safety a test built on this worker observes comes entirely from that
// function's own locking (product row, then the target batch row - see
// its own comment). Same shape/conventions as stock_in_batch_race.php,
// stock_out_batch_race.php, enable_track_batches_race.php.
//
// Usage: php batch_adjust_race.php <productId> <batchId> <newQty>
//        <expectedQty> <userId> <goAtMicrotime>
// Prints one JSON line to stdout:
//   {"status":"ok","reference":"..."}
//   {"status":"conflict"}          (BatchAdjustmentConflictException -
//                                    the expected stale-form/CAS race outcome)
//   {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';

[$script, $productId, $batchId, $newQty, $expectedQty, $userId, $goAt] = $argv;
$productId = (int) $productId;
$batchId = (int) $batchId;
$newQty = (int) $newQty;
$expectedQty = (int) $expectedQty;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $reference = batchAdjustStock($pdo, $productId, $batchId, $newQty, $expectedQty, 'concurrency test', date('Y-m-d'), $userId);
    echo json_encode(['status' => 'ok', 'reference' => $reference]) . "\n";
} catch (BatchAdjustmentConflictException $e) {
    echo json_encode(['status' => 'conflict']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
