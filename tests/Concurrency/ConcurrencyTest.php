<?php
declare(strict_types=1);

namespace Tests\Concurrency;

use PDO;
use PHPUnit\Framework\TestCase;

// Orchestrator for P0 #8 (Stock Concurrency), #14 (Concurrent Payment),
// and the concurrent-generation half of #17 (Reference Numbers).
//
// Deliberately does NOT extend Tests\TestCase: these tests need rows that
// are genuinely COMMITTED and visible to a second, independent
// connection/process - an open outer transaction (Tests\TestCase's
// per-test isolation mechanism) would make that impossible, since nothing
// committed inside it would be visible outside until it closes. Instead,
// this class manages its own setup/cleanup directly against
// $GLOBALS['__TEST_PDO'] in autocommit mode, and explicitly deletes the
// rows it creates in tearDown() so it leaves no committed state behind
// for later tests.
//
// No PHP-level lock or mutex is introduced anywhere in this file or in
// the worker scripts it launches (stock_out_race.php, debt_payment_race.php,
// reference_race.php) - only the database's own row-locking (already
// present in includes/stock.php / includes/debt.php) is what's under test.
final class ConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private array $cleanupProductIds = [];
    private array $cleanupUserIds = [];
    private array $cleanupCustomerIds = [];
    // K4-3: idempotency tokens explicitly passed to pos_sale_race.php
    // workers - unlike every earlier worker in this file (which always
    // passed null), K4-3's tests deliberately pass real tokens, so their
    // idempotency_keys rows need explicit cleanup here too.
    private array $cleanupIdempotencyTokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $GLOBALS['__TEST_PDO'];
    }

    protected function tearDown(): void
    {
        // Children first (FK order), then parents. Best-effort - a
        // leftover row here cannot corrupt another test's own
        // before/after delta assertions, but tidying up keeps the test
        // database legible between runs.
        foreach ($this->cleanupCustomerIds as $id) {
            $this->pdo->exec("DELETE cdp FROM customer_debt_payments cdp JOIN customer_debts cd ON cd.id = cdp.debt_id WHERE cd.customer_id = $id");
            $this->pdo->exec("DELETE FROM customer_debts WHERE customer_id = $id");
            $this->pdo->exec("DELETE FROM customers WHERE id = $id");
        }
        foreach ($this->cleanupProductIds as $id) {
            // Phase K2a: stock_transaction_item_batches references
            // stock_transaction_items (ON DELETE CASCADE), so it would be
            // cleaned up automatically - deleted explicitly here anyway for
            // clarity/symmetry with the other explicit deletes in this
            // method. product_batches has no ON DELETE behavior on its
            // product_id FK, so it must be deleted before products.
            $this->pdo->exec("DELETE sib FROM stock_transaction_item_batches sib
                               JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                               WHERE sti.product_id = $id");
            $this->pdo->exec("DELETE FROM stock_transaction_items WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM product_batches WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM products WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        foreach ($this->cleanupIdempotencyTokens as $token) {
            $stmt = $this->pdo->prepare('DELETE FROM idempotency_keys WHERE token = ?');
            $stmt->execute([$token]);
        }
        parent::tearDown();
    }

    // ---- P0 #8: Stock Concurrency ----

    public function testExactlyOneOfTwoConcurrentStockOutsSucceedsWhenStockIsOnlyEnoughForOne(): void
    {
        $productId = $this->seedProduct(10);
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_out_race.php', (string) $productId, '10', (string) $userId],
            ['stock_out_race.php', (string) $productId, '10', (string) $userId],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses, 'exactly one attempt must succeed and the other must conflict; results: ' . json_encode($results));

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $finalStock = (int) $stmt->fetchColumn();
        $this->assertSame(0, $finalStock, 'the single winning stock-out must be applied exactly once');
        $this->assertGreaterThanOrEqual(0, $finalStock, 'stock must never go negative');
    }

    public function testConcurrentStockOutsNeverDriveStockNegativeEvenWithMoreContendersThanStockAllows(): void
    {
        $productId = $this->seedProduct(5);
        $userId = $this->seedUser();

        // 4 processes each try to take all 5 units - at most one can win.
        $results = $this->runParallel([
            ['stock_out_race.php', (string) $productId, '5', (string) $userId],
            ['stock_out_race.php', (string) $productId, '5', (string) $userId],
            ['stock_out_race.php', (string) $productId, '5', (string) $userId],
            ['stock_out_race.php', (string) $productId, '5', (string) $userId],
        ]);

        $okCount = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $this->assertSame(1, $okCount, 'exactly one of the four contenders may win; results: ' . json_encode($results));

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // ---- P0 #14: Concurrent Payment ----

    public function testExactlyOneOfTwoConcurrentPaymentsThatTogetherWouldOverpaySucceeds(): void
    {
        [$debtId, $customerId, $userId] = $this->seedDebt(100.00, 60.00); // room for exactly 40 more

        $results = $this->runParallel([
            ['debt_payment_race.php', (string) $debtId, '30', (string) $userId],
            ['debt_payment_race.php', (string) $debtId, '30', (string) $userId],
        ]);
        $this->cleanupCustomerIds[] = $customerId;

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'overpaid'], $statuses, 'exactly one payment must succeed and the other must be rejected as overpayment; results: ' . json_encode($results));

        $stmt = $this->pdo->prepare('SELECT paid_amount FROM customer_debts WHERE id = ?');
        $stmt->execute([$debtId]);
        $this->assertEqualsWithDelta(90.00, (float) $stmt->fetchColumn(), 0.001, 'exactly one of the two 30.00 payments may land');
    }

    // ---- K2a: Batch Core + Stock In concurrency ----
    //
    // All five tests below drive recordStockIn() through the exact same
    // runParallel()/proc_open() harness as the P0 #8 Stock Out races above,
    // via tests/Concurrency/stock_in_batch_race.php. Whatever safety they
    // observe comes entirely from the product-row lock (SELECT ... FOR
    // UPDATE) inside recordStockIn() - see that function's own comment in
    // includes/stock.php for why the UNIQUE constraint on product_batches
    // alone cannot be relied on whenever batch_number or expiry_date is
    // NULL.

    public function testConcurrentStockInsForTheSameNewNonNullBatchMergeIntoOneRow(): void
    {
        $productId = $this->seedTrackedProduct();
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_in_batch_race.php', (string) $productId, 'LOT-A', '2027-01-01', '5', (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, 'LOT-A', '2027-01-01', '7', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent Stock In requests must succeed: ' . json_encode($results));
        }
        $this->assertSame($results[0]['batchId'], $results[1]['batchId'], 'both must resolve to the same batch row');

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(1, $batches, 'exactly one product_batches row must exist for this identity');
        $this->assertSame(12, (int) $batches[0]['qty_received'], 'qty_received must be the sum of both lines');
        $this->assertSame(12, (int) $batches[0]['qty_on_hand'], 'qty_on_hand must be the sum of both lines');
    }

    public function testConcurrentStockInsForTheSameBatchNumberWithNullExpiryMergeIntoOneRow(): void
    {
        $productId = $this->seedTrackedProduct();
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_in_batch_race.php', (string) $productId, 'LOT-B', '_NULL_', '4', (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, 'LOT-B', '_NULL_', '9', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent Stock In requests must succeed: ' . json_encode($results));
        }
        $this->assertSame($results[0]['batchId'], $results[1]['batchId']);

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(1, $batches, 'the UNIQUE constraint alone cannot protect a NULL expiry_date - this must come from the product-row lock');
        $this->assertSame(13, (int) $batches[0]['qty_on_hand'], 'both quantities must be preserved, not lost to a race');
    }

    public function testConcurrentStockInsForTheSameExpiryWithNullBatchNumberMergeIntoOneRow(): void
    {
        $productId = $this->seedTrackedProduct();
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_in_batch_race.php', (string) $productId, '_NULL_', '2027-03-01', '6', (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, '_NULL_', '2027-03-01', '3', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent Stock In requests must succeed: ' . json_encode($results));
        }
        $this->assertSame($results[0]['batchId'], $results[1]['batchId']);

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(1, $batches, 'the UNIQUE constraint alone cannot protect a NULL batch_number - this must come from the product-row lock');
        $this->assertSame(9, (int) $batches[0]['qty_on_hand'], 'both quantities must be preserved, not lost to a race');
    }

    public function testConcurrentAnonymousStockInsAlwaysCreateTwoSeparateBatchRows(): void
    {
        $productId = $this->seedTrackedProduct();
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_in_batch_race.php', (string) $productId, '_NULL_', '_NULL_', '4', (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, '_NULL_', '_NULL_', '10', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent Stock In requests must succeed: ' . json_encode($results));
        }
        $this->assertNotSame($results[0]['batchId'], $results[1]['batchId'], 'two anonymous receipts must NEVER merge into one batch');

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(2, $batches, 'exactly two product_batches rows must exist - never one');

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $this->assertSame(14, (int) $stmt->fetchColumn(), 'current_stock must still be the sum of both lines regardless of batch bookkeeping');
    }

    public function testConcurrentTopUpsOfAnExistingBatchPreserveBothIncrements(): void
    {
        $productId = $this->seedTrackedProduct();
        $userId = $this->seedUser();

        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$productId, 'LOT-EXISTING', '2027-06-01', 20, 20, 'stock_in']);
        $existingBatchId = (int) $this->pdo->lastInsertId();

        $results = $this->runParallel([
            ['stock_in_batch_race.php', (string) $productId, 'LOT-EXISTING', '2027-06-01', '5', (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, 'LOT-EXISTING', '2027-06-01', '8', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent Stock In requests must succeed: ' . json_encode($results));
            $this->assertSame($existingBatchId, $r['batchId'], 'both must resolve to the pre-existing batch row, not create a new one');
        }

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(1, $batches, 'exactly one product_batches row must remain');
        $this->assertSame(33, (int) $batches[0]['qty_on_hand'], 'no lost update: 20 + 5 + 8');
        $this->assertSame(33, (int) $batches[0]['qty_received']);
    }

    // ---- K3-1: Stock Out + FEFO batch consumption concurrency ----
    //
    // All five tests below drive recordStockOut(..., consumeBatches: true)
    // through the exact same runParallel()/proc_open() harness as the P0
    // #8 races above, via new worker scripts stock_out_batch_race.php/
    // stock_out_multiproduct_race.php (and, for Test 4, the existing
    // stock_in_batch_race.php). Whatever safety they observe comes
    // entirely from the product-row lock inside insertStockOutLines()
    // (includes/stock.php) - the same lock K2a's Stock In already uses,
    // now also serializing consumption against consumption, and
    // consumption against receiving, for the same product_id.

    public function testConcurrentStockOutsAgainstTheSameTrackedProductNeverOversellOrLoseAnUpdate(): void
    {
        $productId = $this->seedTrackedProductWithStock(20);
        $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 20);
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_out_batch_race.php', (string) $productId, '8', (string) $userId],
            ['stock_out_batch_race.php', (string) $productId, '5', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent requests must succeed - combined demand fits supply: ' . json_encode($results));
        }

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $this->assertSame(7, (int) $stmt->fetchColumn(), '20 - 8 - 5, no lost update');
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentStockOutsBothTargetingTheSameEarliestBatchSerializeCorrectly(): void
    {
        $productId = $this->seedTrackedProductWithStock(15);
        $earliest = $this->seedBatchDirect($productId, 'LOT-EARLY', '2027-01-01', 5);
        $later = $this->seedBatchDirect($productId, 'LOT-LATE', '2027-06-01', 10);
        $userId = $this->seedUser();

        // Combined demand (3+2=5) exactly depletes the earliest batch -
        // the later batch must remain completely untouched regardless of
        // which request's product-row lock wins the race first.
        $results = $this->runParallel([
            ['stock_out_batch_race.php', (string) $productId, '3', (string) $userId],
            ['stock_out_batch_race.php', (string) $productId, '2', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], json_encode($results));
        }

        $this->assertSame(0, $this->batchQtyOnHand($earliest), 'the earliest batch must be fully and exactly depleted, never negative');
        $this->assertSame(10, $this->batchQtyOnHand($later), 'the later batch must be completely untouched');
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentMultiBatchStockOutsProduceTheCorrectFinalAllocationRegardlessOfWinOrder(): void
    {
        // The task's own worked example, run concurrently: Batch A=5,
        // Batch B=10, Request 1=8, Request 2=4. Combined demand (12) fits
        // combined supply (15) - the exact intra-line split of request 1
        // can differ depending on which request's lock wins first, but
        // the resulting TOTALS are pure arithmetic and must be identical
        // either way: this test asserts the final state, not a winner.
        $productId = $this->seedTrackedProductWithStock(15);
        $lotA = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatchDirect($productId, 'LOT-B', '2027-06-01', 10);
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_out_batch_race.php', (string) $productId, '8', (string) $userId],
            ['stock_out_batch_race.php', (string) $productId, '4', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], json_encode($results));
        }

        $this->assertSame(0, $this->batchQtyOnHand($lotA), 'the earlier-expiring batch must be fully consumed by the combined demand');
        $this->assertSame(3, $this->batchQtyOnHand($lotB), '15 total - 12 combined demand = 3 remaining');
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $this->assertSame(3, (int) $stmt->fetchColumn());
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentStockOutAndStockInOnTheSameTrackedProductPreserveTheInvariant(): void
    {
        // Both operations lock the product row first (K2a's Stock In,
        // K3-1's Stock Out) - this proves that shared lock also
        // correctly serializes a receiving event against a consuming one
        // for the same product_id, not just consumption against
        // consumption. The new batch created by Stock In carries a LATER
        // expiry than the existing one, so FEFO always prefers the
        // existing batch for the Stock Out side regardless of which
        // operation's lock wins first - making the final state
        // deterministic without needing to know the winner.
        $productId = $this->seedTrackedProductWithStock(10);
        $existing = $this->seedBatchDirect($productId, 'LOT-EXISTING', '2027-01-01', 10);
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['stock_out_batch_race.php', (string) $productId, '4', (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, 'LOT-NEW', '2027-12-31', '6', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], json_encode($results));
        }

        $this->assertSame(6, $this->batchQtyOnHand($existing), '10 - 4, the Stock Out side always prefers the earlier-expiring existing batch');
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $this->assertSame(12, (int) $stmt->fetchColumn(), '10 - 4 + 6, no lost update between the two operation types');
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentMultiProductStockOutsInOppositeOrderDoNotDeadlockAndAllocateCorrectly(): void
    {
        // insertStockOutLines() sorts its lines by product_id ASC before
        // locking anything - this test submits the SAME two products in
        // OPPOSITE order from each concurrent process (the exact shape
        // that would otherwise risk an opposite-order lock deadlock) and
        // confirms both still succeed with correct final quantities, with
        // no application-level coordination of any kind in the worker.
        $productA = $this->seedTrackedProductWithStock(10);
        $this->seedBatchDirect($productA, 'LOT-A', '2027-01-01', 10);
        $productB = $this->seedTrackedProductWithStock(10);
        $this->seedBatchDirect($productB, 'LOT-B', '2027-01-01', 10);
        $userId = $this->seedUser();
        // Ensure a deterministic "opposite order" regardless of how the
        // two seeded product ids happen to compare.
        [$lo, $hi] = $productA < $productB ? [$productA, $productB] : [$productB, $productA];

        $results = $this->runParallel([
            ['stock_out_multiproduct_race.php', (string) $lo, '3', (string) $hi, '2', (string) $userId],
            ['stock_out_multiproduct_race.php', (string) $hi, '4', (string) $lo, '5', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'neither submission order should deadlock or fail: ' . json_encode($results));
        }

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$lo]);
        $this->assertSame(2, (int) $stmt->fetchColumn(), '10 - 3 - 5');
        $stmt->execute([$hi]);
        $this->assertSame(4, (int) $stmt->fetchColumn(), '10 - 2 - 4');
        $this->assertBatchInvariantHolds($lo);
        $this->assertBatchInvariantHolds($hi);
    }

    // ---- K4-3: POS concurrency (cash + credit, shared kernel + idempotency races) ----
    //
    // All seven tests below drive tests/Concurrency/pos_sale_race.php,
    // which calls the real, unmodified recordStockOut(..., consumeBatches:
    // true) (cash) / recordCreditSale(..., consumeBatches: true) (credit)
    // from includes/stock.php / includes/debt.php - no new locking logic
    // exists anywhere in this codebase for these tests to exercise; they
    // exist to prove that POS's own two entry points inherit the same
    // safety the K3-1 tests above already proved for the shared
    // insertStockOutLines() kernel, PLUS one genuinely new mechanism no
    // earlier test (K3-1 or K4-2) ever exercised under real OS-process
    // concurrency: the idempotency_keys UNIQUE(token) claim race (Tests 5
    // and 6 below) - see includes/stock.php's claimIdempotencyToken() for
    // why this is a real lock-wait, not merely a duplicate-key error, when
    // two transactions race the same token.
    //
    // POS vs Stock Out, POS vs Stock In, and the multi-product opposite-
    // order deadlock scenario are deliberately NOT duplicated here - see
    // this phase's audit report, section 6-9: POS cash calls the exact
    // same recordStockOut()/insertStockOutLines() functions already raced
    // above (testConcurrentStockOutAndStockInOnTheSameTrackedProductPreserve
    // TheInvariant, testConcurrentMultiProductStockOutsInOppositeOrderDoNot
    // DeadlockAndAllocateCorrectly), and POS credit reaches the identical
    // insertStockOutLines() call with no new lock surface before it (its
    // extra customer/debt steps touch only rows - customers,
    // customer_debts, and the separate 'customer_debts' reference-counter
    // row - that no Stock In/Stock Out/Adjustment transaction ever
    // touches). Generic reference-counter concurrency (the mechanism
    // itself, not POS's own use of it) is likewise already proven by
    // testConcurrentReferenceGenerationNeverProducesDuplicates() below.

    public function testConcurrentPosCashSalesAgainstTheSameBatchNeverOversellOrLoseAnUpdate(): void
    {
        $productId = $this->seedTrackedProductWithStock(10);
        $batchId = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 10);
        $userId = $this->seedUser();
        $token1 = testRandomToken();
        $token2 = testRandomToken();
        $this->cleanupIdempotencyTokens = array_merge($this->cleanupIdempotencyTokens, [$token1, $token2]);

        $results = $this->runParallel([
            ['pos_sale_race.php', 'cash', "$productId:7", $token1, (string) $userId, '_NA_'],
            ['pos_sale_race.php', 'cash', "$productId:7", $token2, (string) $userId, '_NA_'],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'stock_conflict'], $statuses, 'exactly one of the two 7-unit requests may win against 10 units of stock: ' . json_encode($results));

        $this->assertSame(3, $this->batchQtyOnHand($batchId), '10 - 7, no lost update, no negative quantity');
        $this->assertGreaterThanOrEqual(0, $this->batchQtyOnHand($batchId));
        $this->assertSame(3, $this->currentStock($productId));
        $this->assertGreaterThanOrEqual(0, $this->currentStock($productId));
        $this->assertSame(1, $this->saleTransactionCountForProduct($productId), 'exactly one sale transaction may exist for this product');
        $this->assertCount(1, $this->allocationsForProduct($productId), 'exactly one allocation ledger row may exist');
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentPosCashSalesConsumeMultipleBatchesInFefoOrderWithNoLostUpdate(): void
    {
        $productId = $this->seedTrackedProductWithStock(15);
        $lotA = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatchDirect($productId, 'LOT-B', '2027-06-01', 10);
        $userId = $this->seedUser();
        $token1 = testRandomToken();
        $token2 = testRandomToken();
        $this->cleanupIdempotencyTokens = array_merge($this->cleanupIdempotencyTokens, [$token1, $token2]);

        $results = $this->runParallel([
            ['pos_sale_race.php', 'cash', "$productId:8", $token1, (string) $userId, '_NA_'],
            ['pos_sale_race.php', 'cash', "$productId:4", $token2, (string) $userId, '_NA_'],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'combined demand (8+4=12) fits combined supply (5+10=15): ' . json_encode($results));
        }

        // The exact intra-line split can differ depending on which
        // process's product-row lock wins first (see the two possible
        // orderings worked out in this phase's audit report, section 3) -
        // but the resulting per-batch TOTALS are pure arithmetic and must
        // be identical either way: batch A (earlier expiry) is always
        // fully depleted first, and only the remainder ever touches B.
        $this->assertSame(0, $this->batchQtyOnHand($lotA), 'the earlier-expiring batch must be fully consumed regardless of win order');
        $this->assertSame(3, $this->batchQtyOnHand($lotB), '15 - 12 = 3 remaining, no lost update');
        $this->assertSame(3, $this->currentStock($productId));
        $this->assertSame(5, $this->allocationSumForBatch($lotA), 'all 5 units of the earlier batch must have been allocated across the two real transactions');
        $this->assertSame(7, $this->allocationSumForBatch($lotB), '12 total demand - 5 from batch A = 7 from batch B');
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentPosCreditSalesAgainstTheSameBatchNeverOversellAndTheDebtBelongsToTheWinner(): void
    {
        $productId = $this->seedTrackedProductWithStock(10);
        $batchId = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 10);
        $userId = $this->seedUser();
        $customer = testSeedCustomer($this->pdo, 'K4-3 Credit Race Customer');
        $this->cleanupCustomerIds[] = $customer['id'];
        $token1 = testRandomToken();
        $token2 = testRandomToken();
        $this->cleanupIdempotencyTokens = array_merge($this->cleanupIdempotencyTokens, [$token1, $token2]);

        $results = $this->runParallel([
            ['pos_sale_race.php', 'credit', "$productId:7", $token1, (string) $userId, 'existing:' . $customer['id']],
            ['pos_sale_race.php', 'credit', "$productId:7", $token2, (string) $userId, 'existing:' . $customer['id']],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ok', 'stock_conflict'], $statuses, 'exactly one of the two 7-unit credit requests may win against 10 units of stock: ' . json_encode($results));

        $this->assertSame(3, $this->batchQtyOnHand($batchId));
        $this->assertSame(3, $this->currentStock($productId));
        $this->assertSame(1, $this->saleTransactionCountForProduct($productId), 'exactly one sale transaction may exist for this product');
        $this->assertSame(1, $this->debtCountForCustomer($customer['id']), 'exactly one debt may exist - no orphan debt from the loser');
        $this->assertCount(1, $this->allocationsForProduct($productId));
        $this->assertBatchInvariantHolds($productId);

        // The single debt must belong to the single winning sale, not to
        // some other transaction - the losing process's own transaction
        // (idempotency claim aside) never reached the debt-insert step at
        // all, since insertStockOutLines() throws before it.
        $winner = $results[array_search('ok', array_column($results, 'status'), true)];
        $stmt = $this->pdo->prepare('SELECT cd.stock_transaction_id, st.reference FROM customer_debts cd
                                      JOIN stock_transactions st ON st.id = cd.stock_transaction_id
                                      WHERE cd.customer_id = ?');
        $stmt->execute([$customer['id']]);
        $debt = $stmt->fetch();
        $this->assertSame($winner['reference'], $debt['reference'], 'the one debt must reference the one winning sale');
    }

    public function testConcurrentPosCreditSalesConsumeMultipleBatchesInFefoOrderAndEachCreatesItsOwnDebt(): void
    {
        $productId = $this->seedTrackedProductWithStock(15);
        $lotA = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatchDirect($productId, 'LOT-B', '2027-06-01', 10);
        $userId = $this->seedUser();
        $customer = testSeedCustomer($this->pdo, 'K4-3 Multi-Batch Credit Customer');
        $this->cleanupCustomerIds[] = $customer['id'];
        $token1 = testRandomToken();
        $token2 = testRandomToken();
        $this->cleanupIdempotencyTokens = array_merge($this->cleanupIdempotencyTokens, [$token1, $token2]);

        $results = $this->runParallel([
            ['pos_sale_race.php', 'credit', "$productId:8", $token1, (string) $userId, 'existing:' . $customer['id']],
            ['pos_sale_race.php', 'credit', "$productId:4", $token2, (string) $userId, 'existing:' . $customer['id']],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], json_encode($results));
        }

        $this->assertSame(0, $this->batchQtyOnHand($lotA));
        $this->assertSame(3, $this->batchQtyOnHand($lotB));
        $this->assertSame(3, $this->currentStock($productId));
        $this->assertSame(2, $this->saleTransactionCountForProduct($productId), 'each successful credit sale is its own sale transaction');
        $this->assertSame(2, $this->debtCountForCustomer($customer['id']), 'each successful credit sale creates its own debt');
        $this->assertSame(5, $this->allocationSumForBatch($lotA), 'FEFO: the earlier batch is fully consumed first, regardless of win order');
        $this->assertSame(7, $this->allocationSumForBatch($lotB));
        $this->assertBatchInvariantHolds($productId);
    }

    // Primary K4-3 requirement: proves the idempotency_keys UNIQUE(token)
    // claim itself is race-safe under genuine OS-process concurrency - the
    // one mechanism no earlier test (K3-1's workers all pass null; K4-2's
    // HTTP harness is single-threaded and can only replay sequentially)
    // ever exercised. See includes/stock.php's claimIdempotencyToken() and
    // this phase's audit report section 5 for the exact lock-wait
    // mechanics this proves: the loser's INSERT blocks on the winner's
    // uncommitted row, then fails with a duplicate-key error the instant
    // the winner commits - never reaching nextReferenceSequence() or any
    // product/batch lock at all.
    public function testConcurrentCashSubmissionsWithTheSameIdempotencyTokenApplyExactlyOnce(): void
    {
        $productId = $this->seedTrackedProductWithStock(20);
        $batchId = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 20);
        $userId = $this->seedUser();
        $token = testRandomToken();
        $this->cleanupIdempotencyTokens[] = $token;
        $refCounterBefore = $this->referenceCounterValue('stock_transactions');

        $results = $this->runParallel([
            ['pos_sale_race.php', 'cash', "$productId:5", $token, (string) $userId, '_NA_'],
            ['pos_sale_race.php', 'cash', "$productId:5", $token, (string) $userId, '_NA_'],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['idempotency_conflict', 'ok'], $statuses, 'exactly one of the two identical-token submissions may succeed, the other must be rejected as a duplicate: ' . json_encode($results));

        $this->assertSame(1, $this->saleTransactionCountForProduct($productId), 'exactly one stock transaction may exist');
        $this->assertCount(1, $this->allocationsForProduct($productId), 'exactly one allocation set may exist');
        $this->assertSame(1, $this->idempotencyKeyCount($token), 'exactly one idempotency_keys row may exist for this token - the loser\'s claim rolled back with the rest of its transaction');
        $this->assertSame(15, $this->batchQtyOnHand($batchId), '20 - 5, stock decremented exactly once despite two identical submissions');
        $this->assertSame(15, $this->currentStock($productId));
        $this->assertSame($refCounterBefore + 1, $this->referenceCounterValue('stock_transactions'), 'the reference counter must advance exactly once - the loser never reached it');
        $this->assertBatchInvariantHolds($productId);
    }

    // Same primary requirement as above, for the credit path - additionally
    // proves the loser's new-customer INSERT cannot survive: it rolls back
    // as part of the same aborted transaction as its idempotency claim,
    // before ever reaching insertStockOutLines() or the debt insert.
    public function testConcurrentCreditSubmissionsWithTheSameIdempotencyTokenAndSameNewCustomerApplyExactlyOnce(): void
    {
        $productId = $this->seedTrackedProductWithStock(20);
        $batchId = $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 20);
        $userId = $this->seedUser();
        $token = testRandomToken();
        $this->cleanupIdempotencyTokens[] = $token;
        $customerName = 'K4-3 Dup Token Credit Customer ' . bin2hex(random_bytes(4));
        $customerPhone = '099000111';
        $refCounterBefore = $this->referenceCounterValue('stock_transactions');
        $debtCounterBefore = $this->referenceCounterValue('customer_debts');

        $results = $this->runParallel([
            ['pos_sale_race.php', 'credit', "$productId:5", $token, (string) $userId, "new:$customerName:$customerPhone"],
            ['pos_sale_race.php', 'credit', "$productId:5", $token, (string) $userId, "new:$customerName:$customerPhone"],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['idempotency_conflict', 'ok'], $statuses, 'exactly one of the two identical-token credit submissions may succeed: ' . json_encode($results));

        $this->assertSame(1, $this->customerCountByName($customerName), 'exactly one customer row may exist - the loser\'s INSERT rolled back with the rest of its transaction');
        $customerId = $this->customerIdByName($customerName);
        $this->cleanupCustomerIds[] = $customerId;

        $this->assertSame(1, $this->saleTransactionCountForProduct($productId), 'exactly one sale transaction may exist');
        $this->assertSame(1, $this->debtCountForCustomer($customerId), 'exactly one debt may exist - no orphan debt');
        $this->assertSame(1, $this->idempotencyKeyCount($token), 'exactly one idempotency_keys row may exist for this token');
        $this->assertCount(1, $this->allocationsForProduct($productId));
        $this->assertSame(15, $this->batchQtyOnHand($batchId), '20 - 5, stock decremented exactly once');
        $this->assertSame(15, $this->currentStock($productId));
        $this->assertSame($refCounterBefore + 1, $this->referenceCounterValue('stock_transactions'), 'only the winner ever reaches the sale reference counter');
        $this->assertSame($debtCounterBefore + 1, $this->referenceCounterValue('customer_debts'), 'only the winner ever reaches the debt reference counter');
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentPosCashAndPosCreditSalesAgainstTheSameProductBothSucceedWhenSupplyAllows(): void
    {
        $productId = $this->seedTrackedProductWithStock(15);
        $this->seedBatchDirect($productId, 'LOT-A', '2027-01-01', 15);
        $userId = $this->seedUser();
        $customer = testSeedCustomer($this->pdo, 'K4-3 Cash Vs Credit Customer');
        $this->cleanupCustomerIds[] = $customer['id'];
        $cashToken = testRandomToken();
        $creditToken = testRandomToken();
        $this->cleanupIdempotencyTokens = array_merge($this->cleanupIdempotencyTokens, [$cashToken, $creditToken]);

        $results = $this->runParallel([
            ['pos_sale_race.php', 'cash', "$productId:8", $cashToken, (string) $userId, '_NA_'],
            ['pos_sale_race.php', 'credit', "$productId:4", $creditToken, (string) $userId, 'existing:' . $customer['id']],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'combined demand (8+4=12) fits supply (15): ' . json_encode($results));
        }

        $this->assertSame(3, $this->currentStock($productId), '15 - 8 - 4');
        $this->assertSame(2, $this->saleTransactionCountForProduct($productId), 'one sale transaction per payment method');
        $this->assertSame(1, $this->debtCountForCustomer($customer['id']), 'only the credit sale creates a debt');
        $this->assertSame(12, $this->allocationSumForProduct($productId), 'combined allocation across both payment methods must equal combined demand');
        $this->assertBatchInvariantHolds($productId);
    }

    // ---- K4-5: Track Batches enablement concurrency ----
    //
    // Both tests below drive tests/Concurrency/enable_track_batches_race.php,
    // which calls the real, unmodified enableTrackBatches() from
    // includes/stock.php - no application-level lock/mutex of any kind
    // exists anywhere in this codebase for these tests to exercise.
    // enableTrackBatches() takes only ONE lock (the product row via
    // SELECT ... FOR UPDATE) and never touches reference_counters at all
    // (no stock_transactions row is created for this conversion - see
    // that function's own comment) - so, unlike every other stock-
    // mutating path in this file, it cannot participate in an opposite-
    // lock-order deadlock with anything: a deadlock requires a cycle of
    // two transactions each waiting on a lock the other holds, and this
    // function only ever requests the one lock it needs. Whatever safety
    // these tests observe comes entirely from that single product-row
    // lock being exclusive.

    public function testConcurrentEnableTrackBatchesOnTheSameProductCreatesExactlyOneOpeningBatch(): void
    {
        $productId = $this->seedProduct(40); // track_batches=0, real pre-tracking stock
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['enable_track_batches_race.php', (string) $productId, (string) $userId],
            ['enable_track_batches_race.php', (string) $productId, (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'both concurrent enable attempts must succeed (the loser is a no-op, not an error): ' . json_encode($results));
        }

        $stmt = $this->pdo->prepare('SELECT track_batches, current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        $this->assertSame(1, (int) $product['track_batches']);
        $this->assertSame(40, (int) $product['current_stock'], 'current_stock must never be incremented/decremented by enabling tracking');

        $batches = $this->batchesForProduct($productId);
        $this->assertCount(1, $batches, 'exactly one opening-balance batch may exist - the second racer must never fabricate a duplicate');
        $this->assertNull($batches[0]['batch_number']);
        $this->assertNull($batches[0]['expiry_date']);
        $this->assertSame(40, (int) $batches[0]['qty_on_hand']);
        $this->assertSame('opening_balance', $batches[0]['origin']);
        $this->assertBatchInvariantHolds($productId);
    }

    public function testConcurrentEnableTrackBatchesAndStockInOnTheSameProductPreserveTheInvariant(): void
    {
        // Genuinely order-dependent race (see this phase's implementation
        // report for the full analysis of both possible orderings) - if
        // Stock In's product-row lock wins first, it still sees
        // track_batches=0 (the enable side hasn't committed yet) and
        // takes the plain untracked increment path, so the enable side
        // then creates one opening batch covering the ALREADY-increased
        // current_stock; if the enable side wins first, Stock In sees
        // track_batches=1 and creates its own separate batch for the new
        // stock. Both orderings are correct - what must hold regardless
        // of winner is the invariant and the final total, not a specific
        // batch layout, so that is all this test asserts.
        $productId = $this->seedProduct(20); // track_batches=0
        $userId = $this->seedUser();

        $results = $this->runParallel([
            ['enable_track_batches_race.php', (string) $productId, (string) $userId],
            ['stock_in_batch_race.php', (string) $productId, 'LOT-NEW', '2027-12-31', '5', (string) $userId],
        ]);

        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], json_encode($results));
        }

        $stmt = $this->pdo->prepare('SELECT track_batches, current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        $this->assertSame(1, (int) $product['track_batches']);
        $this->assertSame(25, (int) $product['current_stock'], '20 + 5, no lost update regardless of which side won the race');
        $this->assertBatchInvariantHolds($productId);
    }

    // ---- P0 #17: Reference Numbers (concurrent generation) ----

    public function testConcurrentReferenceGenerationNeverProducesDuplicates(): void
    {
        $counterKey = 'stock_transactions';
        $stmt = $this->pdo->prepare('SELECT next_value FROM reference_counters WHERE counter_key = ?');
        $stmt->execute([$counterKey]);
        $startValue = (int) $stmt->fetchColumn();

        $workerCount = 8;
        $commands = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $commands[] = ['reference_race.php', $counterKey];
        }
        $results = $this->runParallel($commands);

        $values = [];
        foreach ($results as $r) {
            $this->assertSame('ok', $r['status'], 'every worker must succeed: ' . json_encode($r));
            $values[] = $r['value'];
        }

        $this->assertCount($workerCount, array_unique($values), 'no two concurrent callers may receive the same sequence number');

        // No failures/rollbacks happened in this race, so the specific
        // set of numbers handed out here is expected to be exactly the
        // next $workerCount consecutive integers - a stronger check than
        // "no duplicates" alone, but only asserted for this all-success
        // scenario, not as a general no-gaps-ever guarantee.
        sort($values);
        $expected = range($startValue, $startValue + $workerCount - 1);
        $this->assertSame($expected, $values, 'with no failures, the counter must advance without gaps');
    }

    /** @return array{status:string, value?:int} */
    private function runParallel(array $commands): array
    {
        $goAt = microtime(true) + 0.5; // gives every worker time to start/connect before racing
        $processes = [];
        foreach ($commands as $cmd) {
            $script = __DIR__ . '/' . $cmd[0];
            $args = array_slice($cmd, 1);
            $fullCmd = array_merge([PHP_BINARY, $script], $args, [(string) $goAt]);
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($fullCmd, $descriptors, $pipes);
            $this->assertIsResource($proc, 'failed to launch worker process: ' . $cmd[0]);
            $processes[] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($processes as $p) {
            $stdout = stream_get_contents($p['pipes'][1]);
            $stderr = stream_get_contents($p['pipes'][2]);
            fclose($p['pipes'][1]);
            fclose($p['pipes'][2]);
            $exitCode = proc_close($p['proc']);
            $this->assertSame(0, $exitCode, "worker process exited nonzero ($exitCode); stderr: $stderr");
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, "worker produced non-JSON output: '$stdout' (stderr: $stderr)");
            $results[] = $decoded;
        }
        return $results;
    }

    private function seedProduct(int $stock): int
    {
        $sku = 'CONC-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock) VALUES (?,?,?,?,?)');
        $stmt->execute(['Concurrency Test Product', $sku, 1, 1, $stock]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedTrackedProduct(): int
    {
        $sku = 'CONC-BATCH-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,?,1)');
        $stmt->execute(['Concurrency Batch Test Product', $sku, 1, 1, 0]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function batchesForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    // K3-1: same shape as seedTrackedProduct() above, but with a real
    // starting current_stock - Stock Out concurrency tests need actual
    // stock to consume, unlike Stock In's tests which always start from 0.
    private function seedTrackedProductWithStock(int $stock): int
    {
        $sku = 'CONC-STOCKOUT-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,?,1)');
        $stmt->execute(['Concurrency Stock Out Test Product', $sku, 1, 1, $stock]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedBatchDirect(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty, 'stock_in']);
        return (int) $this->pdo->lastInsertId();
    }

    private function batchQtyOnHand(int $batchId): int
    {
        $stmt = $this->pdo->prepare('SELECT qty_on_hand FROM product_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    // ---- K4-3 helpers ----

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    // Every K4-3 worker records its sale as type='sale' (both
    // recordStockOut()'s cash path and recordCreditSale()'s internal
    // insert use 'sale'), same as the real POS page - so this is scoped
    // to that type, not stock_transactions in general.
    private function saleTransactionCountForProduct(int $productId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT sti.transaction_id) FROM stock_transaction_items sti
                                      JOIN stock_transactions st ON st.id = sti.transaction_id
                                      WHERE sti.product_id = ? AND st.type = 'sale'");
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function allocationsForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT sib.batch_id, sib.qty FROM stock_transaction_item_batches sib
                                       JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                                       WHERE sti.product_id = ? ORDER BY sib.batch_id');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function allocationSumForBatch(int $batchId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_transaction_item_batches WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    private function allocationSumForProduct(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(sib.qty), 0) FROM stock_transaction_item_batches sib
                                       JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                                       WHERE sti.product_id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function idempotencyKeyCount(string $token): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE token = ?');
        $stmt->execute([$token]);
        return (int) $stmt->fetchColumn();
    }

    private function referenceCounterValue(string $counterKey): int
    {
        $stmt = $this->pdo->prepare('SELECT next_value FROM reference_counters WHERE counter_key = ?');
        $stmt->execute([$counterKey]);
        return (int) $stmt->fetchColumn();
    }

    private function debtCountForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM customer_debts WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        return (int) $stmt->fetchColumn();
    }

    private function customerCountByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM customers WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    private function customerIdByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM customers WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    // The required K3 invariant: for a tracked product, current_stock
    // must always equal the sum of its own batches' qty_on_hand.
    private function assertBatchInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $currentStock = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();

        $this->assertSame($currentStock, $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }

    private function seedUser(): int
    {
        $email = 'conc.' . bin2hex(random_bytes(4)) . '@test.local';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['Concurrency Test User', $email, password_hash('x', PASSWORD_DEFAULT)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupUserIds[] = $id;
        return $id;
    }

    /** @return array{0:int,1:int,2:int} [debtId, customerId, userId] */
    private function seedDebt(float $total, float $alreadyPaid): array
    {
        $userId = $this->seedUser();
        $stmt = $this->pdo->prepare('INSERT INTO customers (name) VALUES (?)');
        $stmt->execute(['Concurrency Test Customer']);
        $customerId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO customer_debts (reference, customer_id, total_amount, paid_amount, created_by, updated_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['CONC-' . bin2hex(random_bytes(4)), $customerId, $total, $alreadyPaid, $userId, $userId]);
        $debtId = (int) $this->pdo->lastInsertId();

        return [$debtId, $customerId, $userId];
    }
}
