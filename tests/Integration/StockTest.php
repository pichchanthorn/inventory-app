<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use StockConflictException;
use Tests\TestCase;
use TrackedStockAdjustmentNotSupportedException;

// P0 items #1-#7 (Stock Integrity): Stock In, Stock Out, Insufficient
// Stock, Stock Adjustment, Stale Stock Adjustment, Negative Stock,
// Transaction Rollback. Exercises includes/stock.php's real functions
// against the isolated test database - no mocking of the DB layer.
final class StockTest extends TestCase
{
    private function admin(): int
    {
        return testSeedAdmin($this->pdo)['id'];
    }

    // ---- 1. Stock In ----

    public function testStockInIncreasesCurrentStockCorrectly(): void
    {
        $product = testSeedProduct($this->pdo, 50);
        $userId = $this->admin();

        $reference = recordStockIn(
            $this->pdo,
            [['product_id' => $product['id'], 'qty' => 20, 'cost' => 5.00]],
            date('Y-m-d'),
            null,
            'restock',
            $userId
        );

        $this->assertStringStartsWith('STI-', $reference);
        $this->assertSame(70, $this->currentStock($product['id']));
    }

    public function testStockInHandlesMultipleLinesCorrectly(): void
    {
        $p1 = testSeedProduct($this->pdo, 10);
        $p2 = testSeedProduct($this->pdo, 30);
        $userId = $this->admin();

        recordStockIn(
            $this->pdo,
            [
                ['product_id' => $p1['id'], 'qty' => 5, 'cost' => 1.00],
                ['product_id' => $p2['id'], 'qty' => 15, 'cost' => 2.00],
            ],
            date('Y-m-d'),
            null,
            'multi-line restock',
            $userId
        );

        $this->assertSame(15, $this->currentStock($p1['id']));
        $this->assertSame(45, $this->currentStock($p2['id']));
    }

    // ---- 2. Stock Out ----

    public function testStockOutDecreasesCurrentStockCorrectly(): void
    {
        $product = testSeedProduct($this->pdo, 50);
        $userId = $this->admin();

        $reference = recordStockOut(
            $this->pdo,
            [['product_id' => $product['id'], 'qty' => 20, 'price' => 5.00]],
            date('Y-m-d'),
            'damaged goods',
            $userId
        );

        $this->assertStringStartsWith('STO-', $reference);
        $this->assertSame(30, $this->currentStock($product['id']));
    }

    // ---- 3. Insufficient Stock ----

    public function testInsufficientStockThrowsExpectedException(): void
    {
        $product = testSeedProduct($this->pdo, 5);
        $userId = $this->admin();

        try {
            recordStockOut(
                $this->pdo,
                [['product_id' => $product['id'], 'qty' => 10, 'price' => 5.00]],
                date('Y-m-d'),
                'too much',
                $userId
            );
            $this->fail('Expected StockConflictException was not thrown.');
        } catch (StockConflictException $e) {
            $this->assertSame($product['id'], $e->productId);
        }
    }

    public function testInsufficientStockDoesNotPartiallyMutateStock(): void
    {
        $product = testSeedProduct($this->pdo, 5);
        $userId = $this->admin();

        try {
            recordStockOut(
                $this->pdo,
                [['product_id' => $product['id'], 'qty' => 10, 'price' => 5.00]],
                date('Y-m-d'),
                'too much',
                $userId
            );
        } catch (StockConflictException $e) {
            // expected
        }

        $this->assertSame(5, $this->currentStock($product['id']), 'stock must be unchanged after a failed Stock Out');
    }

