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
