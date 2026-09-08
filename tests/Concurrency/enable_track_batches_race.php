<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the K4-5 Track Batches enablement concurrency
// test (tests/Concurrency/ConcurrencyTest.php). Run as a genuinely
// separate OS process with its own PDO connection - launched via
// proc_open(), never included directly by PHPUnit. Calls the real,
// unmodified enableTrackBatches() from includes/stock.php - no
// application-level lock/mutex of any kind is added here; whatever
// safety this test observes comes entirely from that function's own
// product-row lock (SELECT ... FOR UPDATE). Same shape as
// stock_in_batch_race.php.
//
// Usage: php enable_track_batches_race.php <productId> <userId> <goAtMicrotime>
// Prints one JSON line to stdout: {"status":"ok"} or
// {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';

[$script, $productId, $userId, $goAt] = $argv;
$productId = (int) $productId;
$userId = (int) $userId;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    enableTrackBatches($pdo, $productId, $userId);
    echo json_encode(['status' => 'ok']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
