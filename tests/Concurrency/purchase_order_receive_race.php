<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the P2 (Purchase Order Receiving) concurrency
// tests (tests/Concurrency/ConcurrencyTest.php). Run as a genuinely
// separate OS process with its own PDO connection - launched via
// proc_open(), never included directly by PHPUnit. Calls the real,
// unmodified receivePurchaseOrder() from includes/purchase_order.php - no
// application-level lock/mutex of any kind is added here; whatever safety
// this test observes comes entirely from the guarded
// "UPDATE ... WHERE received_qty + ? <= ordered_qty" statement and the
// purchase_orders row's own SELECT ... FOR UPDATE lock inside
// receivePurchaseOrder() itself. Same shape as
// tests/Concurrency/purchase_order_create_race.php.
//
// Each invocation always generates its OWN fresh random idempotency
// token internally - the scenario under test here is business-level
// quantity/lock safety across two DIFFERENT receiving events, not the
// idempotent-replay-of-the-same-event behavior (which is already covered
// by tests/Integration/PurchaseOrderReceivingTest.php and
// tests/Http/PurchaseOrderReceivingHttpTest.php).
//
// Usage: php purchase_order_receive_race.php <poId> <poItemId> <qty>
//        <unitCost> <userId> <goAtMicrotime>
// Prints one JSON line to stdout:
//   {"status":"ok","po_status":"...","stock_reference":"...","stock_transaction_item_id":N}
// or {"status":"error","exception":"ClassName","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';
require __DIR__ . '/../../includes/purchase_order.php';

[$script, $poId, $poItemId, $qty, $unitCost, $userId, $goAt] = $argv;
$poId = (int) $poId;
$poItemId = (int) $poItemId;
$qty = (int) $qty;
$unitCost = (float) $unitCost;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $token = bin2hex(random_bytes(32));
    $result = receivePurchaseOrder(
        $pdo,
        $poId,
        [[
            'purchase_order_item_id' => $poItemId,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'batch_number' => null,
            'expiry_date' => null,
        ]],
        date('Y-m-d'),
        'concurrency test',
        $userId,
        $token
    );
    echo json_encode([
        'status' => 'ok',
        'po_status' => $result['status'],
        'stock_reference' => $result['stock_reference'],
        'stock_transaction_item_id' => $result['receipts'][0]['stock_transaction_item_id'],
    ]) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'exception' => get_class($e), 'message' => $e->getMessage()]) . "\n";
}
