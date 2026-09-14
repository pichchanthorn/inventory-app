<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the P3-A (Purchase Order Cancellation)
// concurrency tests (tests/Concurrency/ConcurrencyTest.php). Run as a
// genuinely separate OS process with its own PDO connection - launched
// via proc_open(), never included directly by PHPUnit. Calls the real,
// unmodified cancelPurchaseOrder() from includes/purchase_order.php - no
// application-level lock/mutex of any kind is added here; whatever safety
// this test observes comes entirely from the purchase_orders row's own
// SELECT ... FOR UPDATE lock inside cancelPurchaseOrder() itself - the
// SAME lock receivePurchaseOrder() takes as its own first mutating step.
// Same shape as tests/Concurrency/purchase_order_receive_race.php.
//
// Usage: php purchase_order_cancel_race.php <poId> <userId> <goAtMicrotime>
// Prints one JSON line to stdout:
//   {"status":"ok"}
// or {"status":"error","exception":"ClassName","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';
require __DIR__ . '/../../includes/purchase_order.php';

[$script, $poId, $userId, $goAt] = $argv;
$poId = (int) $poId;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    cancelPurchaseOrder($pdo, $poId, $userId);
    echo json_encode(['status' => 'ok']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'exception' => get_class($e), 'message' => $e->getMessage()]) . "\n";
}
