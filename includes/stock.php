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

// Thrown when a Stock Out line targets a track_batches=1 product but the
// caller did not opt into batch consumption (Phase K3-1, $consumeBatches
// on insertStockOutLines()/recordStockOut() below) - e.g. POS, before its
// own FEFO integration (a later phase) ships. Deliberately distinct from
// StockConflictException: that exception means "not enough stock exists"
// (an availability problem), whereas this means "this caller doesn't yet
// know how to allocate against specific batches" (a capability problem)
// - the product may have plenty of stock. Silently falling through to a
// plain current_stock decrement here would decrement it with no
// corresponding batch decrement, breaking the products.current_stock =
// SUM(product_batches.qty_on_hand) invariant this schema requires.
// Callers catch this specifically to show a distinct, actionable message
// rather than either the insufficient-stock message or the generic
// transaction-failed fallback.
class BatchConsumptionRequiredException extends RuntimeException {
    public $productId;
    public function __construct(int $productId) {
        parent::__construct('Batch consumption required for product ' . $productId . ' but not enabled by this caller');
        $this->productId = $productId;
    }
}

// Thrown by adjustStock() (Phase K4-5) when the target product has
// track_batches=1. A Stock Adjustment states a single absolute total for
// the whole product, but for a batch-tracked product that total is
// inherently ambiguous about which specific lot changed - unlike Stock
// In (always given an explicit batch identity) or Stock Out/POS (FEFO-
// ordered consumption against specific batch rows), there is no safe way
// to decide which batch absorbs the difference without guessing at
// physical facts (see the K4-5 design audit for the full analysis of why
// every automatic policy was rejected). Rejected here, before any
// mutation, rather than silently setting products.current_stock with no
// corresponding product_batches change - that would immediately break
// the current_stock = SUM(product_batches.qty_on_hand) invariant this
// schema requires (the exact defect this phase exists to close).
// Batch-specific adjustment is deferred to a later phase (K4-6); until
// then, Stock In/Stock Out remain the supported ways to change a tracked
// product's stock.
class TrackedStockAdjustmentNotSupportedException extends RuntimeException {
    public $productId;
    public function __construct(int $productId) {
        parent::__construct('Stock Adjustment is not supported for track_batches=1 product ' . $productId);
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
// needed for the increment itself — it can never drive stock negative no
// matter what else happens concurrently. Phase K2a adds product_batches/
// stock_transaction_item_batches bookkeeping for track_batches=1 products;
// see findOrCreateBatch() below for the batch-identity/concurrency design.
// $idempotencyToken (Phase I3-B): stock-in/index.php's per-form-render
// token, claimed as the very first statement in this transaction - same
// placement/reasoning as recordStockOut()'s own token above - so a
// duplicate submission (double-click, browser retry, two tabs) can never
// double-increment current_stock (or, as of K2a, double-create/double-
// increment a batch). Defaults to null so any future direct caller that
// doesn't pass one behaves exactly as before this phase.
// $lines: each entry is ['product_id','qty','cost'] as before K2a, plus
// optional 'batch_number'/'expiry_date' (only meaningful when the
// product's own track_batches flag is on; absent/null otherwise) - K2b
// wires these through from the Stock In form. Missing keys default to
// null via the ?? operator below, so every existing caller (and every
// existing test) that only ever passed the original three keys continues
// to work unmodified.
function recordStockIn(PDO $pdo, array $lines, string $date, ?int $supplierId, string $note, int $userId, ?string $idempotencyToken = null) {
    try {
        $pdo->beginTransaction();
        if ($idempotencyToken !== null) {
            claimIdempotencyToken($pdo, $idempotencyToken, $userId);
        }
        $reference = nextStockReference($pdo, 'STI');

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$reference, 'in', $date, $note, $supplierId, $userId]);
        $txId = (int) $pdo->lastInsertId();

        foreach ($lines as $line) {
            // Phase K2a: lock the product row before deciding anything
            // about batches, and read track_batches from THIS locked row
            // rather than trusting a value the caller might pass in - a
            // future client-supplied track_batches field must never be
            // able to turn batch bookkeeping on/off for a line. SELECT ...
            // FOR UPDATE (not a plain SELECT) is what actually takes the
            // lock here; the existing current_stock UPDATE just below
            // would eventually take the same row lock anyway, but taking
            // it here - before findOrCreateBatch() runs - is what
            // serializes the batch lookup/create decision for this
            // product_id against any other concurrent Stock In transaction
            // touching the same product. See findOrCreateBatch()'s own
            // comment for why this, not the UNIQUE constraint on
            // product_batches, is the actual concurrency guarantee.
            $stmt = $pdo->prepare('SELECT track_batches FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$line['product_id']]);
            $product = $stmt->fetch();
            if ($product === false) {
                throw new RuntimeException('Stock In: product ' . $line['product_id'] . ' not found');
            }

            // Existing statement, unchanged - now runs while still holding
            // the row lock the SELECT ... FOR UPDATE above just took
            // (InnoDB holds a transaction's row locks until commit/
            // rollback regardless of which statement first acquired them).
            $stmt = $pdo->prepare('UPDATE products SET current_stock = current_stock + ? WHERE id = ?');
            $stmt->execute([$line['qty'], $line['product_id']]);

            $subtotal = $line['qty'] * $line['cost'];
            $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,?,?)');
            $stmt->execute([$txId, $line['product_id'], $line['qty'], $line['cost'], $subtotal]);
            $itemId = (int) $pdo->lastInsertId();

            if ((int) $product['track_batches'] === 1) {
                $batchId = findOrCreateBatch(
                    $pdo,
                    (int) $line['product_id'],
                    $line['batch_number'] ?? null,
                    $line['expiry_date'] ?? null,
                    'stock_in',
                    $txId,
                    $userId
                );

                $stmt = $pdo->prepare('UPDATE product_batches SET qty_received = qty_received + ?, qty_on_hand = qty_on_hand + ?, updated_by = ? WHERE id = ?');
                $stmt->execute([$line['qty'], $line['qty'], $userId, $batchId]);

                // Receipt-level cost history (Phase K1's design): one
                // immutable row per receiving event, never averaged or
                // overwritten. No weighted-average/COGS logic here or
                // anywhere else in this function - explicitly deferred.
                $stmt = $pdo->prepare('INSERT INTO stock_transaction_item_batches (transaction_item_id, batch_id, qty, unit_cost) VALUES (?,?,?,?)');
                $stmt->execute([$itemId, $batchId, $line['qty'], $line['cost']]);
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

// Resolves the product_batches row for a given physical identity
// (product_id, batch_number, expiry_date - cost is deliberately not part
// of identity, see migration 014's header comment), creating one if none
// exists yet. MUST be called only after the caller already holds the
// product row lock (recordStockIn()'s SELECT ... FOR UPDATE above) - that
// lock, not the SELECT below, is what prevents two concurrent Stock In
// transactions for the same product from both deciding "no match exists"
// and both inserting. MySQL/MariaDB's UNIQUE index on (product_id,
// batch_number, expiry_date) cannot be relied on for that by itself: it
// treats every NULL as distinct from every other NULL, so it rejects
// nothing whenever batch_number or expiry_date is NULL - and a plain
// SELECT (with or without FOR UPDATE) can never lock a row that doesn't
// exist yet, so it can't close that race on its own either. With the
// caller's product-row lock held for the duration, only one transaction
// at a time can ever be inside this function for a given product_id, so
// the SELECT here is always answered by either nothing (safe to insert)
// or a row from a transaction that has already fully committed or rolled
// back - never a concurrently in-flight decision.
//
// Anonymous receipts (batch_number AND expiry_date both NULL) never
// perform the lookup at all and always insert a fresh row - two
// anonymous deliveries of the same product are never treated as the same
// physical batch, by design (an anonymous <=> anonymous comparison would
// otherwise incorrectly match under MySQL's NULL-safe operator).
function findOrCreateBatch(PDO $pdo, int $productId, ?string $batchNumber, ?string $expiryDate, string $origin, ?int $sourceTransactionId, int $userId): int {
    if ($batchNumber === null && $expiryDate === null) {
        return insertNewBatch($pdo, $productId, null, null, $origin, $sourceTransactionId, $userId);
    }

    $stmt = $pdo->prepare('SELECT id FROM product_batches WHERE product_id = ? AND batch_number <=> ? AND expiry_date <=> ?');
    $stmt->execute([$productId, $batchNumber, $expiryDate]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    return insertNewBatch($pdo, $productId, $batchNumber, $expiryDate, $origin, $sourceTransactionId, $userId);
}

// Defense-in-depth only, not the primary concurrency guarantee (that's the
// caller's product-row lock - see findOrCreateBatch() above). If some
// future caller ever reaches this function for the same non-NULL identity
// without holding that lock, uq_product_batches_identity would still
// reject the second INSERT (MySQL/MariaDB error 1062); rather than
// surfacing that as a raw duplicate-key failure, re-read the row the
// other transaction just committed and use it. Never fires for the
// anonymous (NULL/NULL) case, since the UNIQUE index never rejects two
// distinct NULLs.
function insertNewBatch(PDO $pdo, int $productId, ?string $batchNumber, ?string $expiryDate, string $origin, ?int $sourceTransactionId, int $userId): int {
    try {
        $stmt = $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, origin, source_transaction_id, created_by, updated_by) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $origin, $sourceTransactionId, $userId, $userId]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            $stmt = $pdo->prepare('SELECT id FROM product_batches WHERE product_id = ? AND batch_number <=> ? AND expiry_date <=> ?');
            $stmt->execute([$productId, $batchNumber, $expiryDate]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        throw $e;
    }
}

// Shared inner loop for a stock-decreasing transaction: insert each line
// item, then guard-decrement current_stock the same
// UPDATE ... WHERE current_stock >= ? way described above. Does NOT
// manage its own transaction - must be called from within the caller's
// own beginTransaction()/commit(). Used by recordStockOut() below and by
// recordCreditSale() (includes/debt.php), so a credit sale's stock
// decrement gets the exact same concurrency guarantee as a cash sale or
// a manual Stock Out, with no duplicated logic to drift out of sync.
//
// $consumeBatches (Phase K3-1): defaults to false, so recordCreditSale()'s
// own direct call (includes/debt.php) and every other existing caller
// keep working completely unmodified - POS is NOT touched by this phase,
// on purpose. A caller gets FEFO batch consumption only by explicitly
// passing true at its own call site (Stock Out's own call site is wired
// up in a later phase, not this one); nothing here infers it from
// track_batches alone. See BatchConsumptionRequiredException above for
// what happens to a tracked product when this stays false.
function insertStockOutLines(PDO $pdo, int $txId, array $lines, bool $consumeBatches = false): void {
    // Phase K3-1: deterministic lock acquisition order for any multi-
    // product transaction. A tracked line now holds its product-row lock
    // for longer than before (a FEFO read plus N batch updates and N
    // ledger inserts, not just one UPDATE), which raises the odds of the
    // same opposite-order deadlock shape InnoDB already has to detect-
    // and-abort for any multi-product Stock Out today. Sorting once,
    // here, for every caller (tracked or not - harmless either way)
    // removes that specific shape entirely for any transaction built by
    // this codebase's own UI. usort() only reorders the array; it never
    // touches a line's own product_id/qty/price values.
    usort($lines, fn($a, $b) => $a['product_id'] <=> $b['product_id']);

    foreach ($lines as $line) {
        // Phase K3-1: lock the product row and read track_batches from
        // THIS locked row - the sole authoritative source, never a
        // caller-supplied or client-side value - before deciding
        // anything. Same SELECT ... FOR UPDATE pattern recordStockIn()
        // already uses (Phase K2a), for the identical reason: it
        // serializes any decision about this product's batches against
        // every other concurrent transaction (Stock In OR Stock Out)
        // touching the same product_id. Unconditional - runs for every
        // line regardless of $consumeBatches, so a tracked product can
        // never silently fall through to the plain guarded decrement
        // below, no matter which caller reaches this function.
        $stmt = $pdo->prepare('SELECT track_batches FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$line['product_id']]);
        $product = $stmt->fetch();
        if ($product === false) {
            throw new RuntimeException('Stock Out: product ' . $line['product_id'] . ' not found');
        }

        if ((int) $product['track_batches'] === 1) {
            if (!$consumeBatches) {
                // Safety gate: this caller (POS, before its own FEFO
                // integration ships) does not know how to allocate
                // against specific batches. Reject the entire line - and
                // therefore the whole transaction, via the caller's own
                // catch-all - before any mutation happens for it.
                throw new BatchConsumptionRequiredException($line['product_id']);
            }
            insertStockOutLineWithBatchConsumption($pdo, $txId, $line);
            continue;
        }

        // Untracked product: existing behavior, byte-unchanged.
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

// Phase K3-1: FEFO (First-Expired, First-Out) consumption for one Stock
// Out line against a track_batches=1 product. MUST be called only after
// the caller already holds the product-row lock (the SELECT ... FOR
// UPDATE in insertStockOutLines() above) - the same "one lock serializes
// every batch decision for this product_id" guarantee K2a's
// findOrCreateBatch() already relies on for Stock In, extended here to
// consumption: two concurrent lines for the same product can never both
// read the same candidate quantities and both allocate against them,
// because only one transaction at a time can ever be inside this
// function for a given product_id.
//
// Ordering: (expiry_date IS NULL) ASC first - MySQL/MariaDB's default
// ASC ordering puts NULL first, which is backwards for FEFO (it would
// consume unknown-expiry stock before stock known to be expiring soon),
// so this boolean-expression idiom forces every dated batch ahead of
// every undated one. Within that: expiry_date ASC (earliest first), then
// id ASC as a deterministic tie-break (a lower id was received earlier -
// effectively FIFO within a tie, including among several anonymous
// NULL/NULL batches). batch_number never participates in the ordering.
// Zero-quantity batches are excluded entirely by qty_on_hand > 0 - never
// locked, never a candidate.
function insertStockOutLineWithBatchConsumption(PDO $pdo, int $txId, array $line): void {
    $stmt = $pdo->prepare(
        'SELECT id, qty_on_hand FROM product_batches
         WHERE product_id = ? AND qty_on_hand > 0
         ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC
         FOR UPDATE'
    );
    $stmt->execute([$line['product_id']]);
    $candidates = $stmt->fetchAll();

    $totalAvailable = 0;
    foreach ($candidates as $c) {
        $totalAvailable += (int) $c['qty_on_hand'];
    }
    if ($totalAvailable < $line['qty']) {
        // Rejected before any batch or product mutation - no partial
        // allocation, no partial decrement.
        throw new StockConflictException($line['product_id']);
    }

    // Build the full allocation plan in memory first - no DB row is
    // touched until the plan is known to sum exactly to the requested
    // quantity (guaranteed by the check above).
    $remaining = $line['qty'];
    $allocations = [];
    foreach ($candidates as $c) {
        if ($remaining <= 0) {
            break;
        }
        $take = min((int) $c['qty_on_hand'], $remaining);
        $allocations[] = ['batch_id' => (int) $c['id'], 'qty' => $take];
        $remaining -= $take;
    }

    foreach ($allocations as $allocation) {
        $stmt = $pdo->prepare('UPDATE product_batches SET qty_on_hand = qty_on_hand - ? WHERE id = ? AND qty_on_hand >= ?');
        $stmt->execute([$allocation['qty'], $allocation['batch_id'], $allocation['qty']]);
        if ($stmt->rowCount() === 0) {
            // Structurally unreachable given the row lock above already
            // guarantees this exact quantity was available a moment ago
            // in this same transaction - defense-in-depth only, matching
            // the guarded-UPDATE convention used everywhere else in this
            // file. Never allows qty_on_hand to go negative.
            throw new StockConflictException($line['product_id']);
        }
    }

    $subtotal = $line['qty'] * $line['price'];
    $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,?,?)');
    $stmt->execute([$txId, $line['product_id'], $line['qty'], $line['price'], $subtotal]);
    $itemId = (int) $pdo->lastInsertId();

    // Receipt-level cost history, mirroring K2a's Stock In design: the
    // line's own entered price/cost is stored as-is for every batch this
    // line drew from - no historical batch-cost lookup, no weighted-
    // average, no FIFO/COGS accounting. Explicitly deferred to a future
    // accounting phase.
    foreach ($allocations as $allocation) {
        $stmt = $pdo->prepare('INSERT INTO stock_transaction_item_batches (transaction_item_id, batch_id, qty, unit_cost) VALUES (?,?,?,?)');
        $stmt->execute([$itemId, $allocation['batch_id'], $allocation['qty'], $line['price']]);
    }

    // The decrement quantity equals the sum of the allocation plan,
    // which equals $line['qty'] by construction (the insufficiency check
    // above already guarantees the plan fully covers it) - current_stock
    // and SUM(qty_on_hand) can therefore never diverge by commit time.
    // Guard kept as defense-in-depth, same convention as every other
    // stock-decrementing UPDATE in this file.
    $stmt = $pdo->prepare('UPDATE products SET current_stock = current_stock - ? WHERE id = ? AND current_stock >= ?');
    $stmt->execute([$line['qty'], $line['product_id'], $line['qty']]);
    if ($stmt->rowCount() === 0) {
        throw new StockConflictException($line['product_id']);
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
// $consumeBatches (Phase K3-1): appended last, defaulting false, so this
// existing signature stays positionally compatible for every current
// caller - POS's own call to this function needs zero code changes to
// keep its exact current behavior (which now also means: rejected, not
// silently corrupted, for a track_batches=1 product - see
// insertStockOutLines() above). Passed straight through unchanged.
function recordStockOut(PDO $pdo, array $lines, string $date, string $note, int $userId, string $type = 'out', ?float $cashReceived = null, ?string $idempotencyToken = null, bool $consumeBatches = false) {
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

        insertStockOutLines($pdo, $txId, $lines, $consumeBatches);

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
//
// Phase K4-5: rejects track_batches=1 products outright (see
// TrackedStockAdjustmentNotSupportedException above) - this function has
// no concept of batches at all, and a track_batches=1 product's
// current_stock must never move without a corresponding, deliberate
// product_batches change. The check reads track_batches from a freshly
// locked row (SELECT ... FOR UPDATE), the same pattern recordStockIn()/
// insertStockOutLines() already use, placed AFTER the reference number is
// claimed and the transaction header is inserted - so a rejection here
// rolls back the whole transaction via the existing catch block below,
// releasing the reference number and leaving no header behind, exactly
// like every other rejection path in this file. This preserves the
// established lock order for every stock-mutating transaction in this
// codebase: the shared reference_counters row first, then the product
// row - never the reverse. The pre-existing optimistic
// current_stock = ? guard is kept unchanged below for the untracked path
// it still serves (both as its original concurrency guard and,
// incidentally, as this function's only duplicate-submission defense -
// see includes/stock.php's own idempotency documentation).
function adjustStock(PDO $pdo, int $productId, float $newQty, float $currentQty, string $reason, string $date, int $userId) {
    try {
        $pdo->beginTransaction();
        $reference = nextStockReference($pdo, 'ADJ');

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,NULL,?)');
        $stmt->execute([$reference, 'adjustment', $date, $reason, $userId]);
        $txId = $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT track_batches FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if ($product === false) {
            throw new RuntimeException('Stock Adjustment: product ' . $productId . ' not found');
        }
        if ((int) $product['track_batches'] === 1) {
            throw new TrackedStockAdjustmentNotSupportedException($productId);
        }

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

// Phase K4-5: closes the second invariant hole found in K4-4's browser
// QA - enabling Track Batches (product/index.php) on a product that
// already has current_stock > 0 must never leave that stock without a
// matching product_batches row, or current_stock = SUM(qty_on_hand)
// breaks the instant tracking turns on. Owns its own transaction, exactly
// like adjustStock()/recordStockIn() above, rather than running inside
// product/index.php's own field-update transaction - specifically so
// this invariant-critical step (flip track_batches + create the opening
// batch, or neither) can never be left half-done by an unrelated later
// failure in that other transaction (name/SKU/category/etc + audit log).
// product/index.php calls this FIRST, as its own separate step, before
// its normal field-update transaction runs.
//
// SELECT ... FOR UPDATE takes the same product-row lock every other
// stock-mutating path in this file takes before deciding anything about
// batches - two concurrent requests enabling tracking on the same
// product can never both decide "not tracked yet" and both create an
// opening batch: the second's SELECT ... FOR UPDATE blocks until the
// first commits, then reads back track_batches=1 and returns immediately
// (see the idempotent no-op branch below).
//
// The opening-balance batch (origin='opening_balance', batch_number and
// expiry_date both NULL) is exactly the placeholder migration
// 014_add_product_batches.sql's own header comment reserved this origin
// value for: "pre-tracking stock this database has no real batch history
// for". qty_received is deliberately left at 0, NOT set to
// $currentStock - this stock was never received through a real Stock In
// event, and migration 014 is explicit that only Stock In touches
// qty_received. No stock_transactions/stock_transaction_items row is
// created for this conversion - it is not a stock movement (current_stock
// does not change), just a one-time bookkeeping placeholder for
// historical stock, the same non-event nature as opening_balance's own
// name implies.
//
// Guarded by two independent conditions, both required before any batch
// is inserted:
//   - already tracked (track_batches=1 on the locked row) -> no-op
//     entirely. Covers a repeat submission of the same 0->1 transition
//     and any other caller that calls this again on an already-tracked
//     product.
//   - current_stock <= 0 -> nothing to explain, no batch created (an
//     anonymous zero-quantity placeholder batch would be pure noise).
//   - this product already has ANY product_batches row -> skipped. This
//     is either a repeat of this same transition (idempotent) or a
//     pre-existing anomalous state (e.g. tracking was previously enabled
//     then disabled, leaving old batch rows behind - see this phase's
//     design audit §B4/B5) - either way, fabricating a second opening
//     batch on top of real ones would double-count stock, so this
//     function does nothing rather than guess. The resulting mismatch
//     (if any) in that anomalous case is a known, documented limitation,
//     not something this function attempts to reconcile.
function enableTrackBatches(PDO $pdo, int $productId, int $userId): void {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT track_batches, current_stock FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if ($product === false) {
            throw new RuntimeException('enableTrackBatches: product ' . $productId . ' not found');
        }

        if ((int) $product['track_batches'] === 1) {
            $pdo->commit();
            return;
        }

        $stmt = $pdo->prepare('UPDATE products SET track_batches = 1 WHERE id = ?');
        $stmt->execute([$productId]);

        $currentStock = (int) $product['current_stock'];
        if ($currentStock > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = ?');
            $stmt->execute([$productId]);
            if ((int) $stmt->fetchColumn() === 0) {
                $stmt = $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin, source_transaction_id, created_by, updated_by) VALUES (?, NULL, NULL, 0, ?, ?, NULL, ?, ?)');
                $stmt->execute([$productId, $currentStock, 'opening_balance', $userId, $userId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
