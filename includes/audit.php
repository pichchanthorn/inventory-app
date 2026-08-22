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

// The ONLY function permitted to build a users-table audit snapshot.
// Every users write path (user/index.php, profile.php, and
// auth/register.php if in scope) must route through this - never
// hand-build a users snapshot, never pass $user or a raw fetch() row
// straight to logAudit().
//
// This is an explicit field allowlist, not $row with password
// unset() - it is built as a literal array from named keys, so it
// physically never reads $row['password']. A future column added to
// `users` (a 2FA secret, an API token, anything) is excluded by
// default and requires a deliberate edit here to ever appear in an
// audit snapshot, rather than being silently swept in by a SELECT *
// the way an unset()-based approach would.
function userAuditSnapshot(array $row): array {
    return [
        'id'                   => (int) $row['id'],
        'name'                 => $row['name'],
        'email'                => $row['email'],
        'role_id'              => (int) $row['role_id'],
        'avatar'               => $row['avatar'],
        'must_change_password' => (int) $row['must_change_password'],
        'created_at'           => $row['created_at'],
        'updated_at'           => $row['updated_at'] ?? null,
        'created_by'           => $row['created_by'] ?? null,
        'updated_by'           => $row['updated_by'] ?? null,
    ];
}
