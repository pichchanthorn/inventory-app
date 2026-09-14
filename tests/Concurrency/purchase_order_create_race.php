<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the P1 (Purchase Order) reference/creation
// concurrency test (tests/Concurrency/ConcurrencyTest.php). Run as a
// genuinely separate OS process with its own PDO connection - launched
// via proc_open(), never included directly by PHPUnit. Calls the real,
// unmodified createPurchaseOrder() from includes/purchase_order.php - no
// application-level lock/mutex of any kind is added here; whatever
// safety this test observes comes entirely from nextReferenceSequence()'s
// own SELECT ... FOR UPDATE row lock on the 'purchase_orders' counter
// row. Same shape as tests/Concurrency/stock_in_batch_race.php.
//
// Usage: php purchase_order_create_race.php <supplierId> <productId>
//        <userId> <goAtMicrotime>
// Prints one JSON line to stdout: {"status":"ok","id":N,"reference":"..."}
// or {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';
require __DIR__ . '/../../includes/purchase_order.php';

[$script, $supplierId, $productId, $userId, $goAt] = $argv;
$supplierId = (int) $supplierId;
$productId = (int) $productId;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $result = createPurchaseOrder(
        $pdo,
        $supplierId,
        date('Y-m-d'),
        null,
        'concurrency test',
        [['product_id' => $productId, 'ordered_qty' => 1, 'unit_cost' => 1.00]],
        $userId
    );
    echo json_encode(['status' => 'ok', 'id' => $result['id'], 'reference' => $result['reference']]) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
