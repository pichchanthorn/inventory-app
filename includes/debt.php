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
//
// $idempotencyToken (Phase I2-B1): POS's credit-sale path passes its
// per-form-render token here, claimed as the very first statement in
// this transaction - before even the possible new-customer insert - so
// a duplicate submission can never create a second customer row, a
// second sale, or a second debt. See includes/stock.php's
// claimIdempotencyToken() for how the claim itself works.
function recordCreditSale(PDO $pdo, array $lines, string $date, int $userId, ?int $customerId, ?string $newCustomerName, ?string $newCustomerPhone, ?string $dueDate, ?string $idempotencyToken = null): array {
    try {
        $pdo->beginTransaction();

        if ($idempotencyToken !== null) {
            claimIdempotencyToken($pdo, $idempotencyToken, $userId);
        }

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

// Thrown when the guarded UPDATE in recordDebtPayment() affects 0 rows:
// the payment would take paid_amount past total_amount, either because
// the caller's own pre-check was working from a stale $balance (another
// payment landed first) or because it was skipped entirely. Same "guard
// condition lives in the UPDATE's WHERE clause, not a separate SELECT
// beforehand" concurrency pattern as StockConflictException in
// includes/stock.php - the CHECK constraint on customer_debts.paid_amount
// is only a backstop, this is what actually prevents two concurrent
// partial payments from together overpaying a debt.
class DebtOverpaymentException extends RuntimeException {
    public $debtId;
    public function __construct(int $debtId) {
        parent::__construct('Payment would overpay debt ' . $debtId);
        $this->debtId = $debtId;
    }
}

// Records one payment against an existing debt: increments
// customer_debts.paid_amount (balance/status recompute automatically -
// they're GENERATED ALWAYS columns, see the migration), then appends the
// payment to customer_debt_payments, all in one transaction. Caller is
// responsible for validating $amount > 0 before calling this, same
// division of responsibility recordCreditSale() expects of its own
// caller for $lines.
function recordDebtPayment(PDO $pdo, int $debtId, float $amount, string $paymentDate, string $note, int $userId): void {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('UPDATE customer_debts SET paid_amount = paid_amount + ?, updated_by = ? WHERE id = ? AND paid_amount + ? <= total_amount');
        $stmt->execute([$amount, $userId, $debtId, $amount]);
        if ($stmt->rowCount() === 0) {
            throw new DebtOverpaymentException($debtId);
        }

        $stmt = $pdo->prepare('INSERT INTO customer_debt_payments (debt_id, amount, payment_date, note, created_by) VALUES (?,?,?,?,?)');
        $stmt->execute([$debtId, $amount, $paymentDate, $note !== '' ? $note : null, $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
