<?php
declare(strict_types=1);

namespace Tests\Http;

// Phase K4-6-2 (Batch-Specific Stock Adjustment UI + HTTP Integration).
// Drives the real stock-adjustment/index.php page over real HTTP - see
// HttpServerTestCase for why this must be a real HTTP request rather than
// an in-process include. Exercises the new tracked-product branch this
// phase adds (batch_id/batch_new_qty/batch_expected_qty, routed to
// includes/stock.php's batchAdjustStock()) and re-confirms the untracked
// legacy branch (adjustStock()) is unchanged. Generic CSRF/RBAC coverage
// otherwise lives in CsrfTest.php/AuthorizationTest.php against
// stock-out/index.php as the representative page - this file additionally
// proves the same gate on THIS page, since neither of those generic files
// ever exercised stock-adjustment/index.php at all (confirmed absent from
// both during the K4-6-2 audit).
final class StockAdjustmentBatchTest extends HttpServerTestCase
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
            $this->pdo->exec("DELETE al FROM audit_log al
                               JOIN product_batches pb ON pb.id = al.entity_id AND al.entity_type = 'product_batch'
                               WHERE pb.product_id = $id");
            $this->pdo->exec("DELETE FROM product_batches WHERE product_id = $id");
            $this->pdo->exec("DELETE FROM products WHERE id = $id");
        }
        foreach ($this->cleanupUserIds as $id) {
            $this->pdo->exec("DELETE FROM users WHERE id = $id");
        }
        parent::tearDown();
    }

    // ---- 1. Untracked: legacy flow unchanged ----

    public function testUntrackedProductLegacyAdjustmentStillSucceeds(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedProduct(0, 20);
        $countBefore = $this->countAdjustmentTransactions();

        $form = $this->httpGet($jar, '/stock-adjustment/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $res = $this->httpPost($jar, '/stock-adjustment/index.php', [
            'csrf_token' => $token,
            'transaction_date' => date('Y-m-d'),
            'reason' => 'K4-6-2 legacy path test',
            'product_id' => (string) $productId,
            'new_qty' => '25',
        ]);

        $this->assertSame(302, $res['status'], 'legacy adjustment must still succeed: ' . $res['body']);
        $this->assertSame(25, $this->currentStock($productId));
        $this->assertSame($countBefore + 1, $this->countAdjustmentTransactions());
    }

    // ---- 2-4. Tracked: core Target-semantics flows ----

    public function testTrackedValidBatchTargetIncreaseSucceeds(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '15', '10');

        $this->assertSame(302, $res['status'], 'increase must succeed: ' . $res['body']);
        $this->assertSame(15, $this->batchQty($batchId));
        $this->assertSame(15, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);
    }

    public function testTrackedValidBatchTargetDecreaseSucceeds(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '4', '10');

        $this->assertSame(302, $res['status'], 'decrease must succeed: ' . $res['body']);
        $this->assertSame(4, $this->batchQty($batchId));
        $this->assertSame(4, $this->currentStock($productId));
        $this->assertInvariantHolds($productId);
    }

    public function testTargetZeroSucceedsAndBatchRowRemains(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '0', '10');

        $this->assertSame(302, $res['status'], 'target 0 must succeed: ' . $res['body']);
        $this->assertSame(0, $this->batchQty($batchId));
        $this->assertSame(0, $this->currentStock($productId));
        $this->assertCount(1, $this->batchesFor($productId), 'the batch row must not be deleted');
    }

    public function testSameQuantitySucceedsAsRecordedNoOp(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);
        $countBefore = $this->countAdjustmentTransactions();

        $res = $this->submitTracked($jar, $productId, $batchId, '10', '10');

        $this->assertSame(302, $res['status'], 'no-op must still succeed: ' . $res['body']);
        $this->assertSame(10, $this->batchQty($batchId));
        $this->assertSame(10, $this->currentStock($productId));
        $this->assertSame($countBefore + 1, $this->countAdjustmentTransactions(), 'a no-op is still a recorded adjustment');
    }

    // ---- 6-7. Expired / opening-balance batches ----

    public function testExpiredBatchSucceeds(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-EXPIRED', '2020-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '6', '10');

        $this->assertSame(302, $res['status'], 'an expired batch must remain adjustable: ' . $res['body']);
        $this->assertSame(6, $this->batchQty($batchId));
    }

    public function testOpeningBalanceBatchSucceedsAndPreservesOriginAndSourceTransactionId(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(40);
        $batchId = $this->seedOpeningBalanceBatch($productId, 40);

        $res = $this->submitTracked($jar, $productId, $batchId, '25', '40');

        $this->assertSame(302, $res['status'], 'an opening-balance batch must remain adjustable: ' . $res['body']);
        $batch = $this->batch($batchId);
        $this->assertSame(25, (int) $batch['qty_on_hand']);
        $this->assertSame('opening_balance', $batch['origin']);
        $this->assertNull($batch['source_transaction_id']);
    }

    // ---- 8-9. Batch id rejection ----

    public function testMissingOrNonexistentBatchIdIsRejected(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);
        $countBefore = $this->countAdjustmentTransactions();

        $res = $this->submitTracked($jar, $productId, 999999, '5', '0');

        $this->assertSame(200, $res['status'], 'a nonexistent batch id re-renders with an error, not a redirect');
        $this->assertStringContainsString('showToast', $res['body']);
        $this->assertSame(10, $this->currentStock($productId), 'product stock must be unchanged');
        $this->assertSame($countBefore, $this->countAdjustmentTransactions(), 'no adjustment transaction may be created');
    }

    public function testBatchBelongingToAnotherProductIsRejected(): void
    {
        $jar = $this->loggedInSession();
        $productA = $this->seedTrackedProduct(10);
        $productB = $this->seedTrackedProduct(10);
        $batchOfB = $this->seedBatch($productB, 'LOT-B', null, 10);

        $res = $this->submitTracked($jar, $productA, $batchOfB, '5', '10');

        $this->assertSame(200, $res['status'], 'cross-product batch id must be rejected, not redirected');
        $this->assertSame(10, $this->batchQty($batchOfB), 'batch B must be completely untouched');
        $this->assertSame(10, $this->currentStock($productA));
        $this->assertSame(10, $this->currentStock($productB));
    }

    // ---- 10-11. Quantity validation ----

    public function testNegativeTargetIsRejected(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '-1', '10');

        $this->assertSame(200, $res['status']);
        $this->assertSame(10, $this->batchQty($batchId));
        $this->assertSame(10, $this->currentStock($productId));
    }

    public function testNonIntegerTargetIsRejectedNotTruncated(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '5.7', '10');

        $this->assertSame(200, $res['status'], 'a non-integer target must be rejected, not silently truncated');
        $this->assertSame(10, $this->batchQty($batchId), 'must NOT have been truncated to 5 - the batch must be completely untouched');
        $this->assertSame(10, $this->currentStock($productId));
    }

    public function testNonIntegerExpectedQtyIsRejected(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $res = $this->submitTracked($jar, $productId, $batchId, '6', '10.5');

        $this->assertSame(200, $res['status'], 'a malformed expected_qty must be rejected server-side, not trusted');
        $this->assertSame(10, $this->batchQty($batchId));
    }

    // ---- 12. Stale expected_qty ----

    public function testStaleExpectedQtyIsRejected(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        // Simulate the batch changing after the page was rendered (e.g.
        // another transaction) - the form still carries the ORIGINAL
        // pre-read quantity (10) as expected_qty, exactly as K4-6-2's own
        // design requires (never freshly re-read at submit time).
        $this->pdo->prepare('UPDATE product_batches SET qty_on_hand = ? WHERE id = ?')->execute([7, $batchId]);
        $this->pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([7, $productId]);

        $res = $this->submitTracked($jar, $productId, $batchId, '6', '10');

        $this->assertSame(200, $res['status'], 'a stale expected_qty must be rejected, not silently overwritten');
        $this->assertSame(7, $this->batchQty($batchId), 'the concurrent change must survive untouched');
        $this->assertSame(7, $this->currentStock($productId));
    }

    // ---- 13. No batch selected ----

    public function testTrackedProductWithNoBatchSelectedIsRejected(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);
        $countBefore = $this->countAdjustmentTransactions();

        $form = $this->httpGet($jar, '/stock-adjustment/index.php');
        $token = $this->extractCsrfToken($form['body']);
        $res = $this->httpPost($jar, '/stock-adjustment/index.php', [
            'csrf_token' => $token,
            'transaction_date' => date('Y-m-d'),
            'reason' => 'K4-6-2 no batch selected test',
            'product_id' => (string) $productId,
            'batch_id' => '', // never silently 0
            'batch_new_qty' => '5',
            'batch_expected_qty' => '10',
        ]);

        $this->assertSame(200, $res['status'], 'an empty batch_id must never be silently treated as batch id 0');
        $this->assertSame(10, $this->currentStock($productId));
        $this->assertSame($countBefore, $this->countAdjustmentTransactions());
    }

    // ---- 14-15. RBAC / CSRF ----

    public function testViewerDirectPostIsRejectedWithNoMutation(): void
    {
        $viewerJar = $this->loggedInSession(3);
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        // A Viewer must still be able to load the page (read-only access),
        // same convention CsrfTest.php/AuthorizationTest.php already
        // establish for stock-out/index.php.
        $form = $this->httpGet($viewerJar, '/stock-adjustment/index.php');
        $this->assertSame(200, $form['status']);
        $token = $this->extractCsrfToken($form['body']);

        $res = $this->submitTracked($viewerJar, $productId, $batchId, '5', '10', $token);

        $this->assertSame(10, $this->batchQty($batchId), 'a Viewer write attempt must not mutate the batch');
        $this->assertSame(10, $this->currentStock($productId));
    }

    public function testMissingCsrfTokenIsRejectedWith403AndNoMutation(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);

        $this->httpGet($jar, '/stock-adjustment/index.php'); // establish a session
        $res = $this->httpPost($jar, '/stock-adjustment/index.php', [
            // csrf_token deliberately omitted
            'transaction_date' => date('Y-m-d'),
            'reason' => 'K4-6-2 CSRF test',
            'product_id' => (string) $productId,
            'batch_id' => (string) $batchId,
            'batch_new_qty' => '5',
            'batch_expected_qty' => '10',
        ]);

        $this->assertSame(403, $res['status'], 'a missing CSRF token must be rejected');
        $this->assertSame(10, $this->batchQty($batchId));
        $this->assertSame(10, $this->currentStock($productId));
    }

    // ---- 16. Rejected requests: no audit row, no reference burn ----

    public function testRejectedTrackedRequestsCreateNoAuditRowAndBurnNoReference(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($productId, 'LOT-A', '2027-01-01', 10);
        $auditCountBefore = $this->auditRowCount($batchId);
        $referenceValueBefore = $this->referenceCounterValue();

        $res = $this->submitTracked($jar, $productId, $batchId, '-1', '10');

        $this->assertSame(200, $res['status']);
        $this->assertSame($auditCountBefore, $this->auditRowCount($batchId), 'a rejected request must create no audit_log row');
        $this->assertSame($referenceValueBefore, $this->referenceCounterValue(), 'a rejected request must not burn an ADJ reference number');
    }

    // ---- Invariant across a successful tracked adjustment ----

    public function testSuccessfulTrackedAdjustmentPreservesTheInvariantAcrossMultipleBatches(): void
    {
        $jar = $this->loggedInSession();
        $productId = $this->seedTrackedProduct(18);
        $batchA = $this->seedBatch($productId, 'LOT-A', null, 10);
        $batchB = $this->seedBatch($productId, 'LOT-B', null, 8);

        $res = $this->submitTracked($jar, $productId, $batchA, '15', '10');

        $this->assertSame(302, $res['status'], $res['body']);
        $this->assertSame(15, $this->batchQty($batchA));
        $this->assertSame(8, $this->batchQty($batchB), 'the other batch must be untouched');
        $this->assertInvariantHolds($productId);
    }

    // ---- helpers ----

    private function submitTracked(string $jar, int $productId, int $batchId, string $newQty, string $expectedQty, ?string $csrfToken = null): array
    {
        if ($csrfToken === null) {
            $form = $this->httpGet($jar, '/stock-adjustment/index.php');
            $csrfToken = $this->extractCsrfToken($form['body']);
        }
        return $this->httpPost($jar, '/stock-adjustment/index.php', [
            'csrf_token' => $csrfToken,
            'transaction_date' => date('Y-m-d'),
            'reason' => 'K4-6-2 HTTP test',
            'product_id' => (string) $productId,
            'batch_id' => (string) $batchId,
            'batch_new_qty' => $newQty,
            'batch_expected_qty' => $expectedQty,
        ]);
    }

    private function seedProduct(int $trackBatches, int $stock): int
    {
        $sku = 'K462-' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare('INSERT INTO products (name, sku, cost_price, sale_price, current_stock, track_batches) VALUES (?,?,?,?,?,?)');
        $stmt->execute(['K4-6-2 Test Product', $sku, 1, 2, $stock, $trackBatches]);
        $id = (int) $this->pdo->lastInsertId();
        $this->cleanupProductIds[] = $id;
        return $id;
    }

    private function seedTrackedProduct(int $stock): int
    {
        return $this->seedProduct(1, $stock);
    }

    private function seedBatch(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty, 'stock_in']);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedOpeningBalanceBatch(int $productId, int $qty): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin) VALUES (?, NULL, NULL, 0, ?, 'opening_balance')");
        $stmt->execute([$productId, $qty]);
        return (int) $this->pdo->lastInsertId();
    }

    private function batch(int $batchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, "batch $batchId not found");
        return $row;
    }

    private function batchesFor(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function batchQty(int $batchId): int
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

    private function countAdjustmentTransactions(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE type = 'adjustment'")->fetchColumn();
    }

    private function auditRowCount(int $batchId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'product_batch' AND entity_id = ?");
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    private function referenceCounterValue(): int
    {
        $stmt = $this->pdo->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function assertInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();
        $this->assertSame($this->currentStock($productId), $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }

    private function loggedInSession(int $roleId = 2): string
    {
        $email = 'k462.' . bin2hex(random_bytes(4)) . '@test.local';
        $password = 'TestPass123!';
        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?,?,?,?)');
        $stmt->execute(['K4-6-2 Test User', $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        $this->cleanupUserIds[] = (int) $this->pdo->lastInsertId();

        $jar = $this->newCookieJar();
        $this->login($jar, $email, $password);
        return $jar;
    }
}
