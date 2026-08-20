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

// Builds the next "PREFIX-000123" reference — identical logic to what
// each page already did, just relocated. Left unchanged: reference
// collisions are already handled safely by the existing UNIQUE constraint
// on stock_transactions.reference plus the generic catch below.
function nextStockReference(PDO $pdo, string $prefix) {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM stock_transactions')->fetchColumn() + 1;
    return $prefix . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

// Stock In: increases current_stock for each line. No concurrency guard
// needed — an increment can never drive stock negative no matter what
// else happens concurrently.
function recordStockIn(PDO $pdo, array $lines, string $date, ?int $supplierId, string $note, int $userId) {
    try {
        $pdo->beginTransaction();
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
function recordStockOut(PDO $pdo, array $lines, string $date, string $note, int $userId, string $type = 'out') {
    if (!in_array($type, ['out', 'sale'], true)) {
        throw new InvalidArgumentException("Invalid stock-out type '$type' - must be 'out' or 'sale'.");
    }

    try {
        $pdo->beginTransaction();
        // Reference prefix follows the transaction type: a POS sale gets
        // SAL-, a manual Stock Out keeps the existing STO- prefix. $type
        // already defaults to 'out', so this is a no-op for every existing
        // caller - it only changes behavior when 'sale' is passed.
        $reference = nextStockReference($pdo, $type === 'sale' ? 'SAL' : 'STO');

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,NULL,?)');
        $stmt->execute([$reference, $type, $date, $note, $userId]);
        $txId = $pdo->lastInsertId();

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