    public function testInsufficientStockLeavesNoPartialTransactionOrHeader(): void
    {
        $product = testSeedProduct($this->pdo, 5);
        $userId = $this->admin();
        $countBefore = $this->countRows('stock_transactions');
        $itemCountBefore = $this->countRows('stock_transaction_items');

        try {
            recordStockOut(
                $this->pdo,
                [['product_id' => $product['id'], 'qty' => 10, 'price' => 5.00]],
                date('Y-m-d'),
                'too much',
                $userId
            );
        } catch (StockConflictException $e) {
            // expected
        }

        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'no header row must be left behind');
        $this->assertSame($itemCountBefore, $this->countRows('stock_transaction_items'), 'no line item must be left behind');
    }

    // ---- 4. Stock Adjustment ----

    public function testStockAdjustmentSetsExactExpectedQuantity(): void
    {
        $product = testSeedProduct($this->pdo, 40);
        $userId = $this->admin();

        $reference = adjustStock($this->pdo, $product['id'], 33, 40, 'physical count', date('Y-m-d'), $userId);

        $this->assertStringStartsWith('ADJ-', $reference);
        $this->assertSame(33, $this->currentStock($product['id']));
    }

    // ---- 4b. K4-5: Stock Adjustment is rejected for track_batches=1 ----
    //
    // adjustStock() has no concept of batches - see includes/stock.php's
    // TrackedStockAdjustmentNotSupportedException for the full reasoning
    // (an absolute product-level target is inherently ambiguous about
    // which specific batch/lot the difference belongs to). These tests
    // prove the rejection happens before ANY mutation of any kind, on a
    // real transaction the catch-all above (the untracked-happy-path
    // test) proves still works completely unmodified.

    public function testTrackedProductAdjustmentIsRejectedWithoutMutatingAnything(): void
    {
        $product = $this->seedTrackedProduct(15);
        $lot = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 15);
        $userId = $this->admin();
        $countBefore = $this->countRows('stock_transactions');
        $refCounterBefore = $this->referenceCounterValue();

        $this->expectException(TrackedStockAdjustmentNotSupportedException::class);
        try {
            adjustStock($this->pdo, $product['id'], 20, 15, 'physical count', date('Y-m-d'), $userId);
        } finally {
            $this->assertSame(15, $this->currentStock($product['id']), 'current_stock must not move');
            $this->assertSame(15, $this->batchQtyOnHand($lot), 'the batch must not move');
            $this->assertInvariantHolds($product['id']);
            $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'no stock_transactions header may survive');
            $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must be rolled back - no reference burned');

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
            $stmt->execute([$product['id']]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'no stock_transaction_items row may survive');
        }
    }

    public function testTrackedProductAdjustmentWithMultipleBatchesIsRejectedAndEveryBatchIsUntouched(): void
    {
        $product = $this->seedTrackedProduct(15);
        $lotA = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($product['id'], 'LOT-B', '2027-06-01', 10);
        $userId = $this->admin();

        try {
            adjustStock($this->pdo, $product['id'], 8, 15, 'physical count', date('Y-m-d'), $userId);
            $this->fail('Expected TrackedStockAdjustmentNotSupportedException.');
        } catch (TrackedStockAdjustmentNotSupportedException $e) {
            // expected
        }

        $this->assertSame(5, $this->batchQtyOnHand($lotA));
        $this->assertSame(10, $this->batchQtyOnHand($lotB));
        $this->assertSame(15, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testUntrackedProductAdjustmentRemainsUnaffectedByTheTrackedGate(): void
    {
        // Same shape as testStockAdjustmentSetsExactExpectedQuantity()
        // above - restated here explicitly as a K4-5 regression check
        // that the new track_batches lookup does not disturb the
        // untracked path at all.
        $product = testSeedProduct($this->pdo, 40);
        $userId = $this->admin();

        $reference = adjustStock($this->pdo, $product['id'], 33, 40, 'physical count', date('Y-m-d'), $userId);

        $this->assertStringStartsWith('ADJ-', $reference);
        $this->assertSame(33, $this->currentStock($product['id']));
    }

    // ---- 5. Stale Stock Adjustment (optimistic lock) ----

    public function testStaleStockAdjustmentFailsWhenStockChangedSincePreviousRead(): void
    {
        $product = testSeedProduct($this->pdo, 40);
        $userId = $this->admin();

        // Simulate a concurrent change landing between this test's "read"
        // (currentQty=40, captured below) and its adjustment attempt: a
        // separate Stock Out already moved current_stock to 25.
        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 15, 'price' => 1]], date('Y-m-d'), 'concurrent change', $userId);
        $this->assertSame(25, $this->currentStock($product['id']));

        $this->expectException(StockConflictException::class);
        // Still using the stale currentQty=40 read from before the concurrent change.
        adjustStock($this->pdo, $product['id'], 33, 40, 'stale adjustment attempt', date('Y-m-d'), $userId);
    }

    public function testStaleStockAdjustmentDoesNotOverwriteNewerStockValue(): void
    {
        $product = testSeedProduct($this->pdo, 40);
        $userId = $this->admin();
        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 15, 'price' => 1]], date('Y-m-d'), 'concurrent change', $userId);

        try {
            adjustStock($this->pdo, $product['id'], 33, 40, 'stale adjustment attempt', date('Y-m-d'), $userId);
        } catch (StockConflictException $e) {
            // expected
        }

        $this->assertSame(25, $this->currentStock($product['id']), 'the newer, concurrently-written value must survive');
    }

    // ---- 6. Negative Stock ----

    public function testStockCanNeverBecomeNegativeThroughStockOut(): void
    {
        $product = testSeedProduct($this->pdo, 3);
        $userId = $this->admin();

        try {
            recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 1]], date('Y-m-d'), 'over-sell attempt', $userId);
        } catch (StockConflictException $e) {
            // expected
        }

        $this->assertGreaterThanOrEqual(0, $this->currentStock($product['id']));
        $this->assertSame(3, $this->currentStock($product['id']));
    }

    public function testStockCanNeverBecomeNegativeThroughAdjustment(): void
    {
        // The application layer itself never submits a negative new_qty
        // (stock-adjustment/index.php's <input min="0">), but the CHECK
        // constraint on products.current_stock is the last line of
        // defense - confirm it actually rejects an attempt that bypasses
        // the UI and calls adjustStock() directly with a negative value.
        $product = testSeedProduct($this->pdo, 10);
        $userId = $this->admin();

        try {
            adjustStock($this->pdo, $product['id'], -5, 10, 'bypaSs attempt', date('Y-m-d'), $userId);
            $this->fail('Expected a database-level rejection of a negative current_stock.');
        } catch (PDOException $e) {
            $this->assertGreaterThanOrEqual(0, $this->currentStock($product['id']));
        }
    }

    // ---- 7. Transaction Rollback ----

    public function testStockOutRollsBackEarlierLineWhenALaterLineFails(): void
    {
        $p1 = testSeedProduct($this->pdo, 50); // enough stock
        $p2 = testSeedProduct($this->pdo, 2);  // not enough stock
        $userId = $this->admin();
        $countBefore = $this->countRows('stock_transactions');

        try {
            recordStockOut(
                $this->pdo,
                [
                    ['product_id' => $p1['id'], 'qty' => 10, 'price' => 1], // would succeed alone
                    ['product_id' => $p2['id'], 'qty' => 10, 'price' => 1], // fails: insufficient stock
                ],
                date('Y-m-d'),
                'multi-line, second line fails',
                $userId
            );
            $this->fail('Expected StockConflictException was not thrown.');
        } catch (StockConflictException $e) {
            // expected
        }

        $this->assertSame(50, $this->currentStock($p1['id']), 'the earlier, individually-valid line must also be rolled back');
        $this->assertSame(2, $this->currentStock($p2['id']));
        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'the transaction header must also be rolled back');
    }

    public function testStockInRollsBackEarlierLineAndHeaderOnLaterFailure(): void
    {
        $p1 = testSeedProduct($this->pdo, 10);
        $userId = $this->admin();
        $countBefore = $this->countRows('stock_transactions');
        $nonexistentProductId = 999999;

        try {
            recordStockIn(
                $this->pdo,
                [
                    ['product_id' => $p1['id'], 'qty' => 5, 'cost' => 1],       // would succeed alone
                    ['product_id' => $nonexistentProductId, 'qty' => 5, 'cost' => 1], // fails: FK violation
                ],
                date('Y-m-d'),
                null,
                'second line references a nonexistent product',
                $userId
            );
            $this->fail('Expected an exception from the FK violation on the second line.');
        } catch (\Throwable $e) {
            // expected - PDOException from the FK constraint
        }

        $this->assertSame(10, $this->currentStock($p1['id']), 'the earlier, individually-valid line must also be rolled back');
        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'the transaction header must also be rolled back');
    }

    // ---- K2a: Batch Core + Stock In rollback ----

    public function testStockInRollsBackBatchAndAllocationMutationsAndIdempotencyClaimOnLaterFailure(): void
    {
        $p1 = testSeedProduct($this->pdo, 10, ['track_batches' => 1]);
        $userId = $this->admin();
        $token = testRandomToken();
        $countBefore = $this->countRows('stock_transactions');
        $refCounterBefore = $this->referenceCounterValue();
        $nonexistentProductId = 999999;

        try {
            recordStockIn(
                $this->pdo,
                [
                    ['product_id' => $p1['id'], 'qty' => 5, 'cost' => 1, 'batch_number' => 'ROLLBACK-LOT', 'expiry_date' => null], // would succeed alone: item, stock, batch, allocation
                    ['product_id' => $nonexistentProductId, 'qty' => 5, 'cost' => 1], // fails: product not found
                ],
                date('Y-m-d'),
                null,
                'second line references a nonexistent product',
                $userId,
                $token
            );
            $this->fail('Expected an exception from the invalid second line.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(10, $this->currentStock($p1['id']), 'the earlier, individually-valid line\'s stock increment must be rolled back');
        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'the transaction header must also be rolled back');
        $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must be rolled back too');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
        $stmt->execute([$p1['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no line-1 stock_transaction_items row must persist');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$p1['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no line-1 product_batches row must persist');

        // No batch was ever committed for this product, so there is no
        // batch_id to scope an allocation-ledger check to - the absence of
        // any product_batches row above already proves no allocation could
        // exist either (stock_transaction_item_batches.batch_id has a NOT
        // NULL FK to product_batches).

        // The idempotency claim must have rolled back with everything
        // else - the same token must still be usable for a fresh, valid
        // submission.
        $reference = recordStockIn(
            $this->pdo,
            [['product_id' => $p1['id'], 'qty' => 5, 'cost' => 1, 'batch_number' => 'ROLLBACK-LOT', 'expiry_date' => null]],
            date('Y-m-d'),
            null,
            'retry after rollback',
            $userId,
            $token
        );
        $this->assertStringStartsWith('STI-', $reference);
        $this->assertSame(15, $this->currentStock($p1['id']));

        $stmt = $this->pdo->prepare('SELECT qty_on_hand, qty_received FROM product_batches WHERE product_id = ?');
        $stmt->execute([$p1['id']]);
        $batch = $stmt->fetch();
        $this->assertSame(5, (int) $batch['qty_on_hand'], 'the retried submission must create exactly one correctly-quantified batch');
        $this->assertSame(5, (int) $batch['qty_received']);
    }

    // ---- K3-1: Stock Out + FEFO batch consumption ----
    //
    // All tests below call recordStockOut(..., consumeBatches: true) -
    // Stock Out's own call site (stock-out/index.php) is NOT wired up to
    // pass this yet (that's a later phase); these tests exercise the
    // backend contract directly, exactly as stock-out/index.php will
    // once it does.

    public function testStockOutConsumesFromASingleBatchWhenOneSuffices(): void
    {
        $product = $this->seedTrackedProduct(20);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 20);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 8, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(12, $this->currentStock($product['id']));
        $this->assertSame(12, $this->batchQtyOnHand($batchId));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutPartiallyDepletesABatchWhenNotAllIsNeeded(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 3, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(7, $this->batchQtyOnHand($batchId));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutExactlyDepletesABatchToZero(): void
    {
        $product = $this->seedTrackedProduct(5);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 5);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($batchId));
        $this->assertSame(0, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutConsumesFromEarliestExpiryFirstAcrossMultipleBatches(): void
    {
        // The task's own worked example: LOT-A (5, expiring sooner) must
        // be fully consumed before LOT-B (10, expiring later) is touched
        // at all, for a request that spans both.
        $product = $this->seedTrackedProduct(15);
        $lotA = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($product['id'], 'LOT-B', '2027-06-01', 10);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 8, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($lotA), 'the earlier-expiring batch must be fully consumed first');
        $this->assertSame(7, $this->batchQtyOnHand($lotB), 'only the remainder should be drawn from the later batch');
        $this->assertSame(7, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);

        $stmt = $this->pdo->prepare('SELECT sib.batch_id, sib.qty FROM stock_transaction_item_batches sib
                                       JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                                       WHERE sti.product_id = ? ORDER BY sib.batch_id');
        $stmt->execute([$product['id']]);
        $allocations = $stmt->fetchAll();
        $this->assertCount(2, $allocations, 'one allocation ledger row per batch drawn from, not per line');
        $this->assertSame(['batch_id' => $lotA, 'qty' => 5], ['batch_id' => (int) $allocations[0]['batch_id'], 'qty' => (int) $allocations[0]['qty']]);
        $this->assertSame(['batch_id' => $lotB, 'qty' => 3], ['batch_id' => (int) $allocations[1]['batch_id'], 'qty' => (int) $allocations[1]['qty']]);
    }

    public function testStockOutConsumesAllAvailableStockAcrossAllBatches(): void
    {
        $product = $this->seedTrackedProduct(15);
        $lotA = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($product['id'], 'LOT-B', '2027-06-01', 10);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 15, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($lotA));
        $this->assertSame(0, $this->batchQtyOnHand($lotB));
        $this->assertSame(0, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutWithInsufficientTotalBatchStockIsRejectedWithNoPartialMutation(): void
    {
        $product = $this->seedTrackedProduct(8);
        $lotA = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($product['id'], 'LOT-B', '2027-06-01', 3);
        $userId = $this->admin();

        try {
            recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 20, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);
            $this->fail('Expected StockConflictException was not thrown.');
        } catch (StockConflictException $e) {
            $this->assertSame($product['id'], $e->productId);
        }

        $this->assertSame(5, $this->batchQtyOnHand($lotA), 'no partial allocation from batch A');
        $this->assertSame(3, $this->batchQtyOnHand($lotB), 'no partial allocation from batch B');
        $this->assertSame(8, $this->currentStock($product['id']), 'current_stock must be unchanged');
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutTreatsNullExpiryBatchesAsLastInFefoOrder(): void
    {
        $product = $this->seedTrackedProduct(10);
        $dated = $this->seedBatch($product['id'], 'LOT-DATED', '2027-01-01', 4);
        $undated = $this->seedBatch($product['id'], 'LOT-UNDATED', null, 6);
        $userId = $this->admin();

        // Requesting more than the dated batch alone can cover forces
        // FEFO to spill into the undated batch - proving undated is
        // ordered AFTER dated, not before (the naive ORDER BY expiry_date
        // ASC pitfall this design explicitly avoids).
        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 6, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($dated), 'the dated batch must be fully consumed before the undated one is touched');
        $this->assertSame(4, $this->batchQtyOnHand($undated));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutTiesBySameExpiryConsumeLowerBatchIdFirst(): void
    {
        $product = $this->seedTrackedProduct(10);
        $first = $this->seedBatch($product['id'], 'LOT-FIRST', '2027-01-01', 5);
        $second = $this->seedBatch($product['id'], 'LOT-SECOND', '2027-01-01', 5);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($first), 'the lower-id batch (received first) must be consumed first on a tie');
        $this->assertSame(5, $this->batchQtyOnHand($second));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutConsumingAnAnonymousBatchOnlyAfterDatedBatchesAreExhausted(): void
    {
        $product = $this->seedTrackedProduct(10);
        $dated = $this->seedBatch($product['id'], 'LOT-DATED', '2027-01-01', 3);
        $anonymous = $this->seedBatch($product['id'], null, null, 7);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($dated));
        $this->assertSame(5, $this->batchQtyOnHand($anonymous), 'the anonymous NULL/NULL batch is consumed only after the dated batch is exhausted');
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutHandlesBatchOnlyAndExpiryOnlyIdentitiesPurelyByExpiry(): void
    {
        // batch_number never affects ordering - only expiry_date does.
        // batchOnly (expiry NULL) must be ordered AFTER expiryOnly (a
        // real expiry date), regardless of having a "real" batch number.
        $product = $this->seedTrackedProduct(10);
        $expiryOnly = $this->seedBatch($product['id'], null, '2027-01-01', 4);
        $batchOnly = $this->seedBatch($product['id'], 'LOT-NOEXP', null, 6);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 6, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(0, $this->batchQtyOnHand($expiryOnly), 'the dated (expiry-only) batch must be consumed first');
        $this->assertSame(4, $this->batchQtyOnHand($batchOnly));
        $this->assertInvariantHolds($product['id']);
    }

    public function testStockOutWithExplicitConsumeBatchesOnAnUntrackedProductIsANoOp(): void
    {
        // consumeBatches=true is inert when track_batches=0 - the new
        // per-line check reads track_batches from the DB and takes the
        // existing, unchanged guarded-decrement path regardless of the
        // flag's value.
        $product = testSeedProduct($this->pdo, 10);
        $userId = $this->admin();

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 4, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, null, true);

        $this->assertSame(6, $this->currentStock($product['id']));
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no product_batches row must ever be created for an untracked product');
    }

    public function testStockOutRollsBackMultiBatchAllocationOnALaterLineFailure(): void
    {
        $product = $this->seedTrackedProduct(15);
        $lotA = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 5);
        $lotB = $this->seedBatch($product['id'], 'LOT-B', '2027-06-01', 10);
        $userId = $this->admin();
        $token = testRandomToken();
        $countBefore = $this->countRows('stock_transactions');
        $refCounterBefore = $this->referenceCounterValue();
        $nonexistentProductId = 999999;

        try {
            recordStockOut(
                $this->pdo,
                [
                    // Would succeed alone: spans both batches (5 + 3),
                    // exercising rollback of a MULTI-batch allocation,
                    // not just a single one.
                    ['product_id' => $product['id'], 'qty' => 8, 'price' => 1],
                    ['product_id' => $nonexistentProductId, 'qty' => 1, 'price' => 1],
                ],
                date('Y-m-d'), 'second line references a nonexistent product', $userId, 'out', null, $token, true
            );
            $this->fail('Expected an exception from the invalid second line.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(5, $this->batchQtyOnHand($lotA), 'batch A must be restored to its pre-transaction quantity');
        $this->assertSame(10, $this->batchQtyOnHand($lotB), 'batch B must be restored to its pre-transaction quantity');
        $this->assertSame(15, $this->currentStock($product['id']), 'current_stock must be restored');
        $this->assertInvariantHolds($product['id']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_item_batches sib
                                       JOIN stock_transaction_items sti ON sti.id = sib.transaction_item_id
                                       WHERE sti.product_id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no allocation ledger row must survive');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no stock_transaction_items row must survive');

        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'no stock_transactions header must survive');
        $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must be rolled back');

        // The idempotency claim must have rolled back too.
        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), 'retry', $userId, 'out', null, $token, true);
        $this->assertStringStartsWith('STO-', $reference);
        $this->assertSame(10, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    private function seedTrackedProduct(int $stock): array
    {
        return testSeedProduct($this->pdo, $stock, ['track_batches' => 1]);
    }

    private function seedBatch(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand) VALUES (?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty]);
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
    private function assertInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();
        $this->assertSame($this->currentStock($productId), $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }

    private function referenceCounterValue(): int
    {
        return (int) $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'")->fetchColumn();
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
