<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K2b-1B (Stock In Batch/Expiry UI). Drives the real
// stock-in/index.php page over real HTTP, specifically the new
// batch_number[]/expiry_date[] POST parsing this phase added - the
// '' -> null normalization, the 60-character validation, and that the
// values actually reach recordStockIn() (includes/stock.php, unmodified
// by K2b) correctly. K2a's own test suite (tests/Integration/StockTest.php,
// IdempotencyTest.php, tests/Concurrency/ConcurrencyTest.php) already
// proves recordStockIn()/findOrCreateBatch() themselves are correct at
// the PHP-function level; this file proves the new page-level glue in
// front of them is correct too. See HttpServerTestCase for why this must
// be a real HTTP request rather than an in-process include.
final class StockInBatchUiTest extends HttpServerTestCase
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

    // ---- Tracked product: all four batch/expiry combinations ----

    public function testTrackedProductWithBatchAndExpirySubmitsCorrectly(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $res = $this->submitLine($jar, $productId, '5', 'LOT-BOTH', '2027-01-01');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertSame('LOT-BOTH', $batch['batch_number']);
        $this->assertSame('2027-01-01', $batch['expiry_date']);
        $this->assertSame(5, (int) $batch['qty_on_hand']);
        $this->assertSame(5, (int) $batch['qty_received']);
    }

    public function testTrackedProductWithBatchOnlyLeavesExpiryNull(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $res = $this->submitLine($jar, $productId, '3', 'LOT-ONLY', '');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertSame('LOT-ONLY', $batch['batch_number']);
        $this->assertNull($batch['expiry_date'], 'a blank expiry field must reach the DB as NULL, not an empty string');
    }

    public function testTrackedProductWithExpiryOnlyLeavesBatchNumberNull(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $res = $this->submitLine($jar, $productId, '4', '', '2027-06-01');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertNull($batch['batch_number'], 'a blank batch number field must reach the DB as NULL, not an empty string');
        $this->assertSame('2027-06-01', $batch['expiry_date']);
    }

    public function testTrackedProductWithNeitherFieldCreatesAnonymousBatch(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $res = $this->submitLine($jar, $productId, '7', '', '');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertNull($batch['batch_number']);
        $this->assertNull($batch['expiry_date']);
        $this->assertSame(7, (int) $batch['qty_on_hand']);
    }

    // ---- Batch number validation ----

    public function testExactly60CharacterBatchNumberIsAccepted(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);
        $sixty = str_repeat('A', 60);

        $res = $this->submitLine($jar, $productId, '2', $sixty, '');
        $this->assertSame(302, $res['status'], '60 characters must be accepted: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertSame($sixty, $batch['batch_number']);
    }

    public function test61CharacterBatchNumberIsRejectedWithNoMutation(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);
        $sixtyOne = str_repeat('A', 61);
        $countBefore = $this->countStockInTransactions();

        $res = $this->submitLine($jar, $productId, '2', $sixtyOne, '');

        // Rejected server-side: re-renders the same page (200) with an
        // error, the same convention insufficient-stock/duplicate-token
        // errors already use elsewhere in this app - not a 4xx status.
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('60', $res['body']);
        $this->assertSame($countBefore, $this->countStockInTransactions(), 'a rejected batch number must create no transaction');
        $this->assertCount(0, $this->batchesFor($productId), 'a rejected batch number must create no product_batches row');
        $this->assertSame(0, $this->currentStock($productId), 'stock must be unchanged after a rejected submission');
    }

    public function testBatchNumberWhitespaceIsTrimmed(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $res = $this->submitLine($jar, $productId, '2', '  LOT-TRIM  ', '');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertSame('LOT-TRIM', $batch['batch_number']);
    }

    public function testWhitespaceOnlyBatchNumberNormalizesToNullAnonymousBatch(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $res = $this->submitLine($jar, $productId, '2', '   ', '');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $batch = $this->soleBatchFor($productId);
        $this->assertNull($batch['batch_number'], 'whitespace-only input must trim to empty, then normalize to NULL, not be stored as spaces');
    }

    // ---- Untracked product: unaffected ----

    public function testUntrackedProductStockInBehaviorIsUnchangedAndIgnoresBatchValues(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(0);

        $res = $this->submitLine($jar, $productId, '9', 'SHOULD-BE-IGNORED', '2027-01-01');
        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);

        $this->assertSame(9, $this->currentStock($productId));
        $this->assertCount(0, $this->batchesFor($productId), 'an untracked product must never get a product_batches row, regardless of what the (hidden) batch fields contained');
    }

    // ---- Existing batch receipt merges ----

    public function testReceivingTheSameBatchIdentityAgainMergesIntoTheExistingRow(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $this->submitLine($jar, $productId, '5', 'LOT-MERGE', '2027-03-01');
        $res = $this->submitLine($jar, $productId, '3', 'LOT-MERGE', '2027-03-01');
        $this->assertSame(302, $res['status'], 'second submission must succeed: ' . $res['body']);

        $batches = $this->batchesFor($productId);
        $this->assertCount(1, $batches, 'the second receipt of the same identity must merge, not create a second row');
        $this->assertSame(8, (int) $batches[0]['qty_on_hand']);
    }

    // ---- Anonymous batches never merge ----

    public function testTwoSeparateAnonymousReceiptsCreateTwoBatchRows(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $this->submitLine($jar, $productId, '4', '', '');
        $res = $this->submitLine($jar, $productId, '6', '', '');
        $this->assertSame(302, $res['status'], 'second submission must succeed: ' . $res['body']);

        $batches = $this->batchesFor($productId);
        $this->assertCount(2, $batches, 'two separate anonymous receipts must never merge into one batch');
        $this->assertSame(10, $this->currentStock($productId));
    }

    // ---- Idempotency (through the real form) ----

    public function testDuplicateSubmissionOfATrackedLineCreatesExactlyOneTransactionAndBatch(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1);

        $form = $this->httpGet($jar, '/stock-in/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $fields = $this->lineFields($csrfToken, $idempotencyToken, $productId, '5', 'LOT-IDEMP', '2027-01-01');
        $countBefore = $this->countStockInTransactions();

        $res1 = $this->httpPost($jar, '/stock-in/index.php', $fields);
        $this->assertSame(302, $res1['status'], 'first submission must succeed: ' . $res1['body']);
        // Same idempotency_token resubmitted (double-click / browser
        // retry), exactly as pos/index.php's own idempotency tests model it.
        $res2 = $this->httpPost($jar, '/stock-in/index.php', $fields);
        $this->assertSame(200, $res2['status'], 'a duplicate submission re-renders with an error, not a redirect');

        $this->assertSame($countBefore + 1, $this->countStockInTransactions(), 'only the first submission may create a transaction');
        $this->assertCount(1, $this->batchesFor($productId), 'only the first submission may create a batch');
        $this->assertSame(5, $this->currentStock($productId), 'stock must be incremented exactly once');
    }

    // ---- helpers ----

    private function submitLine(string $jar, int $productId, string $qty, string $batchNumber, string $expiryDate): array
    {
        $form = $this->httpGet($jar, '/stock-in/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        return $this->httpPost($jar, '/stock-in/index.php', $this->lineFields($csrfToken, $idempotencyToken, $productId, $qty, $batchNumber, $expiryDate));
    }

    private function lineFields(string $csrfToken, string $idempotencyToken, int $productId, string $qty, string $batchNumber, string $expiryDate): array
    {
        return [
            'csrf_token' => $csrfToken,
            'idempotency_token' => $idempotencyToken,
            'supplier_id' => '',
            'transaction_date' => date('Y-m-d'),
            'note' => 'K2b batch UI test',
            'product_id' => [(string) $productId],
            'qty' => [$qty],
            'unit_cost' => ['1.00'],
            'unit_cost_currency' => ['USD'],
            'batch_number' => [$batchNumber],
            'expiry_date' => [$expiryDate],
        ];
    }

    private function extractIdempotencyToken(string $html): string
    {
        $this->assertMatchesRegularExpression('/name="idempotency_token" value="([a-f0-9]+)"/', $html);
        preg_match('/name="idempotency_token" value="([a-f0-9]+)"/', $html, $m);
        return $m[1];
    }

    /** @return array{batch_number:?string,expiry_date:?string,qty_on_hand:int,qty_received:int} */
    private function soleBatchFor(int $productId): array
    {
        $batches = $this->batchesFor($productId);
        $this->assertCount(1, $batches, "expected exactly one product_batches row for product $productId");
        return $batches[0];
    }

    private function batchesFor(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function countStockInTransactions(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'in'")->fetchColumn();
    }

    private function seedProduct(int $trackBatches): int
    {
        $sku = 'K2B-STI-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,0,?)');
        $stmt->execute(['K2b Stock In Test Product', $sku, 1, 2, $trackBatches]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function loggedInSession(): string
    {
        $email = 'k2b.stockin.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K2b Stock In Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
