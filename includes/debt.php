<?php
// ================================================
// Customer debt/credit-sale logic (Batch 2 of the Debt/Customer Credit
// tracking feature - see database/migrations/010_add_customer_debt_
// tracking.sql for the full schema design and reasoning). Requires
// includes/stock.php to already be loaded, for insertStockOutLines()
// and StockConflictException.
// ================================================

// Same "PREFIX-000123" pattern as nextStockReference() in
// includes/stock.php, just counting customer_debts instead of
// stock_transactions - debts aren't stock_transactions, so they get
// their own reference sequence rather than sharing one.
function nextDebtReference(PDO $pdo): string {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn() + 1;
    return 'DBT-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

// Records a POS credit sale: a normal 'sale' stock_transaction (product
// still leaves inventory the same way a cash sale does - see the
// migration comment on customer_debts.stock_transaction_id for why this
// deliberately isn't a new stock_transactions.type value) plus a
// customer_debts row linking to it, all in ONE transaction. If a new
// customer is being created inline (customerId is null), that insert is
// part of the same transaction too - so a mid-transaction failure (e.g.
// insufficient stock on a later line) rolls back the customer insert
// along with everything else, rather than leaving an orphan customer
// row with no matching sale.
//
// $lines: same shape as recordStockOut() expects - each
// ['product_id','qty','price']. Caller is responsible for validating
// $lines (non-empty, non-negative price) before calling this, same
// division of responsibility as recordStockOut().
//
// Exactly one of $customerId or $newCustomerName must be provided by
// the caller (enforced by pos/index.php's own validation before this is
// called) - this function trusts that and just acts on whichever is
// non-null, preferring an existing $customerId if both were somehow set.
//
// Returns an array with everything the caller needs to build the
// receipt: sale reference, debt reference, resolved customer id/name,
// and the server-computed total (same "server total is the only one
// that decides anything" principle as pos/index.php's cash-sale path).
function recordCreditSale(PDO $pdo, array $lines, string $date, int $userId, ?int $customerId, ?string $newCustomerName, ?string $newCustomerPhone, ?string $dueDate): array {
    try {
        $pdo->beginTransaction();

        if ($customerId === null) {
            $stmt = $pdo->prepare('INSERT INTO customers (name, phone, created_by, updated_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$newCustomerName, $newCustomerPhone !== '' ? $newCustomerPhone : null, $userId, $userId]);
            $customerId = (int) $pdo->lastInsertId();
        }
        $customerName = $newCustomerName;
        if ($customerName === null) {
            $stmt = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
            $stmt->execute([$customerId]);
            $customerName = $stmt->fetchColumn() ?: null;
        }

        $reference = nextStockReference($pdo, 'SAL');
        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id, cash_received) VALUES (?,?,?,?,NULL,?,NULL)');
        $stmt->execute([$reference, 'sale', $date, '', $userId]);
        $txId = $pdo->lastInsertId();

        insertStockOutLines($pdo, $txId, $lines);

        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['qty'] * $line['price'];
        }

        $debtReference = nextDebtReference($pdo);
        $stmt = $pdo->prepare('INSERT INTO customer_debts (reference, customer_id, stock_transaction_id, total_amount, due_date, created_by, updated_by) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$debtReference, $customerId, $txId, $total, $dueDate !== '' ? $dueDate : null, $userId, $userId]);

        $pdo->commit();
        return [
            'reference' => $reference,
            'debt_reference' => $debtReference,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'total' => $total,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
