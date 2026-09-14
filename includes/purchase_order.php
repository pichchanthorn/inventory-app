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

// Phase P2: thrown when receivePurchaseOrder() is called against a PO
// whose status is not 'ordered' or 'partially_received' - a draft
// (nothing to receive yet), an already-fully-received PO, or (in a
// future phase) a cancelled one. Carries the actual status for the same
// precise-message reason as PurchaseOrderNotDraftException above.
class PurchaseOrderNotReceivableException extends RuntimeException {
    public $poId;
    public $status;
    public function __construct(int $poId, string $status) {
        parent::__construct('Purchase Order ' . $poId . ' is not receivable (status: ' . $status . ')');
        $this->poId = $poId;
        $this->status = $status;
    }
}

// Phase P2: thrown when a posted purchase_order_items id does not belong
// to the posted purchase_order_id - either the item id does not exist at
// all, or it exists but for a DIFFERENT PO. This is the server-side
// ownership check that must never trust a client-supplied parent/child
// relationship, the same discipline ProductBatchNotFoundException
// (includes/stock.php) already enforces for a batch id under a product.
class PurchaseOrderItemMismatchException extends RuntimeException {
    public $poId;
    public $poItemId;
    public function __construct(int $poId, int $poItemId) {
        parent::__construct('Purchase order item ' . $poItemId . ' does not belong to Purchase Order ' . $poId);
        $this->poId = $poId;
        $this->poItemId = $poItemId;
    }
}

