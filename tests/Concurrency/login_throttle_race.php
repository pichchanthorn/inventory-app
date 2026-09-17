<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the Phase K2-C (login brute-force
// protection) concurrency case. Calls the real, unmodified
// recordFailedLogin() from includes/login_throttle.php on its own
// connection, in autocommit - that function owns no transaction and
// needs none, which is the property under test.
//
// Usage: php login_throttle_race.php <email> <goAtMicrotime>
// Prints one JSON line: {"status":"ok"} or
// {"status":"error","message":"..."}
// ================================================

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/login_throttle.php';

[$script, $email, $goAt] = $argv;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    recordFailedLogin($pdo, $email);
    echo json_encode(['status' => 'ok']) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
