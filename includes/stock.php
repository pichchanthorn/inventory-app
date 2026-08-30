<?php
// ================================================
// Shared stock-mutation logic used by Stock In, Stock Out, and Stock
// Adjustment (and, later, POS). Each function owns its own DB transaction
// end to end: create the stock_transactions header, create the line
// item(s), update products.current_stock, commit on success, roll back on
// any failure.
//
// Concurrency safety lives in the UPDATE statements themselves (see
// recordStockOut / adjustStock below), not in a separate SELECT-then-
// check-in-PHP step beforehand — two concurrent requests touching the
// same product can never both succeed and drive stock negative or
// silently overwrite each other, because the guard condition is checked
// by the database at the moment of the write, under the row lock that
// UPDATE already takes.
// ================================================

// Thrown when a guarded UPDATE affects 0 rows: either Stock Out couldn't
// find enough stock at the moment of the write, or Stock Adjustment's
// optimistic-lock check found the product had already changed since it
// was read. Callers catch this specifically to show a precise, friendly
// message instead of the generic transaction-failed fallback.
class StockConflictException extends RuntimeException {
    public $productId;
    public function __construct(int $productId) {
        parent::__construct('Stock conflict for product ' . $productId);
        $this->productId = $productId;
    }
}

// Thrown when claimIdempotencyToken() finds its token already claimed -
// i.e. this exact submission (a double-click, a browser retry, two
// tabs, a network timeout followed by a resubmit) was already recorded.
// Callers catch this specifically to show a "this looks like a repeat
// submission" message instead of the generic transaction-failed
// fallback - see pos/index.php.
class IdempotencyConflictException extends RuntimeException {}

// Server-side idempotency for POS sale submissions (Phase I2-B1) -
// lives here rather than in a POS-specific file since both POS's cash
// path (recordStockOut, below) and its credit path (recordCreditSale,
// includes/debt.php) need it, and debt.php already requires this file.
//
// The entire mechanism is one INSERT into idempotency_keys, whose
// UNIQUE constraint on `token` makes the claim atomic across genuinely
// concurrent requests under InnoDB's row lock - the same "let the
// database's own constraint carry the concurrency guarantee" principle
// as the guarded UPDATEs elsewhere in this file, rather than a
// SELECT-then-INSERT check in PHP that a second request could race past.
//
// MUST be called as the first statement inside the caller's own
// beginTransaction() block (not before it) - that's what makes a failed/
// rolled-back sale attempt release its claim automatically: if anything
// later in the same transaction fails, the whole transaction (including
// this INSERT) rolls back together, leaving the token claimable again
// for a legitimate retry. Only a transaction that actually commits
// permanently consumes its token.
function claimIdempotencyToken(PDO $pdo, string $token, int $userId): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO idempotency_keys (token, user_id) VALUES (?, ?)');
        $stmt->execute([$token, $userId]);
    } catch (PDOException $e) {
        // MySQL/MariaDB error 1062: "Duplicate entry ... for key" - the
        // UNIQUE constraint on token rejected a repeat, same precise-
        // error-code check as product/index.php's delete-has-history
        // (1451) and customer/index.php's delete-has-debts (1451) cases.
        if (($e->errorInfo[1] ?? null) === 1062) {
            throw new IdempotencyConflictException('Idempotency token already claimed');
        }
        throw $e;
    }
}

// Atomic, per-key sequence counter (Phase I3-A) backing
// nextStockReference() below and includes/debt.php's nextDebtReference()
// - see database/migrations/012_add_reference_counters.sql for the
// schema and full reasoning. Replaces the old SELECT COUNT(*) + 1 read,
// which raced under concurrent requests (two callers could read the same
// count and collide on stock_transactions.reference's UNIQUE constraint,
// failing one caller's otherwise-legitimate transaction).
//
// MUST be called after the caller's own beginTransaction() - same
// requirement claimIdempotencyToken() already has. SELECT ... FOR UPDATE
// takes an exclusive row lock on the one counter row for $counterKey,
// held for the rest of the caller's transaction: a second concurrent
// caller's SELECT ... FOR UPDATE on that same row blocks until this one
// commits or rolls back, so two callers can never read the same
// next_value. A rolled-back caller's UPDATE rolls back with it, so a
// failed attempt never permanently consumes a number.
function nextReferenceSequence(PDO $pdo, string $counterKey): int {
    $stmt = $pdo->prepare('SELECT next_value FROM reference_counters WHERE counter_key = ? FOR UPDATE');
    $stmt->execute([$counterKey]);
    $n = $stmt->fetchColumn();
    if ($n === false) {
        throw new RuntimeException("Missing reference_counters row for '$counterKey' - check migration 012 was applied.");
    }
    $n = (int) $n;

    $stmt = $pdo->prepare('UPDATE reference_counters SET next_value = ? WHERE counter_key = ?');
    $stmt->execute([$n + 1, $counterKey]);

    return $n;
}

