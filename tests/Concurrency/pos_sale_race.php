<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the K4-3 POS concurrency tests
// (tests/Concurrency/ConcurrencyTest.php). Run as a genuinely separate OS
// process with its own PDO connection - launched via proc_open(), never
// included directly by PHPUnit. Same shape/conventions as
// stock_out_batch_race.php and stock_out_multiproduct_race.php: no
// application-level lock/mutex of any kind is added here, and no
// production logic is duplicated - this file only parses CLI args, waits
// for the shared start barrier, and calls the real, unmodified
// recordStockOut(..., consumeBatches: true) (cash) or
// recordCreditSale(..., consumeBatches: true) (credit) from
// includes/stock.php / includes/debt.php. Whatever safety a test built on
// this worker observes comes entirely from those two functions' own
// locking - see includes/stock.php's insertStockOutLines() and
// includes/debt.php's recordCreditSale() for the mechanism.
//
// Usage:
//   php pos_sale_race.php <method> <lines> <token> <userId> <customerMode> <goAtMicrotime>
//
//   <method>       "cash" or "credit"
//   <lines>        comma-separated "productId:qty" pairs, e.g. "5:7" or
//                  "5:8,6:4" - every line uses unit price 1, same
//                  convention as stock_out_batch_race.php, since these
//                  tests are about quantity/allocation math, not totals.
//   <token>        a literal idempotency token string, or the sentinel
//                  "_NONE_" for no token at all (skips the idempotency
//                  claim entirely, same as passing null) - same "literal
//                  sentinel for a real null" convention as
//                  stock_in_batch_race.php's "_NULL_". The caller
//                  (ConcurrencyTest.php) decides whether two workers get
//                  the SAME literal token (to race the idempotency claim
//                  itself) or two different ones (to race only the
//                  stock/batch locks) - this worker never invents its own.
//   <userId>       existing user id to record the sale under.
//   <customerMode> "_NA_" for cash (ignored); for credit, either
//                  "existing:<customerId>" or "new:<name>:<phone>" (phone
//                  may be empty: "new:<name>:").
//   <goAtMicrotime> shared start-barrier time, same busy-wait pattern as
//                  every other worker in this directory.
//
// Prints exactly one JSON line to stdout:
//   cash success:   {"status":"ok","reference":"..."}
//   credit success: {"status":"ok","reference":"...","debt_reference":"...","customer_id":N}
//   {"status":"stock_conflict","productId":N}
//   {"status":"idempotency_conflict"}
//   {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';
require __DIR__ . '/../../includes/debt.php';

[$script, $method, $linesSpec, $token, $userId, $customerMode, $goAt] = $argv;
$userId = (int) $userId;
$goAt = (float) $goAt;
$idempotencyToken = $token === '_NONE_' ? null : $token;

$lines = [];
foreach (explode(',', $linesSpec) as $pair) {
    [$productId, $qty] = explode(':', $pair);
    $lines[] = ['product_id' => (int) $productId, 'qty' => (float) $qty, 'price' => 1.0];
}

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    if ($method === 'credit') {
        $customerId = null;
        $newCustomerName = null;
        $newCustomerPhone = null;
        if (str_starts_with($customerMode, 'existing:')) {
            $customerId = (int) substr($customerMode, strlen('existing:'));
        } elseif (str_starts_with($customerMode, 'new:')) {
            [, $newCustomerName, $newCustomerPhone] = explode(':', $customerMode, 3);
        } else {
            throw new RuntimeException("pos_sale_race.php: unrecognized customerMode '$customerMode'");
        }

        $result = recordCreditSale($pdo, $lines, date('Y-m-d'), $userId, $customerId, $newCustomerName, $newCustomerPhone, null, $idempotencyToken, true);
        echo json_encode([
            'status' => 'ok',
            'reference' => $result['reference'],
            'debt_reference' => $result['debt_reference'],
            'customer_id' => $result['customer_id'],
        ]) . "\n";
    } else {
        $reference = recordStockOut($pdo, $lines, date('Y-m-d'), 'concurrency test', $userId, 'sale', null, $idempotencyToken, true);
        echo json_encode(['status' => 'ok', 'reference' => $reference]) . "\n";
    }
} catch (StockConflictException $e) {
    echo json_encode(['status' => 'stock_conflict', 'productId' => $e->productId]) . "\n";
} catch (IdempotencyConflictException $e) {
    echo json_encode(['status' => 'idempotency_conflict']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
