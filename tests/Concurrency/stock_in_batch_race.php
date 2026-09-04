<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the K2a batch-concurrency tests
// (tests/Concurrency/ConcurrencyTest.php). Run as a genuinely separate OS
// process with its own PDO connection - launched via proc_open(), never
// included directly by PHPUnit. Calls the real, unmodified recordStockIn()
// from includes/stock.php - no application-level lock/mutex of any kind
// is added here; whatever safety this test observes comes entirely from
// the product-row lock (SELECT ... FOR UPDATE) inside recordStockIn()
// itself. Same shape as tests/Concurrency/stock_out_race.php.
//
// Usage: php stock_in_batch_race.php <productId> <batchNumber|_NULL_>
//        <expiryDate|_NULL_> <qty> <userId> <goAtMicrotime>
// The literal token "_NULL_" maps to a real PHP null - argv is always a
// string, so this is how the orchestrator asks for a NULL batch_number or
// expiry_date without ambiguity against a genuine empty-string value.
// Prints one JSON line to stdout: {"status":"ok","reference":"...","batchId":N}
// or {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';

[$script, $productId, $batchNumber, $expiryDate, $qty, $userId, $goAt] = $argv;
$productId = (int) $productId;
$batchNumber = $batchNumber === '_NULL_' ? null : $batchNumber;
$expiryDate = $expiryDate === '_NULL_' ? null : $expiryDate;
$qty = (float) $qty;
$userId = (int) $userId;
$goAt = (float) $goAt;

// Busy-wait until the shared start time the orchestrator computed, so all
// worker processes reach recordStockIn() as close to simultaneously as
// possible - scheduling coordination for the test harness only, not a
// lock on the resource under test.
while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $line = ['product_id' => $productId, 'qty' => $qty, 'cost' => 1, 'batch_number' => $batchNumber, 'expiry_date' => $expiryDate];
    $reference = recordStockIn($pdo, [$line], date('Y-m-d'), null, 'concurrency test', $userId);

    $stmt = $pdo->prepare("SELECT sib.batch_id FROM stock_transaction_item_batches sib
                            JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                            JOIN stock_transactions st ON st.id = sti.transaction_id
                            WHERE st.reference = ?");
    $stmt->execute([$reference]);
    $batchId = (int) $stmt->fetchColumn();

    echo json_encode(['status' => 'ok', 'reference' => $reference, 'batchId' => $batchId]) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
