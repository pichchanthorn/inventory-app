<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K4-2 (POS HTTP + Idempotency Integration). Drives the real
// pos/index.php page over real HTTP for both payment methods, proving
// what no existing test proves: that the actual $_POST -> $lines[]
// construction in pos/index.php reaches recordStockOut()/
// recordCreditSale() with consumeBatches: true (Phase K4-1), that the
// idempotency token round-trips correctly through the real hidden form
// field and a real second request, and that a mid-cart failure rolls
// back through the real HTTP boundary - not just the PHP function call.
//
// FEFO allocation correctness itself (ordering, tie-breaks, NULL-expiry
// semantics, multi-batch math) is already fully proven at the function
// level by tests/Integration/StockTest.php and tests/Concurrency/
// ConcurrencyTest.php (K3-1) and tests/Integration/DebtTest.php (K4-1).
// Nothing here reimplements or re-derives that logic - every batch plan
// below is deterministic and asserted against the resulting DB state,
// never computed in the test itself. See HttpServerTestCase for why this
// must be a real HTTP request rather than an in-process include.
final class PosFefoIntegrationTest extends HttpServerTestCase
{
    private array $cleanupProductIds = [];
    private array $cleanupUserIds = [];
    private array $cleanupCustomerIds = [];

    protected function tearDown(): void
    {
        // Same order/shape as StockOutFefoIntegrationTest.php's own
        // tearDown - stock_transactions header rows are deliberately left
        // in place (never scoped/deleted here), matching that file's
        // established convention: nothing in this suite asserts on a raw
        // total transaction count, only on rows scoped to the specific
        // product/customer ids each test creates.
        foreach ($this->cleanupCustomerIds as $id) {
            $this->pdo->exec("DELETE cd FROM customer_debts cd WHERE cd.customer_id = $id");
            $this->pdo->exec("DELETE FROM customers WHERE id = $id");
        }
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

    // ---- 1-2: untracked regression ----

    public function testCashSaleOfAnUntrackedProductRemainsUnaffectedThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(0, 20);

        $res = $this->submitCash($jar, [['product_id' => $productId, 'qty' => 5, 'price' => 2.00]], 10.00);

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(15, $this->currentStock($productId));
        $this->assertCount(0, $this->batchesFor($productId), 'an untracked product must never get a product_batches row');
    }

    public function testCreditSaleOfAnUntrackedProductRemainsUnaffectedThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(0, 20);

        $res = $this->submitCreditNewCustomer($jar, [['product_id' => $productId, 'qty' => 5, 'price' => 2.00]], 'Untracked POS Farmer', '011000000');

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(15, $this->currentStock($productId));
        $this->assertCount(0, $this->batchesFor($productId), 'no batch allocation may exist for an untracked product');

        $stmt = $this->pdo->prepare('SELECT c.id AS customer_id, cd.total_amount FROM customer_debts cd
                                      JOIN customers c ON c.id = cd.customer_id
                                      WHERE c.name = ?');
        $stmt->execute(['Untracked POS Farmer']);
        $debt = $stmt->fetch();
        $this->assertNotFalse($debt, 'a debt row must be created');
        $this->cleanupCustomerIds[] = (int) $debt['customer_id'];
        $this->assertEqualsWithDelta(10.00, (float) $debt['total_amount'], 0.001, '5 * 2.00');
    }

    // ---- 3-4: single batch ----

