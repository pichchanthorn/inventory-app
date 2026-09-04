<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use StockConflictException;
use Tests\TestCase;

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
