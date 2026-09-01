<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the P0 #17 (Reference Numbers) concurrency
// case. Calls the real, unmodified nextReferenceSequence() from
// includes/stock.php inside its own transaction (as required by that
// function's own contract), on its own connection.
//
// Usage: php reference_race.php <counterKey> <goAtMicrotime>
// Prints one JSON line: {"status":"ok","value":123} or
// {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/stock.php';

[$script, $counterKey, $goAt] = $argv;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $pdo->beginTransaction();
    $value = nextReferenceSequence($pdo, $counterKey);
    $pdo->commit();
    echo json_encode(['status' => 'ok', 'value' => $value]) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