// Phase P2: thrown when the guarded UPDATE in receivePurchaseOrder()
// (below) affects 0 rows - the requested quantity would receive more
// than the line's remaining (ordered_qty - received_qty) allows. This is
// the correctness mechanism, not a PHP-side pre-check alone: the WHERE
// clause's "received_qty + ? <= ordered_qty" condition is evaluated
// atomically by the database at the moment of the write, so this
// exception firing means the write was genuinely rejected, not that a
// stale PHP-side read was merely inconsistent with a value read moments
// earlier.
class PurchaseOrderOverReceiveException extends RuntimeException {
    public $poId;
    public $poItemId;
    public function __construct(int $poId, int $poItemId) {
        parent::__construct('Requested quantity exceeds the remaining ordered quantity for purchase order item ' . $poItemId);
        $this->poId = $poId;
        $this->poItemId = $poItemId;
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

// Phase P2: submits a draft Purchase Order (draft -> ordered). Owns the
// transaction end to end - same shape as every other function in this
// file. No idempotency token: a duplicate Submit POST is naturally
// idempotent by construction (the second call's lock-then-check sees
// status='ordered' already and is rejected by
// PurchaseOrderNotDraftException, with zero side effect either time) -
// unlike Receive below, Submit never mutates inventory, so there is
// nothing a replay could double.
function submitPurchaseOrder(PDO $pdo, int $poId, int $userId): void {
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

        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'ordered', updated_by = ? WHERE id = ?");
        $stmt->execute([$userId, $poId]);

        logAudit($pdo, $userId, 'update', 'purchase_order', $poId, $before, [
            'name' => $before['reference'],
            'status' => 'ordered',
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Phase P2: receives against an 'ordered' or 'partially_received'
// Purchase Order. Owns ONE transaction end to end - idempotency claim,
// PO-domain locking/validation, the actual Stock In mutation, receipt
// linkage, status recompute, and audit all succeed or fail together.
//
// Lock order (must never be inverted - reviewed and confirmed against
// every other lock-taking function in this codebase, none of which ever
// touches a purchase_orders/purchase_order_items row, so there is no
// existing code path that could conflict with this order):
//   1. purchase_orders row (SELECT ... FOR UPDATE) - PO-domain, parent.
//   2. purchase_order_items rows - PO-domain, children. NOT locked via a
//      separate SELECT ... FOR UPDATE - the guarded UPDATE below (step
//      3) takes its own row lock atomically as part of the write itself,
//      exactly like insertStockOutLines()'s guarded current_stock
//      decrement (includes/stock.php) needs no separate prior lock
//      either. This is the "atomic DB guard is the correctness
//      mechanism, not a PHP-side pre-check alone" design: the plain
//      (non-locking) SELECT just above it only reads product_id/ordered_
//      qty/received_qty for ownership validation and the audit "before"
//      snapshot - it makes no concurrency-relevant decision itself.
//   3. insertStockInTransaction()'s OWN internal products/product_batches
//      locks - inventory-domain, entered only after 1+2 above are fully
//      done for every line being received this call. insertStockInTransaction()
//      is called completely unmodified; this function never touches a
//      products or product_batches row directly.
// No code in this function ever acquires a product/batch lock before the
// purchase_orders row lock - verified by inspection: the PO row lock is
// the first statement after the idempotency claim, and every subsequent
// statement either targets purchase_orders/purchase_order_items/
// purchase_order_receipts directly, or is the single call into
// insertStockInTransaction() which owns its own internal ordering.
//
// $receiptLines: each entry is ['purchase_order_item_id'=>int, 'qty'=>int,
// 'unit_cost'=>float, 'batch_number'=>?string, 'expiry_date'=>?string].
// Caller is responsible for validating qty/unit_cost FORMAT before
// calling this (non-negative-integer-string, no silent truncation;
// non-negative numeric cost) - the same division of responsibility
// createPurchaseOrder()'s own $items parameter already documents. This
// function's own responsibility is what only a DB round-trip under lock
// can decide: that every $purchase_order_item_id actually belongs to
// $poId (never trusts a client-supplied parent/child relationship - see
// PurchaseOrderItemMismatchException), and that the requested quantity
// does not exceed what remains (via the guarded UPDATE, never a PHP-side
// arithmetic check alone).
//
// Mapping contract this function relies on and itself preserves: builds
// one internal ordered array ($receiptPlan) from $receiptLines, derives
// the Stock In $lines for insertStockInTransaction() from that SAME
// array in that SAME order, and zips $receiptPlan[i]['purchase_order_item_id']
// with $result['item_ids'][i] to build each purchase_order_receipts row -
// relying on insertStockInTransaction()'s own documented guarantee
// (includes/stock.php) that item_ids is positionally aligned with its
// input $lines, with no reordering/filtering, ever (see
// tests/Integration/StockTest.php's
// testInsertStockInTransactionReturnsReferenceTransactionIdAndItemIdsInOrder
// for the existing proof of that producer-side contract, and this
// phase's own multi-line receiving test for the consumer side).
//
// Audits exactly once per successful call, unconditionally - even when
// the resulting status is unchanged (partially_received -> partially_received).
// The event, not the status delta, is what is audited (same "audit the
// event, not the value change" principle updatePurchaseOrder() already
// follows). The audit's 'received_this_event' key is what this
// specific call added, independent of the PO's cumulative state.
//
// Returns ['status'=>string, 'stock_reference'=>string, 'receipts'=>array]
// - the new PO status, the Stock In reference this receipt produced, and
// the per-line receipt linkage, everything a caller needs to show a
// confirmation and redirect to the PO detail page.
function receivePurchaseOrder(PDO $pdo, int $poId, array $receiptLines, string $date, ?string $note, int $userId, string $idempotencyToken): array {
    try {
        $pdo->beginTransaction();
        claimIdempotencyToken($pdo, $idempotencyToken, $userId);

        // Step 1: PO-domain parent lock, before anything else.
        $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$poId]);
        $po = $stmt->fetch();
        if ($po === false) {
            throw new PurchaseOrderNotFoundException($poId);
        }
        if (!in_array($po['status'], ['ordered', 'partially_received'], true)) {
            throw new PurchaseOrderNotReceivableException($poId, $po['status']);
        }

        if (!$receiptLines) {
            throw new InvalidArgumentException('at least one line must be received');
        }

        // Plain (non-locking) read: ownership validation + the audit
        // "before" snapshot only - makes no concurrency-relevant decision
        // itself (that is the guarded UPDATE below, per line).
        $stmt = $pdo->prepare('SELECT id, product_id, ordered_qty, received_qty FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$poId]);
        $itemsBeforeById = [];
        foreach ($stmt->fetchAll() as $row) {
            $itemsBeforeById[(int) $row['id']] = $row;
        }

        // Step 2 (PO-domain children) + build the ordered $receiptPlan
        // the mapping contract above depends on.
        $receiptPlan = [];
        foreach ($receiptLines as $line) {
            $poItemId = (int) $line['purchase_order_item_id'];
            $qty = (int) $line['qty'];
            if ($qty <= 0) {
                throw new InvalidArgumentException('received quantity must be a whole number greater than zero');
            }
            if (!isset($itemsBeforeById[$poItemId])) {
                throw new PurchaseOrderItemMismatchException($poId, $poItemId);
            }

            // The guarded UPDATE IS the concurrency guarantee - its own
            // WHERE clause is evaluated atomically by the database at
            // the moment of the write, not from the plain SELECT above.
            $stmt = $pdo->prepare('UPDATE purchase_order_items
                                    SET received_qty = received_qty + ?
                                    WHERE id = ? AND purchase_order_id = ? AND received_qty + ? <= ordered_qty');
            $stmt->execute([$qty, $poItemId, $poId, $qty]);
            if ($stmt->rowCount() === 0) {
                throw new PurchaseOrderOverReceiveException($poId, $poItemId);
            }

            $receiptPlan[] = [
                'purchase_order_item_id' => $poItemId,
                'product_id' => (int) $itemsBeforeById[$poItemId]['product_id'],
                'qty' => $qty,
                'unit_cost' => (float) $line['unit_cost'],
                'batch_number' => $line['batch_number'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
            ];
        }

        // Step 3: the ONE canonical Stock In mutation - reused, never
        // duplicated. $lines derived from $receiptPlan in the SAME order.
        $stockInLines = array_map(static fn(array $r): array => [
            'product_id' => $r['product_id'],
            'qty' => $r['qty'],
            'cost' => $r['unit_cost'],
            'batch_number' => $r['batch_number'],
            'expiry_date' => $r['expiry_date'],
        ], $receiptPlan);

        $stockInNote = ($note !== null && trim($note) !== '') ? $note : ('Receiving for ' . $po['reference']);
        $result = insertStockInTransaction($pdo, $stockInLines, $date, (int) $po['supplier_id'], $stockInNote, $userId);

        // Zip $receiptPlan[i] with $result['item_ids'][i] - the mapping
        // contract this whole design rests on (see this function's own
        // header comment).
        $receivedThisEvent = [];
        foreach ($receiptPlan as $i => $r) {
            $stiId = $result['item_ids'][$i];
            $stmt = $pdo->prepare('INSERT INTO purchase_order_receipts (purchase_order_item_id, stock_transaction_item_id, qty, created_by) VALUES (?,?,?,?)');
            $stmt->execute([$r['purchase_order_item_id'], $stiId, $r['qty'], $userId]);
            $receivedThisEvent[] = [
                'purchase_order_item_id' => $r['purchase_order_item_id'],
                'qty' => $r['qty'],
                'stock_transaction_item_id' => $stiId,
            ];
        }

        // Recompute status from a FRESH read of every line (post-write) -
        // never trusts the pre-write $itemsBeforeById snapshot, since
        // this call may have just changed some of those rows.
        $stmt = $pdo->prepare('SELECT id, ordered_qty, received_qty FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$poId]);
        $itemsAfter = $stmt->fetchAll();
        $allFullyReceived = true;
        foreach ($itemsAfter as $row) {
            if ((int) $row['received_qty'] < (int) $row['ordered_qty']) {
                $allFullyReceived = false;
                break;
            }
        }
        $newStatus = $allFullyReceived ? 'received' : 'partially_received';

        $stmt = $pdo->prepare('UPDATE purchase_orders SET status = ?, updated_by = ? WHERE id = ?');
        $stmt->execute([$newStatus, $userId, $poId]);

        // Audited unconditionally, even when $newStatus === $po['status']
        // (partially_received -> partially_received) - the event is what
        // is audited, not whether the status label happened to move.
        logAudit($pdo, $userId, 'update', 'purchase_order', $poId, [
            'name' => $po['reference'],
            'status' => $po['status'],
            'items' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'], 'ordered_qty' => (int) $row['ordered_qty'], 'received_qty' => (int) $row['received_qty'],
            ], array_values($itemsBeforeById)),
        ], [
            'name' => $po['reference'],
            'status' => $newStatus,
            'items' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'], 'ordered_qty' => (int) $row['ordered_qty'], 'received_qty' => (int) $row['received_qty'],
            ], $itemsAfter),
            'received_this_event' => $receivedThisEvent,
        ]);

        $pdo->commit();
        return ['status' => $newStatus, 'stock_reference' => $result['reference'], 'receipts' => $receivedThisEvent];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