// Builds the next "PREFIX-000123" reference - same format as before
// Phase I3-A, only the counter underneath changed. All of STI/STO/ADJ/
// SAL share ONE counter ('stock_transactions'), not one each - matching
// the original COUNT(*) FROM stock_transactions, which never filtered by
// type either.
function nextStockReference(PDO $pdo, string $prefix) {
    $n = nextReferenceSequence($pdo, 'stock_transactions');
    return $prefix . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

// Stock In: increases current_stock for each line. No concurrency guard
// needed — an increment can never drive stock negative no matter what
// else happens concurrently.
// $idempotencyToken (Phase I3-B): stock-in/index.php's per-form-render
// token, claimed as the very first statement in this transaction - same
// placement/reasoning as recordStockOut()'s own token above - so a
// duplicate submission (double-click, browser retry, two tabs) can never
// double-increment current_stock. Defaults to null so any future direct
// caller that doesn't pass one behaves exactly as before this phase.
function recordStockIn(PDO $pdo, array $lines, string $date, ?int $supplierId, string $note, int $userId, ?string $idempotencyToken = null) {
    try {
        $pdo->beginTransaction();
        if ($idempotencyToken !== null) {
            claimIdempotencyToken($pdo, $idempotencyToken, $userId);
        }
        $reference = nextStockReference($pdo, 'STI');

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$reference, 'in', $date, $note, $supplierId, $userId]);
        $txId = $pdo->lastInsertId();

        foreach ($lines as $line) {
            $subtotal = $line['qty'] * $line['cost'];
            $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,?,?)');
            $stmt->execute([$txId, $line['product_id'], $line['qty'], $line['cost'], $subtotal]);

            $stmt = $pdo->prepare('UPDATE products SET current_stock = current_stock + ? WHERE id = ?');
            $stmt->execute([$line['qty'], $line['product_id']]);
        }

        $pdo->commit();
        return $reference;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Shared inner loop for a stock-decreasing transaction: insert each
// line item, then guard-decrement current_stock the same
// UPDATE ... WHERE current_stock >= ? way described above. Does NOT
// manage its own transaction - must be called from within the caller's
// own beginTransaction()/commit(). Used by recordStockOut() below and
// by recordCreditSale() (includes/debt.php), so a credit sale's stock
// decrement gets the exact same concurrency guarantee as a cash sale or
// a manual Stock Out, with no duplicated logic to drift out of sync.
function insertStockOutLines(PDO $pdo, int $txId, array $lines): void {
    foreach ($lines as $line) {
        $subtotal = $line['qty'] * $line['price'];
        $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,?,?)');
        $stmt->execute([$txId, $line['product_id'], $line['qty'], $line['price'], $subtotal]);

        $stmt = $pdo->prepare('UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND current_stock >= ?');
        $stmt->execute([$line['qty'], $line['product_id'], $line['qty']]);
        if ($stmt->rowCount() === 0) {
            throw new StockConflictException($line['product_id']);
        }
    }
}

// Stock Out: decreases current_stock for each line via an atomic
// UPDATE ... WHERE current_stock >= ?, so "is there enough stock" is
// checked in the same statement as the write, under InnoDB's row lock —
// not as a separate SELECT beforehand that a concurrent request could
// race past. 0 rows affected means insufficient stock at the moment of
// the write; throws StockConflictException so the caller can rebuild the
// existing "not enough stock for X" message.
//
// $type distinguishes a manual Stock Out from a future POS sale — both
// decrease stock the same way, but are logged as different transaction
// types for reporting. Defaults to 'out' so every existing caller keeps
// working unmodified.
// $cashReceived is only meaningful for 'sale' — persisted as NULL for
// every other type regardless of what's passed in, so the DB invariant
// ("only sale rows have this populated") doesn't depend on caller
// discipline. Defaults to null, so the existing Stock Out call site
// (which never passes it) is unaffected.
// $idempotencyToken: POS's cash-sale path (Phase I2-B1) passes its
// per-form-render token here; Stock Out's own call site never passes
// one, so it defaults to null and claimIdempotencyToken() is skipped
// entirely for that caller - Stock Out's behavior is completely
// unchanged.
function recordStockOut(PDO $pdo, array $lines, string $date, string $note, int $userId, string $type = 'out', ?float $cashReceived = null, ?string $idempotencyToken = null) {
    if (!in_array($type, ['out', 'sale'], true)) {
        throw new InvalidArgumentException("Invalid stock-out type '$type' - must be 'out' or 'sale'.");
    }

    try {
        $pdo->beginTransaction();
        // Claimed first, before anything else in this transaction (even
        // before a reference number is generated) - a duplicate
        // submission is rejected immediately, without side effects.
        if ($idempotencyToken !== null) {
            claimIdempotencyToken($pdo, $idempotencyToken, $userId);
        }
        // Reference prefix follows the transaction type: a POS sale gets
        // SAL-, a manual Stock Out keeps the existing STO- prefix. $type
        // already defaults to 'out', so this is a no-op for every existing
        // caller - it only changes behavior when 'sale' is passed.
        $reference = nextStockReference($pdo, $type === 'sale' ? 'SAL' : 'STO');

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id, cash_received) VALUES (?,?,?,?,NULL,?,?)');
        $stmt->execute([$reference, $type, $date, $note, $userId, $type === 'sale' ? $cashReceived : null]);
        $txId = $pdo->lastInsertId();

        insertStockOutLines($pdo, $txId, $lines);

        $pdo->commit();
        return $reference;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Stock Adjustment: sets current_stock to an exact value, guarded by
// optimistic locking — UPDATE ... WHERE current_stock = ? using the value
// read moments earlier. If the product's stock changed underneath it
// (a concurrent Stock In, Stock Out, or another Adjustment) between the
// read and this write, 0 rows are affected and StockConflictException is
// thrown instead of silently overwriting that concurrent change.
function adjustStock(PDO $pdo, int $productId, float $newQty, float $currentQty, string $reason, string $date, int $userId) {
    try {
        $pdo->beginTransaction();
        $reference = nextStockReference($pdo, 'ADJ');

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,NULL,?)');
        $stmt->execute([$reference, 'adjustment', $date, $reason, $userId]);
        $txId = $pdo->lastInsertId();

        $diff = abs($newQty - $currentQty);
        $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,0,0)');
        $stmt->execute([$txId, $productId, $diff]);

        $stmt = $pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ? AND current_stock = ?');
        $stmt->execute([$newQty, $productId, $currentQty]);
        if ($stmt->rowCount() === 0) {
            throw new StockConflictException($productId);
        }

        $pdo->commit();
        return $reference;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
