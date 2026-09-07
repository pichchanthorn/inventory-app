<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K3-2 (Stock Out FEFO Integration). Drives the real
// stock-out/index.php page over real HTTP, specifically proving its
// recordStockOut() call site now passes consumeBatches: true - the one
// line this phase changes. K3-1's own test suite (tests/Integration/
// StockTest.php, IdempotencyTest.php, tests/Concurrency/ConcurrencyTest.php)
// already proves the FEFO allocation engine itself (recordStockOut()/
// insertStockOutLines(), includes/stock.php) is correct at the PHP-
// function level, called directly with consumeBatches: true; this file
// proves the real page actually reaches that engine the same way,
// through an ordinary browser-shaped POST, rather than merely asserting
// the source line was edited. See HttpServerTestCase for why this must
// be a real HTTP request rather than an in-process include.
final class StockOutFefoIntegrationTest extends HttpServerTestCase
{
    private array $cleanupProductIds = [];
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupProductIds as $id) {
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

    public function testUntrackedProductStockOutStillWorksExactlyAsBefore(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(0, 20);

        $res = $this->submitLine($jar, $productId, '5');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $this->assertSame(15, $this->currentStock($productId));
        $this->assertCount(0, $this->batchesFor($productId), 'an untracked product must never get a product_batches row');
    }

    public function testTrackedProductWithOneBatchIsDecrementedThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 20);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 20);

        $res = $this->submitLine($jar, $productId, '8');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $this->assertSame(12, $this->currentStock($productId));
        $this->assertSame(12, $this->batchQtyOnHand($batchId));
        $this->assertInvariantHolds($productId);
    }

    public function testTrackedProductConsumingMultipleBatchesThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $lotA = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($productId, 'LOT-B', '2027-06-01', 10);

        $res = $this->submitLine($jar, $productId, '8');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $this->assertSame(0, $this->batchQtyOnHand($lotA), 'the earlier-expiring batch must be fully consumed first');
        $this->assertSame(7, $this->batchQtyOnHand($lotB), '10 - 3, only the remainder drawn from the later batch');
        $this->assertSame(7, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);

        $stmt = $this->pdo->prepare('SELECT sib.batch_id, sib.qty FROM stock_transaction_item_batches sib
                                       JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                                       WHERE sti.product_id = ? ORDER BY sib.batch_id');
        $stmt->execute([$productId]);
        $allocations = $stmt->fetchAll();
        $this->assertCount(2, $allocations, 'one allocation ledger row per batch drawn from');
        $this->assertSame(5, (int) $allocations[0]['qty']);
        $this->assertSame(3, (int) $allocations[1]['qty']);
    }

    public function testFefoOrderingThroughTheRealPagePrefersEarliestExpiryThenLaterThenUndated(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $earliest = $this->seedBatch($productId, 'LOT-EARLY', '2027-01-01', 3);
        $later = $this->seedBatch($productId, 'LOT-LATER', '2027-06-01', 4);
        $undated = $this->seedBatch($productId, 'LOT-UNDATED', null, 8);

        // Request more than the two dated batches combined (3+4=7) to
        // force FEFO to spill into the undated batch last.
        $res = $this->submitLine($jar, $productId, '10');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $this->assertSame(0, $this->batchQtyOnHand($earliest), 'earliest-expiry batch consumed first');
        $this->assertSame(0, $this->batchQtyOnHand($later), 'later-expiry batch consumed second');
        $this->assertSame(5, $this->batchQtyOnHand($undated), '8 - 3, undated batch only touched last and only for the remainder');
        $this->assertInvariantHolds($productId);
    }

    public function testInsufficientBatchStockIsRejectedWithNoMutationThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 8);
        $lotA = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($productId, 'LOT-B', '2027-06-01', 3);
        $countBefore = $this->countStockOutTransactions();
        $refCounterBefore = $this->referenceCounterValue();

        $res = $this->submitLine($jar, $productId, '20');

        // Rejected server-side: re-renders the same page (200) with an
        // error, the same convention every other Stock Out failure uses.
        $this->assertSame(200, $res['status']);
        $this->assertSame($countBefore, $this->countStockOutTransactions(), 'no transaction may be created');
        $this->assertSame(5, $this->batchQtyOnHand($lotA), 'no partial allocation from batch A');
        $this->assertSame(3, $this->batchQtyOnHand($lotB), 'no partial allocation from batch B');
        $this->assertSame(8, $this->currentStock($productId), 'current_stock must be unchanged');
        $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must not be burned');
        $this->assertInvariantHolds($productId);
    }

    public function testDuplicateSubmissionOfATrackedLineCreatesExactlyOneTransactionAndAllocation(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 10);
        $batchId = $this->seedBatch($productId, 'LOT-IDEMP', '2027-01-01', 10);

        $form = $this->httpGet($jar, '/stock-out/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $fields = $this->lineFields($csrfToken, $idempotencyToken, $productId, '4');
        $countBefore = $this->countStockOutTransactions();

        $res1 = $this->httpPost($jar, '/stock-out/index.php', $fields);
        $this->assertSame(302, $res1['status'], 'first submission must succeed: ' . $res1['body']);
        // Same idempotency_token resubmitted (double-click / browser retry).
        $res2 = $this->httpPost($jar, '/stock-out/index.php', $fields);
        $this->assertSame(200, $res2['status'], 'a duplicate submission re-renders with an error, not a redirect');

        $this->assertSame($countBefore + 1, $this->countStockOutTransactions(), 'only the first submission may create a transaction');
        $this->assertSame(6, $this->batchQtyOnHand($batchId), 'batch must be decremented exactly once');
        $this->assertSame(6, $this->currentStock($productId), 'stock must be decremented exactly once');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_item_batches WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'only one allocation ledger row may exist');
        $this->assertInvariantHolds($productId);
    }

    private function submitLine(string $jar, int $productId, string $qty): array
    {
        $form = $this->httpGet($jar, '/stock-out/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        return $this->httpPost($jar, '/stock-out/index.php', $this->lineFields($csrfToken, $idempotencyToken, $productId, $qty));
    }

    private function lineFields(string $csrfToken, string $idempotencyToken, int $productId, string $qty): array
    {
        return [
            'csrf_token' => $csrfToken,
            'idempotency_token' => $idempotencyToken,
            'transaction_date' => date('Y-m-d'),
            'note' => 'K3-2 FEFO integration test',
            'product_id' => [(string) $productId],
            'qty' => [$qty],
            'unit_price' => ['1.00'],
        ];
    }

    private function extractIdempotencyToken(string $html): string
    {
        $this->assertMatchesRegularExpression('/name="idempotency_token" value="([a-f0-9]+)"/', $html);
        preg_match('/name="idempotency_token" value="([a-f0-9]+)"/', $html, $m);
        return $m[1];
    }

    private function batchesFor(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function batchQtyOnHand(int $batchId): int
    {
        $stmt = $this->pdo->prepare('SELECT qty_on_hand FROM product_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    private function seedBatch(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand) VALUES (?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty]);
        return (int) $this->pdo->lastInsertId();
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function countStockOutTransactions(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'out'")->fetchColumn();
    }

    private function referenceCounterValue(): int
    {
        return (int) $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'")->fetchColumn();
    }

    // The required K3 invariant: for a tracked product, current_stock
    // must always equal the sum of its own batches' qty_on_hand.
    private function assertInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();
        $this->assertSame($this->currentStock($productId), $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }

    private function seedProduct(int $trackBatches, int $stock): int
    {
        $sku = 'K3-2-STO-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['K3-2 Stock Out Test Product', $sku, 1, 2, $stock, $trackBatches]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function loggedInSession(): string
    {
        $email = 'k3-2.stockout.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K3-2 Stock Out Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
