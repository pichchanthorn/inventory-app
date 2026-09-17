<?php
declare(strict_types=1);

// ================================================
// Standalone CLI worker for the Phase K2-D (privilege freshness)
// concurrency case. Performs the SAME freshness read that
// includes/auth_check.php performs on every authenticated request, in a
// tight poll, on its own connection - while the orchestrator commits a
// password reset in another process.
//
// What this is actually checking: includes/auth_check.php decides
// whether to keep a session alive by reading password_changed_at, and
// user/index.php writes the new hash and that timestamp in ONE
// statement. If a concurrent reader could ever see the new password
// without the new timestamp, a reset would silently fail to invalidate
// the sessions it was meant to end. This worker records every distinct
// (password, password_changed_at) pair it observes so the orchestrator
// can assert no such half-applied pair exists.
//
// Usage: php privilege_freshness_race.php <userId> <untilMicrotime> <goAtMicrotime>
// Prints one JSON line: {"status":"ok","pairs":[["<hash>","<ts>"],...]}
// ================================================

require __DIR__ . '/../../config/db.php';

[$script, $userId, $until, $goAt] = $argv;
$userId = (int) $userId;
$until = (float) $until;
$goAt = (float) $goAt;

while (microtime(true) < $goAt) {
    usleep(200);
}

try {
    $stmt = $pdo->prepare('SELECT password, password_changed_at FROM users WHERE id = ?');
    $seen = [];
    while (microtime(true) < $until) {
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $seen[$row['password'] . '|' . (string) $row['password_changed_at']] = [
                $row['password'],
                (string) $row['password_changed_at'],
            ];
        }
    }
    echo json_encode(['status' => 'ok', 'pairs' => array_values($seen)]) . "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]) . "\n";
}
