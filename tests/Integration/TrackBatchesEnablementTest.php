<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

// Phase K4-5 (Stock Adjustment + Batch Invariant safety). Exercises
// includes/stock.php's enableTrackBatches() directly - the function
// product/index.php calls, as its own separate transaction, whenever an
// edit's submitted track_batches checkbox is checked (see that file).
// This closes the second invariant hole K4-4's browser QA found:
// enabling Track Batches on a product that already has current_stock > 0
// must never leave that stock without a matching product_batches row.
//
// See the K4-5 design audit for why this is a one-time opening-balance
// placeholder (origin='opening_balance', batch_number/expiry_date both
// NULL, qty_received=0) rather than a real Stock In event, and why an
// existing product_batches row (however it got there) always wins over
// fabricating a second opening batch.
final class TrackBatchesEnablementTest extends TestCase
{
    private function admin(): int
    {
        return testSeedAdmin($this->pdo)['id'];
    }

    // ---- 4. Enable with existing stock ----

    public function testEnablingTrackBatchesWithExistingStockCreatesExactlyOneOpeningBalanceBatch(): void
    {
        $product = testSeedProduct($this->pdo, 40); // track_batches=0 by default
        $userId = $this->admin();

        enableTrackBatches($this->pdo, $product['id'], $userId);

        $this->assertSame(1, $this->trackBatches($product['id']));
        $this->assertSame(40, $this->currentStock($product['id']), 'current_stock must be unchanged - not incremented or decremented');

        $batches = $this->batchesFor($product['id']);
        $this->assertCount(1, $batches, 'exactly one opening-balance batch must exist');
        $batch = $batches[0];
        $this->assertNull($batch['batch_number']);
        $this->assertNull($batch['expiry_date']);
        $this->assertSame(40, (int) $batch['qty_on_hand']);
        $this->assertSame(0, (int) $batch['qty_received'], 'this stock was never received through Stock In - qty_received stays 0');
        $this->assertSame('opening_balance', $batch['origin']);
        $this->assertNull($batch['source_transaction_id'], 'there is no real Stock In event to point at');
        $this->assertSame($userId, (int) $batch['created_by']);
        $this->assertSame($userId, (int) $batch['updated_by']);

        $this->assertInvariantHolds($product['id']);
        // No stock movement was recorded - this is a bookkeeping
        // conversion, not a Stock In/Out/Adjustment event.
        $this->assertSame(0, $this->countStockTransactionsForProduct($product['id']));
    }

    // ---- 5. Enable with zero stock ----

    public function testEnablingTrackBatchesWithZeroStockCreatesNoBatch(): void
    {
        $product = testSeedProduct($this->pdo, 0);
        $userId = $this->admin();

        enableTrackBatches($this->pdo, $product['id'], $userId);

        $this->assertSame(1, $this->trackBatches($product['id']));
        $this->assertSame(0, $this->currentStock($product['id']));
        $this->assertCount(0, $this->batchesFor($product['id']), 'no anonymous placeholder batch for zero stock');
        $this->assertInvariantHolds($product['id']);
    }

    // ---- 6. Repeated enable / idempotent state transition ----

    public function testCallingEnableTrackBatchesAgainOnAnAlreadyTrackedProductIsANoOp(): void
    {
        $product = testSeedProduct($this->pdo, 40);
        $userId = $this->admin();

        enableTrackBatches($this->pdo, $product['id'], $userId);
        $firstBatches = $this->batchesFor($product['id']);
        $this->assertCount(1, $firstBatches);
        $firstBatchId = $firstBatches[0]['id'];

        // Repeat the exact same call - simulates a duplicate submission of
        // the same 0->1 transition (e.g. the edit form re-submitted).
        enableTrackBatches($this->pdo, $product['id'], $userId);

        $this->assertSame(1, $this->trackBatches($product['id']));
        $this->assertSame(40, $this->currentStock($product['id']));
        $secondBatches = $this->batchesFor($product['id']);
        $this->assertCount(1, $secondBatches, 'no second opening batch may be created');
        $this->assertSame($firstBatchId, $secondBatches[0]['id'], 'the original batch row must be untouched, not replaced');
        $this->assertInvariantHolds($product['id']);
    }

