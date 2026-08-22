<?php
// ================================================
// Shared audit-log writer for the Accountability Foundation feature.
// One pure function, deliberately entity-agnostic - it doesn't know
// anything about categories vs. units vs. suppliers (or, in later
// batches, products/users). Each caller builds its own $before/$after
// arrays and is responsible for shaping them correctly, same
// "trust the one call site" spirit as includes/currency.php.
//
// In particular: for any entity type where the row can carry sensitive
// data (most notably users.password), the CALLER must exclude that
// field before it ever reaches this function - logAudit() has no way
// to know which fields are sensitive for which table, and must not be
// asked to guess. Never widen this function itself to accept a raw
// PDO::fetch() row and "figure out" what to redact.
//
// Always called inside the same transaction as the mutation it's
// logging (see category/unit/supplier/index.php) - an audit trail that
// can silently fail to record a change isn't worth having, so a failed
// audit_log write must roll back the mutation too, not be swallowed.
// ================================================

// $before/$after: entity-level snapshots (the whole row, as an
// associative array) - null for $before on a 'create' action, null for
// $after on a 'delete' action. Never field-level diffs (Phase 1 scope).
function logAudit(PDO $pdo, int $actorId, string $action, string $entityType, int $entityId, ?array $before, ?array $after): void {
    $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, before_snapshot, after_snapshot) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $actorId,
        $action,
        $entityType,
        $entityId,
        $before !== null ? json_encode($before) : null,
        $after !== null ? json_encode($after) : null,
    ]);
}