    public function testCashSaleOfATrackedProductWithOneBatchIsDecrementedThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 20);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 20);

        $res = $this->submitCash($jar, [['product_id' => $productId, 'qty' => 8, 'price' => 2.00]], 20.00);

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(12, $this->currentStock($productId));
        $this->assertSame(12, $this->batchQtyOnHand($batchId));
        $this->assertAllocationSum($productId, 8);
        $this->assertInvariantHolds($productId);
    }

    public function testCreditSaleOfATrackedProductWithOneBatchForAnExistingCustomerIsDecrementedThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 20);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 20);
        $customer = testSeedCustomer($this->pdo, 'Existing POS Customer');
        $this->cleanupCustomerIds[] = $customer['id'];

        $res = $this->submitCreditExistingCustomer($jar, [['product_id' => $productId, 'qty' => 8, 'price' => 2.00]], $customer['id']);

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(12, $this->currentStock($productId));
        $this->assertSame(12, $this->batchQtyOnHand($batchId));
        $this->assertAllocationSum($productId, 8);
        $this->assertInvariantHolds($productId);

        $stmt = $this->pdo->prepare('SELECT total_amount FROM customer_debts WHERE customer_id = ?');
        $stmt->execute([$customer['id']]);
        $this->assertEqualsWithDelta(16.00, (float) $stmt->fetchColumn(), 0.001, '8 * 2.00');
    }

    // ---- 5-6: multi-batch (approved A=5/B=10/request=8 example) ----

    public function testCashSaleOfATrackedProductConsumingMultipleBatchesThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $lotA = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($productId, 'LOT-B', '2027-06-01', 10);

        $res = $this->submitCash($jar, [['product_id' => $productId, 'qty' => 8, 'price' => 2.00]], 20.00);

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(0, $this->batchQtyOnHand($lotA), 'the earlier-expiring batch must be fully consumed first');
        $this->assertSame(7, $this->batchQtyOnHand($lotB), '10 - 3, only the remainder drawn from the later batch');
        $this->assertSame(7, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);

        $allocations = $this->allocationsFor($productId);
        $this->assertCount(2, $allocations, 'one allocation ledger row per batch drawn from');
        $this->assertSame(5, (int) $allocations[0]['qty']);
        $this->assertSame(3, (int) $allocations[1]['qty']);
    }

    public function testCreditSaleOfATrackedProductConsumingMultipleBatchesForANewCustomerThroughTheRealPage(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $lotA = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($productId, 'LOT-B', '2027-06-01', 10);

        $res = $this->submitCreditNewCustomer($jar, [['product_id' => $productId, 'qty' => 8, 'price' => 2.50]], 'Multi-Batch POS Farmer', '011000001');

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(0, $this->batchQtyOnHand($lotA));
        $this->assertSame(7, $this->batchQtyOnHand($lotB));
        $this->assertSame(7, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);

        $allocations = $this->allocationsFor($productId);
        $this->assertCount(2, $allocations);
        $this->assertSame(5, (int) $allocations[0]['qty']);
        $this->assertSame(3, (int) $allocations[1]['qty']);

        $stmt = $this->pdo->prepare('SELECT c.id, cd.total_amount FROM customer_debts cd
                                      JOIN customers c ON c.id = cd.customer_id
                                      WHERE c.name = ?');
        $stmt->execute(['Multi-Batch POS Farmer']);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'the new customer must have been created and a debt recorded against them');
        $this->cleanupCustomerIds[] = (int) $row['id'];
        $this->assertEqualsWithDelta(20.00, (float) $row['total_amount'], 0.001, '8 * 2.50');
    }

    // ---- 7: FEFO ordering (dated-earlier -> dated-later -> undated) ----

    public function testCashSaleFefoOrderingThroughTheRealPagePrefersEarliestExpiryThenLaterThenUndated(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $earliest = $this->seedBatch($productId, 'LOT-EARLY', '2027-01-01', 3);
        $later = $this->seedBatch($productId, 'LOT-LATER', '2027-06-01', 4);
        $undated = $this->seedBatch($productId, 'LOT-UNDATED', null, 8);

        // Requesting more than the two dated batches combined (3+4=7)
        // forces FEFO to spill into the undated batch last.
        $res = $this->submitCash($jar, [['product_id' => $productId, 'qty' => 10, 'price' => 1.00]], 10.00);

        $this->assertSame(302, $res['status'], 'submission must succeed: ' . $res['body']);
        $this->assertSame(0, $this->batchQtyOnHand($earliest), 'earliest-expiry batch consumed first');
        $this->assertSame(0, $this->batchQtyOnHand($later), 'later-expiry batch consumed second');
        $this->assertSame(5, $this->batchQtyOnHand($undated), '8 - 3, undated batch only touched last and only for the remainder');
        $this->assertInvariantHolds($productId);
    }

    // ---- 8-9: idempotency (real HTTP replay, same session/token) ----

    public function testDuplicateCashSubmissionOfATrackedLineCreatesExactlyOneTransactionAndAllocation(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 20);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 20);

        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $fields = $this->cashFields($csrfToken, $idempotencyToken, [['product_id' => $productId, 'qty' => 5, 'price' => 2.00]], 10.00);

        $res1 = $this->httpPost($jar, '/pos/index.php', $fields);
        $this->assertSame(302, $res1['status'], 'first submission must succeed: ' . $res1['body']);

        // Same idempotency_token resubmitted (double-click / browser retry).
        $res2 = $this->httpPost($jar, '/pos/index.php', $fields);
        $this->assertSame(200, $res2['status'], 'a duplicate submission must re-render with an error, not redirect');
        $this->assertStringContainsString('duplicate submission', $res2['body'], 'the duplicate-submission error must be surfaced');

        // No accidental *stale* receipt left in the session: $_SESSION
        // ['pos_last_sale'] is read into a local var at the very top of
        // every request (success or not) before unset() runs, so a
        // genuine back-to-back duplicate POST (no intervening GET, as
        // above) legitimately re-renders res1's own real receipt
        // alongside the duplicate-submission toast on res2 itself - that
        // is not a data bug (no double mutation, asserted below) and this
        // suite does not assert on that specific HTML. What matters is
        // that res2's request unconditionally unset() the session key, so
        // a later, unrelated GET - never having POSTed anything itself -
        // must not show any receipt at all.
        $followUp = $this->httpGet($jar, '/pos/index.php');
        $this->assertStringNotContainsString('id="posReceipt"', $followUp['body'], 'no stale receipt may survive in the session for a later, unrelated GET');

        $this->assertSame(15, $this->currentStock($productId), 'stock must be decremented exactly once');
        $this->assertSame(15, $this->batchQtyOnHand($batchId), 'batch must be decremented exactly once');
        $this->assertCount(1, $this->allocationsFor($productId), 'exactly one allocation ledger row may exist');
        $this->assertInvariantHolds($productId);
    }

    public function testDuplicateCreditSubmissionOfATrackedLineCreatesExactlyOneSaleDebtAndAllocation(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 20);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 20);

        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $fields = $this->creditNewCustomerFields($csrfToken, $idempotencyToken, [['product_id' => $productId, 'qty' => 5, 'price' => 2.00]], 'Duplicate Credit Farmer', '011000002');

        $res1 = $this->httpPost($jar, '/pos/index.php', $fields);
        $this->assertSame(302, $res1['status'], 'first submission must succeed: ' . $res1['body']);

        $customerCountAfterFirst = (int) $this->pdo->query("SELECT COUNT(*) FROM customers WHERE name = 'Duplicate Credit Farmer'")->fetchColumn();
        $this->assertSame(1, $customerCountAfterFirst, 'the new customer must be created exactly once');
        $customerId = (int) $this->pdo->query("SELECT id FROM customers WHERE name = 'Duplicate Credit Farmer'")->fetchColumn();
        $this->cleanupCustomerIds[] = $customerId;

        $res2 = $this->httpPost($jar, '/pos/index.php', $fields);
        $this->assertSame(200, $res2['status'], 'a duplicate submission must re-render with an error, not redirect');
        $this->assertStringContainsString('duplicate submission', $res2['body'], 'the duplicate-submission error must be surfaced');

        // See testDuplicateCashSubmissionOfATrackedLineCreatesExactlyOne
        // TransactionAndAllocation() above for why this checks a later,
        // unrelated GET rather than res2's own body.
        $followUp = $this->httpGet($jar, '/pos/index.php');
        $this->assertStringNotContainsString('id="posReceipt"', $followUp['body'], 'no stale receipt may survive in the session for a later, unrelated GET');

        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM customers WHERE name = 'Duplicate Credit Farmer'")->fetchColumn(), 'no duplicate customer row');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts WHERE customer_id = ' . $customerId)->fetchColumn(), 'exactly one debt row');
        $this->assertSame(15, $this->currentStock($productId), 'stock must be decremented exactly once');
        $this->assertSame(15, $this->batchQtyOnHand($batchId), 'batch must be decremented exactly once');
        $this->assertCount(1, $this->allocationsFor($productId), 'exactly one allocation ledger row may exist');
        $this->assertInvariantHolds($productId);
    }

    // ---- 10-11: rollback (mid-cart failure after earlier-line mutation) ----

    public function testCashSaleRollsBackWhenALaterLineFailsAfterEarlierBatchAllocation(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $lotA = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($productId, 'LOT-B', '2027-06-01', 10);
        $refCounterBefore = $this->referenceCounterValue();
        $nonexistentProductId = 999999;

        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $fields = $this->cashFields($csrfToken, $idempotencyToken, [
            // Would succeed alone: spans both batches (5 + 3).
            ['product_id' => $productId, 'qty' => 8, 'price' => 2.00],
            ['product_id' => $nonexistentProductId, 'qty' => 1, 'price' => 1.00],
        ], 100.00);

        $res = $this->httpPost($jar, '/pos/index.php', $fields);
        $this->assertSame(200, $res['status'], 'a failing cart must re-render with an error, not redirect');
        $this->assertStringContainsString('id="posForm"', $res['body']);

        $this->assertSame(5, $this->batchQtyOnHand($lotA), 'batch A must be restored');
        $this->assertSame(10, $this->batchQtyOnHand($lotB), 'batch B must be restored');
        $this->assertSame(15, $this->currentStock($productId), 'current_stock must be restored');
        $this->assertInvariantHolds($productId);
        $this->assertCount(0, $this->allocationsFor($productId), 'no allocation ledger row must survive');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
        $stmt->execute([$productId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no stock_transaction_items row must survive');
        $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must be rolled back');

        // The idempotency claim must have rolled back too - the same
        // token must still be usable for a fresh, valid submission.
        $retryFields = $this->cashFields($csrfToken, $idempotencyToken, [['product_id' => $productId, 'qty' => 5, 'price' => 2.00]], 20.00);
        $retry = $this->httpPost($jar, '/pos/index.php', $retryFields);
        $this->assertSame(302, $retry['status'], 'the retried submission with the same token must succeed: ' . $retry['body']);
        $this->assertSame(10, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);
    }

    public function testCreditSaleRollsBackNewCustomerBatchAllocationAndDebtWhenALaterLineFails(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(1, 15);
        $lotA = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($productId, 'LOT-B', '2027-06-01', 10);
        $refCounterBefore = $this->referenceCounterValue();
        $customerCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $debtCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn();
        $nonexistentProductId = 999999;

        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        $fields = $this->creditNewCustomerFields($csrfToken, $idempotencyToken, [
            ['product_id' => $productId, 'qty' => 8, 'price' => 2.00],
            ['product_id' => $nonexistentProductId, 'qty' => 1, 'price' => 1.00],
        ], 'Rollback Credit Farmer', '011000003');

        $res = $this->httpPost($jar, '/pos/index.php', $fields);
        $this->assertSame(200, $res['status'], 'a failing cart must re-render with an error, not redirect');
        $this->assertStringContainsString('id="posForm"', $res['body']);

        $this->assertSame(5, $this->batchQtyOnHand($lotA), 'batch A must be restored');
        $this->assertSame(10, $this->batchQtyOnHand($lotB), 'batch B must be restored');
        $this->assertSame(15, $this->currentStock($productId), 'current_stock must be restored');
        $this->assertInvariantHolds($productId);
        $this->assertCount(0, $this->allocationsFor($productId), 'no allocation ledger row must survive');

        $this->assertSame($customerCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), 'no new customer must survive');
        $this->assertSame($debtCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn(), 'no debt row must survive');
        $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must be rolled back');

        // The idempotency claim must have rolled back too, and the retry
        // must produce exactly the expected successful sale/debt/allocation.
        $retryFields = $this->creditNewCustomerFields($csrfToken, $idempotencyToken, [['product_id' => $productId, 'qty' => 5, 'price' => 2.00]], 'Rollback Credit Farmer Retry', '011000004');
        $retry = $this->httpPost($jar, '/pos/index.php', $retryFields);
        $this->assertSame(302, $retry['status'], 'the retried submission with the same token must succeed: ' . $retry['body']);

        $this->assertSame(10, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);
        $stmt = $this->pdo->prepare('SELECT c.id, cd.total_amount FROM customer_debts cd
                                      JOIN customers c ON c.id = cd.customer_id
                                      WHERE c.name = ?');
        $stmt->execute(['Rollback Credit Farmer Retry']);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'the retry must create exactly the expected new customer + debt');
        $this->cleanupCustomerIds[] = (int) $row['id'];
        $this->assertEqualsWithDelta(10.00, (float) $row['total_amount'], 0.001, '5 * 2.00');
    }

    // ---- helpers ----

    private function submitCash(string $jar, array $lines, float $cashReceived): array
    {
        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        return $this->httpPost($jar, '/pos/index.php', $this->cashFields($csrfToken, $idempotencyToken, $lines, $cashReceived));
    }

    private function submitCreditExistingCustomer(string $jar, array $lines, int $customerId): array
    {
        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        return $this->httpPost($jar, '/pos/index.php', $this->creditExistingCustomerFields($csrfToken, $idempotencyToken, $lines, $customerId));
    }

    private function submitCreditNewCustomer(string $jar, array $lines, string $name, string $phone): array
    {
        $form = $this->httpGet($jar, '/pos/index.php');
        $csrfToken = $this->extractCsrfToken($form['body']);
        $idempotencyToken = $this->extractIdempotencyToken($form['body']);
        return $this->httpPost($jar, '/pos/index.php', $this->creditNewCustomerFields($csrfToken, $idempotencyToken, $lines, $name, $phone));
    }

    private function cashFields(string $csrfToken, string $idempotencyToken, array $lines, float $cashReceived): array
    {
        return array_merge($this->lineFields($lines), [
            'csrf_token' => $csrfToken,
            'idempotency_token' => $idempotencyToken,
            'payment_method' => 'cash',
            'cash_received' => (string) $cashReceived,
        ]);
    }

    private function creditExistingCustomerFields(string $csrfToken, string $idempotencyToken, array $lines, int $customerId): array
    {
        return array_merge($this->lineFields($lines), [
            'csrf_token' => $csrfToken,
            'idempotency_token' => $idempotencyToken,
            'payment_method' => 'credit',
            'customer_mode' => 'existing',
            'customer_id' => (string) $customerId,
            'due_date' => '',
        ]);
    }

    private function creditNewCustomerFields(string $csrfToken, string $idempotencyToken, array $lines, string $name, string $phone): array
    {
        return array_merge($this->lineFields($lines), [
            'csrf_token' => $csrfToken,
            'idempotency_token' => $idempotencyToken,
            'payment_method' => 'credit',
            'customer_mode' => 'new',
            'new_customer_name' => $name,
            'new_customer_phone' => $phone,
            'due_date' => '',
        ]);
    }

    private function lineFields(array $lines): array
    {
        $productIds = [];
        $qtys = [];
        $prices = [];
        foreach ($lines as $line) {
            $productIds[] = (string) $line['product_id'];
            $qtys[] = (string) $line['qty'];
            $prices[] = (string) $line['price'];
        }
        return ['product_id' => $productIds, 'qty' => $qtys, 'unit_price' => $prices];
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

    private function allocationsFor(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT sib.batch_id, sib.qty FROM stock_transaction_item_batches sib
                                       JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                                       WHERE sti.product_id = ? ORDER BY sib.batch_id');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function assertAllocationSum(int $productId, int $expectedTotal): void
    {
        $sum = 0;
        foreach ($this->allocationsFor($productId) as $a) {
            $sum += (int) $a['qty'];
        }
        $this->assertSame($expectedTotal, $sum, 'sum of allocation ledger rows must equal the requested quantity');
    }

    private function batchQtyOnHand(int $batchId): int
    {
        $stmt = $this->pdo->prepare('SELECT qty_on_hand FROM product_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function referenceCounterValue(): int
    {
        return (int) $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'")->fetchColumn();
    }

    // The required K3/K4 invariant: for a tracked product, current_stock
    // must always equal the sum of its own batches' qty_on_hand.
    private function assertInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();
        $this->assertSame($this->currentStock($productId), $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }

    private function seedBatch(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand) VALUES (?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedProduct(int $trackBatches, int $stock): int
    {
        $sku = 'K4-2-POS-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['K4-2 POS Test Product', $sku, 1, 2, $stock, $trackBatches]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function loggedInSession(): string
    {
        $email = 'k4-2.pos.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,2)');
        $stmt->execute(['K4-2 POS Test User', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
