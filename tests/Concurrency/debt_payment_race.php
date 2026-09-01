<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the P0 #14 (Concurrent Payment) test. Same
// pattern as stock_out_race.php - a genuinely separate process/connection
// calling the real, unmodified recordDebtPayment() from includes/debt.php.
//
// Usage: php debt_payment_race.php <debtId> <amount> <userId> <goAtMicrotime>
// Prints one JSON line: {"status":"ok"} or {"status":"overpaid"}
// or {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';
require __DIR__ . '/../../includes/debt.php';

[$script, $debtId, $amount, $userId, $goAt] = $argv;
$debtId = (int) $debtId;
$amount = (float) $amount;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    recordDebtPayment($pdo, $debtId, $amount, date('Y-m-d'), 'concurrency test', $userId);
    echo json_encode(['status' => 'ok']) . "\n";
} catch (DebtOverpaymentException $e) {
    echo json_encode(['status' => 'overpaid']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
