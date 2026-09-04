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