    // ---- 7. Existing batch anomaly case ----

    public function testEnablingTrackBatchesWhenBatchesAlreadyExistDoesNotFabricateASecondOpeningBatch(): void
    {
        // Anomalous but technically reachable state: track_batches=0
        // (untracked) yet product_batches rows already exist for this
        // product - e.g. tracking was previously enabled (creating real
        // or opening-balance batches) and later disabled, leaving those
        // rows behind (K4-5 deliberately does not touch batches on
        // disable - see the design audit's B5 analysis). This is NOT
        // silently reconciled: enableTrackBatches() must never guess at
        // whether current_stock and the pre-existing batch sum still
        // agree, so it does nothing beyond flipping the flag.
        $product = testSeedProduct($this->pdo, 40);
        $userId = $this->admin();
        $this->seedBatch($product['id'], 'LOT-PREEXISTING', '2027-01-01', 25);

        enableTrackBatches($this->pdo, $product['id'], $userId);

        $this->assertSame(1, $this->trackBatches($product['id']));
        $this->assertSame(40, $this->currentStock($product['id']), 'current_stock is never touched by this function');
        $batches = $this->batchesFor($product['id']);
        $this->assertCount(1, $batches, 'no opening-balance batch may be fabricated on top of a pre-existing one');
        $this->assertSame('LOT-PREEXISTING', $batches[0]['batch_number']);
        $this->assertSame(25, (int) $batches[0]['qty_on_hand'], 'the pre-existing batch itself must be untouched');
        // Documented, deliberate consequence of this anomalous starting
        // state: the invariant is NOT reconciled by this function (40 vs
        // 25) - see the K4-5 design audit and final report for why this
        // is left alone rather than guessed at.
    }

    // ---- 8. Rollback ----

    public function testEnableTrackBatchesRollsBackCompletelyWhenTheBatchInsertFailsAfterTheFlagWouldHaveChanged(): void
    {
        // A real, unmocked way to force a failure strictly AFTER the
        // UPDATE products SET track_batches = 1 statement has already run
        // inside enableTrackBatches()'s own transaction, but before it
        // commits: created_by/updated_by on product_batches has a REAL
        // FOREIGN KEY to users(id) (see database/schema.sql) - passing a
        // user id that does not exist makes the batch INSERT itself throw
        // a genuine PDOException, proving the whole transaction
        // (including the already-run flag flip) rolls back together.
        $product = testSeedProduct($this->pdo, 40);
        $nonexistentUserId = 999999;

        try {
            enableTrackBatches($this->pdo, $product['id'], $nonexistentUserId);
            $this->fail('Expected a foreign-key violation from the nonexistent user id.');
        } catch (\PDOException $e) {
            // expected
        }

        $this->assertSame(0, $this->trackBatches($product['id']), 'track_batches must remain unchanged - it must not be left partially flipped');
        $this->assertSame(40, $this->currentStock($product['id']));
        $this->assertCount(0, $this->batchesFor($product['id']), 'no opening batch may survive');
        $this->assertFalse($this->pdo->inTransaction(), 'the transaction must be fully closed, not left open');
    }

    public function testEnableTrackBatchesForANonexistentProductThrowsWithoutSideEffects(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            enableTrackBatches($this->pdo, 999999, $this->admin());
        } finally {
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    private function trackBatches(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT track_batches FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function batchesFor(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ? ORDER BY id');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function seedBatch(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand) VALUES (?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty]);
        return (int) $this->pdo->lastInsertId();
    }

    private function countStockTransactionsForProduct(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    // The required K1-K4 invariant: for a tracked product, current_stock
    // must always equal the sum of its own batches' qty_on_hand.
    private function assertInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();
        $this->assertSame($this->currentStock($productId), $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }
}
