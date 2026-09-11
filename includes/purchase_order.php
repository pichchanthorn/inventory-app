<?php
// ================================================
// Purchase / Supplier Order Management (Phase P1) - draft-only PO core.
// Requires includes/stock.php to already be loaded, for
// nextReferenceSequence() - same documented-but-not-self-required
// dependency convention includes/debt.php already uses for the exact
// same function (see that file's own header comment). Self-requires
// audit.php directly (require_once, safe to double-load) since this
// file calls logAudit() itself, the same reasoning includes/stock.php's
// own require_once of audit.php already documents.
//
// Strict P1 scope: create/view/edit/delete a DRAFT Purchase Order only.
// No status transition other than the implicit 'draft' written at
// create time ever happens here - submit/cancel/receive are P2/P3, not
// implemented in this file at all. purchase_order_items.received_qty is
// never written to anything but its schema default (0) by any function
// below.
// ================================================

require_once __DIR__ . '/audit.php';

// Thrown when a PO id does not resolve to any purchase_orders row.
class PurchaseOrderNotFoundException extends RuntimeException {
    public $poId;
    public function __construct(int $poId) {
        parent::__construct('Purchase Order ' . $poId . ' not found');
        $this->poId = $poId;
    }
}

// Thrown when an edit/delete is attempted against a PO whose status is
// not 'draft' - P1 has no code path that can ever create a non-draft
// PO, so this only fires against a PO some later phase (or manual DB
// edit) advanced past draft. Carries the actual status so a caller can
// show a precise message ("this PO is already ordered") rather than a
// generic rejection.
class PurchaseOrderNotDraftException extends RuntimeException {
    public $poId;
    public $status;
    public function __construct(int $poId, string $status) {
        parent::__construct('Purchase Order ' . $poId . ' is not a draft (status: ' . $status . ')');
        $this->poId = $poId;
        $this->status = $status;
    }
}

// "PUR-000123" - same nextReferenceSequence()-backed pattern as
// nextStockReference()/nextDebtReference(), its own independent counter
// key ('purchase_orders', migration 016) rather than sharing
// 'stock_transactions' - a Purchase Order is a structurally different
// entity from a stock_transactions row.
function nextPurchaseOrderReference(PDO $pdo): string {
    $n = nextReferenceSequence($pdo, 'purchase_orders');
    return 'PUR-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

// Creates a draft Purchase Order and its line items, all in ONE
// transaction. Owns the transaction end to end - same shape as
// recordStockIn()/recordCreditSale().
//
// $idempotencyToken (per-form-render, same mechanism/placement as every
// other write path in this app): claimed as the FIRST statement inside
// this transaction, before even the supplier-existence check, so a
// duplicate submission (double-click, browser retry, two tabs) can
// never create two POs. Optional/nullable purely so a future direct/
// test caller that doesn't need it isn't forced to fabricate one - the
// real UI (purchase-order/index.php) always supplies one.
//
// $items: each entry is ['product_id'=>int, 'ordered_qty'=>int,
// 'unit_cost'=>float]. Caller is responsible for validating quantity/
// cost FORMAT (non-negative-integer-string, no silent truncation;
// non-negative numeric cost) before calling this - the same division of
// responsibility recordStockIn()/recordCreditSale() already document
// for $lines (see recordCreditSale()'s own docblock in includes/
// debt.php). This function's own responsibility is what only a DB
// round-trip can decide: that $supplierId and every $item['product_id']
// actually exist, checked here under the same transaction so a
// nonexistent id can never slip through as a dangling FK - MySQL/
// MariaDB would reject it anyway via the FK constraint, but checking
// explicitly first produces a precise, friendly exception instead of a
// raw PDOException.
//
// Returns ['id'=>int, 'reference'=>string] - the PO id and its PUR
// reference, everything a caller needs to redirect to the new PO's
// detail page.
function createPurchaseOrder(PDO $pdo, int $supplierId, string $orderDate, ?string $expectedDate, ?string $note, array $items, int $userId, ?string $idempotencyToken = null): array {
    try {
        $pdo->beginTransaction();
        if ($idempotencyToken !== null) {
            claimIdempotencyToken($pdo, $idempotencyToken, $userId);
        }

        $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('supplier not found');
        }

        $reference = nextPurchaseOrderReference($pdo);

        $stmt = $pdo->prepare('INSERT INTO purchase_orders (reference, supplier_id, status, order_date, expected_date, note, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$reference, $supplierId, 'draft', $orderDate, $expectedDate, $note, $userId, $userId]);
        $poId = (int) $pdo->lastInsertId();

        $itemsSnapshot = [];
        foreach ($items as $item) {
            $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
            $stmt->execute([$item['product_id']]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('product not found: ' . $item['product_id']);
            }

            $stmt = $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, unit_cost) VALUES (?,?,?,?)');
            $stmt->execute([$poId, $item['product_id'], $item['ordered_qty'], $item['unit_cost']]);
            $itemsSnapshot[] = [
                'id' => (int) $pdo->lastInsertId(),
                'product_id' => (int) $item['product_id'],
                'ordered_qty' => (int) $item['ordered_qty'],
                'unit_cost' => (float) $item['unit_cost'],
            ];
        }

        logAudit($pdo, $userId, 'create', 'purchase_order', $poId, null, [
            'name' => $reference,
            'reference' => $reference,
            'supplier_id' => $supplierId,
            'status' => 'draft',
            'order_date' => $orderDate,
            'expected_date' => $expectedDate,
            'note' => $note,
            'items' => $itemsSnapshot,
        ]);

        $pdo->commit();
        return ['id' => $poId, 'reference' => $reference];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Edits a draft Purchase Order's header fields and fully replaces its
// line items, in ONE transaction. Owns the transaction end to end.
//
// Draft-only, enforced server-side under the row lock - never trusts
// the UI: locks the PO row first (SELECT ... FOR UPDATE), verifies it
// exists, then verifies status is still 'draft' at the moment of the
// lock (not merely what an earlier, un-locked read showed) before any
// mutation happens. Throws PurchaseOrderNotFoundException /
// PurchaseOrderNotDraftException rather than silently no-op'ing, so a
// caller can show a precise message.
//
// Full delete-then-reinsert of purchase_order_items is safe in P1
// specifically because a draft PO can have no receiving history yet (P3
// doesn't exist) - there is nothing downstream that could reference a
// specific purchase_order_items.id across this edit. This assumption
// MUST be revisited before P3 ships: once purchase_order_receipts can
// reference a purchase_order_items row, a line that has already been
// partially received must never be silently deleted and recreated with
// a new id.
//
// Never writes 'status' or 'received_qty' to anything - this function
// has no parameter for either, so there is no code path through which a
// caller (however malicious the raw POST data) could smuggle a status
// change through an edit.
function updatePurchaseOrder(PDO $pdo, int $poId, int $supplierId, string $orderDate, ?string $expectedDate, ?string $note, array $items, int $userId): void {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$poId]);
        $before = $stmt->fetch();
        if ($before === false) {
            throw new PurchaseOrderNotFoundException($poId);
        }
        if ($before['status'] !== 'draft') {
            throw new PurchaseOrderNotDraftException($poId, $before['status']);
        }
        // Same "name" alias every other module's audit snapshot uses,
        // so audit/index.php's existing (name ?? name ?? '-') fallback
        // shows the PO's own reference instead of a blank Entity column -
        // purchase_orders has no 'name' column of its own, 'reference'
        // fills the identical role.
        $before['name'] = $before['reference'];

        $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('supplier not found');
        }

        $stmt = $pdo->prepare('UPDATE purchase_orders SET supplier_id=?, order_date=?, expected_date=?, note=?, updated_by=? WHERE id=?');
        $stmt->execute([$supplierId, $orderDate, $expectedDate, $note, $userId, $poId]);

        $stmt = $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$poId]);

        $itemsSnapshot = [];
        foreach ($items as $item) {
            $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
            $stmt->execute([$item['product_id']]);
            if ($stmt->fetchColumn() === false) {
                throw new InvalidArgumentException('product not found: ' . $item['product_id']);
            }

            $stmt = $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, ordered_qty, unit_cost) VALUES (?,?,?,?)');
            $stmt->execute([$poId, $item['product_id'], $item['ordered_qty'], $item['unit_cost']]);
            $itemsSnapshot[] = [
                'id' => (int) $pdo->lastInsertId(),
                'product_id' => (int) $item['product_id'],
                'ordered_qty' => (int) $item['ordered_qty'],
                'unit_cost' => (float) $item['unit_cost'],
            ];
        }

        logAudit($pdo, $userId, 'update', 'purchase_order', $poId, $before, [
            'name' => $before['reference'],
            'supplier_id' => $supplierId,
            'status' => 'draft',
            'order_date' => $orderDate,
            'expected_date' => $expectedDate,
            'note' => $note,
            'items' => $itemsSnapshot,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Hard-deletes a draft Purchase Order and (via ON DELETE CASCADE) its
// line items, in ONE transaction. Draft-only, enforced BOTH by the
// locked read-then-check below (what lets the caller distinguish "not
// found" from "not a draft") AND by an explicit status='draft'
// condition on the DELETE statement itself - defense-in-depth, the same
// "trust the lock, but still write the guard" convention every guarded
// UPDATE in includes/stock.php already follows, even though nothing can
// actually change this row's status between the SELECT ... FOR UPDATE
// and the DELETE while the lock is held.
//
// Does not itself catch/translate a MySQL 1451 (FK constraint) error -
// not expected in P1 (nothing can yet reference a purchase_orders row
// besides its own cascading purchase_order_items), but if a future
// phase's FK ever makes this reachable, translating the raw PDOException
// into a friendly message is the caller's responsibility, the same
// convention product/index.php's own delete handler already follows.
function deletePurchaseOrder(PDO $pdo, int $poId, int $userId): void {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$poId]);
        $before = $stmt->fetch();
        if ($before === false) {
            throw new PurchaseOrderNotFoundException($poId);
        }
        if ($before['status'] !== 'draft') {
            throw new PurchaseOrderNotDraftException($poId, $before['status']);
        }
        $before['name'] = $before['reference'];

        $stmt = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ? AND status = 'draft'");
        $stmt->execute([$poId]);
        if ($stmt->rowCount() === 0) {
            // Unreachable given the row lock above; kept as a hard
            // safety net rather than assuming the lock alone suffices.
            throw new PurchaseOrderNotDraftException($poId, $before['status']);
        }

        logAudit($pdo, $userId, 'delete', 'purchase_order', $poId, $before, null);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
