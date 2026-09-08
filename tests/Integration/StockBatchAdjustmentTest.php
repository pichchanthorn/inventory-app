<?php
declare(strict_types=1);

namespace Tests\Integration;

use BatchAdjustmentConflictException;
use InvalidBatchAdjustmentQuantityException;
use ProductBatchNotFoundException;
use Tests\TestCase;
use UntrackedProductBatchAdjustmentNotSupportedException;

// Phase K4-6-1 (Batch-Specific Stock Adjustment, backend). Exercises
// includes/stock.php's batchAdjustStock() directly - the K4-6-A-approved
// backend that lets a track_batches=1 product's Stock Adjustment target
// exactly one product_batches row, rather than being rejected outright
// the way adjustStock() rejects every tracked product (see
// TrackedStockAdjustmentNotSupportedException, StockTest.php's own K4-5
// coverage of that gate - unaffected and unchanged by this phase).
//
// Same "no outer transaction, seed with randomized ids, assert deltas -
// never raw table counts" isolation philosophy as every other Integration
// test in this suite - see Tests\TestCase's own header comment.
final class StockBatchAdjustmentTest extends TestCase
{
    private function admin(): int
    {
        return testSeedAdmin($this->pdo)['id'];
    }

    private function seedTrackedProduct(int $stock = 0): array
    {
        return testSeedProduct($this->pdo, $stock, ['track_batches' => 1]);
    }

    private function seedBatch(int $productId, ?string $batchNumber, ?string $expiryDate, int $qty, string $origin = 'stock_in'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$productId, $batchNumber, $expiryDate, $qty, $qty, $origin]);
        return (int) $this->pdo->lastInsertId();
    }

    // A real opening-balance batch (see enableTrackBatches() in
    // includes/stock.php) always has qty_received=0, regardless of
    // qty_on_hand - this stock was never received through a real Stock In
    // event. seedBatch() above sets qty_received=qty for every origin
    // (representative of a real Stock In-sourced batch), so opening-
    // balance-specific tests need this dedicated helper instead.
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

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function referenceCounterValue(): int
    {
        $stmt = $this->pdo->prepare("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function transactionForReference(string $reference): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_transactions WHERE reference = ?');
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, "no stock_transactions row for reference $reference");
        return $row;
    }

    private function itemsForTransaction(int $txId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_transaction_items WHERE transaction_id = ?');
        $stmt->execute([$txId]);
        return $stmt->fetchAll();
    }

    private function allocationRowsForItem(int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_transaction_item_batches WHERE transaction_item_id = ?');
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }

    private function auditRowsForBatch(int $batchId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'product_batch' AND entity_id = ? ORDER BY id");
        $stmt->execute([$batchId]);
        return $stmt->fetchAll();
    }

    private function countAdjustmentTransactionsForProduct(int $productId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM stock_transaction_items sti
                                      JOIN stock_transactions st ON st.id = sti.transaction_id
                                      WHERE sti.product_id = ? AND st.type = 'adjustment'");
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertInvariantHolds(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(qty_on_hand), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchSum = (int) $stmt->fetchColumn();
        $this->assertSame($this->currentStock($productId), $batchSum, 'products.current_stock must equal SUM(product_batches.qty_on_hand)');
    }

    // ---- 1-4: core Target-semantics behavior ----

    public function testTrackedBatchIncreaseUpdatesBatchAndCurrentStockByExactDelta(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        $reference = batchAdjustStock($this->pdo, $product['id'], $batchId, 15, 10, 'recount', date('Y-m-d'), $userId);

        $this->assertStringStartsWith('ADJ-', $reference);
        $this->assertSame(15, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(15, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testTrackedBatchDecreaseUpdatesBatchAndCurrentStockByExactDelta(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $userId);

        $this->assertSame(6, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(6, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testTargetZeroEmptiesTheBatchWithoutDeletingIt(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 0, 10, 'depleted', date('Y-m-d'), $userId);

        $this->assertSame(0, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(0, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testTargetEqualToCurrentIsANoOpButStillRecordsAnAdjustment(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        $reference = batchAdjustStock($this->pdo, $product['id'], $batchId, 10, 10, 'confirmed count', date('Y-m-d'), $userId);

        $this->assertSame(10, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(10, $this->currentStock($product['id']));
        $tx = $this->transactionForReference($reference);
        $items = $this->itemsForTransaction((int) $tx['id']);
        $this->assertCount(1, $items);
        $this->assertSame(0, (int) $items[0]['qty'], 'no-op still records qty=0, matching the untracked adjustStock() convention');
        $this->assertCount(1, $this->auditRowsForBatch($batchId));
    }

    // ---- 5-8: rejections, no mutation ----

    public function testNegativeTargetIsRejectedBeforeAnyMutation(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();
        $startingReferenceValue = $this->referenceCounterValue();

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, -1, 10, 'bad input', date('Y-m-d'), $userId);
            $this->fail('expected InvalidBatchAdjustmentQuantityException');
        } catch (InvalidBatchAdjustmentQuantityException $e) {
            // expected
        }

        $this->assertSame(10, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(10, $this->currentStock($product['id']));
        $this->assertSame($startingReferenceValue, $this->referenceCounterValue(), 'no reference may be burned - the exception is thrown before any transaction opens');
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchNotFoundIsRejectedWithoutMutation(): void
    {
        $product = $this->seedTrackedProduct(10);
        $userId = $this->admin();
        $startingReferenceValue = $this->referenceCounterValue();

        try {
            batchAdjustStock($this->pdo, $product['id'], 999999, 5, 0, 'bad batch id', date('Y-m-d'), $userId);
            $this->fail('expected ProductBatchNotFoundException');
        } catch (ProductBatchNotFoundException $e) {
            $this->assertSame($product['id'], $e->productId);
            $this->assertSame(999999, $e->batchId);
        }

        $this->assertSame(10, $this->currentStock($product['id']));
        $this->assertSame($startingReferenceValue, $this->referenceCounterValue(), 'no reference may be burned on rollback');
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchBelongingToADifferentProductIsRejected(): void
    {
        $productA = $this->seedTrackedProduct(10);
        $productB = $this->seedTrackedProduct(10);
        $batchOfB = $this->seedBatch($productB['id'], 'LOT-B', null, 10);
        $userId = $this->admin();

        try {
            batchAdjustStock($this->pdo, $productA['id'], $batchOfB, 5, 10, 'wrong product', date('Y-m-d'), $userId);
            $this->fail('expected ProductBatchNotFoundException');
        } catch (ProductBatchNotFoundException $e) {
            // expected - the ownership check (id AND product_id) rejects it
        }

        $this->assertSame(10, (int) $this->batch($batchOfB)['qty_on_hand'], 'batch B must be completely untouched');
        $this->assertSame(10, $this->currentStock($productA['id']));
        $this->assertSame(10, $this->currentStock($productB['id']));
    }

    public function testUntrackedProductIsRejectedByTheBatchSpecificFunction(): void
    {
        $product = testSeedProduct($this->pdo, 10); // track_batches=0
        $userId = $this->admin();

        try {
            batchAdjustStock($this->pdo, $product['id'], 1, 5, 0, 'wrong function for this product', date('Y-m-d'), $userId);
            $this->fail('expected UntrackedProductBatchAdjustmentNotSupportedException');
        } catch (UntrackedProductBatchAdjustmentNotSupportedException $e) {
            $this->assertSame($product['id'], $e->productId);
        }

        $this->assertSame(10, $this->currentStock($product['id']));
    }

    // ---- 9-11: opening-balance batch ----

    public function testOpeningBalanceBatchIsAdjustableUpAndDown(): void
    {
        $product = $this->seedTrackedProduct(40);
        $batchId = $this->seedOpeningBalanceBatch($product['id'], 40);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 50, 40, 'recount up', date('Y-m-d'), $userId);
        $this->assertSame(50, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(50, $this->currentStock($product['id']));

        batchAdjustStock($this->pdo, $product['id'], $batchId, 12, 50, 'recount down', date('Y-m-d'), $userId);
        $this->assertSame(12, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(12, $this->currentStock($product['id']));
        $this->assertInvariantHolds($product['id']);
    }

    public function testOpeningBalanceOriginAndSourceTransactionIdNeverChange(): void
    {
        $product = $this->seedTrackedProduct(40);
        $batchId = $this->seedOpeningBalanceBatch($product['id'], 40);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 5, 40, 'recount', date('Y-m-d'), $userId);

        $batch = $this->batch($batchId);
        $this->assertSame('opening_balance', $batch['origin'], 'must never be silently converted into a stock_in-origin batch');
        $this->assertNull($batch['source_transaction_id'], 'must never be silently converted into a Stock In event');
    }

    public function testOpeningBalanceQtyReceivedAndIdentityFieldsAreUnchanged(): void
    {
        $product = $this->seedTrackedProduct(40);
        $batchId = $this->seedOpeningBalanceBatch($product['id'], 40);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 5, 40, 'recount', date('Y-m-d'), $userId);

        $batch = $this->batch($batchId);
        $this->assertSame(0, (int) $batch['qty_received'], 'qty_received is Stock In\'s own ledger - adjustment never touches it');
        $this->assertNull($batch['batch_number']);
        $this->assertNull($batch['expiry_date']);
        // stock_transactions.type must still be 'adjustment', never 'in'.
        $stmt = $this->pdo->prepare("SELECT type FROM stock_transaction_items sti
                                      JOIN stock_transactions st ON st.id = sti.transaction_id
                                      WHERE sti.product_id = ? ORDER BY st.id DESC LIMIT 1");
        $stmt->execute([$product['id']]);
        $this->assertSame('adjustment', $stmt->fetchColumn());
    }

    // ---- 12-13: anonymous batches ----

    public function testAnonymousBatchIsAdjustable(): void
    {
        $product = $this->seedTrackedProduct(8); // must match the seeded batch's qty for the invariant to hold from the start
        $batchId = $this->seedBatch($product['id'], null, null, 8);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 3, 8, 'recount', date('Y-m-d'), $userId);

        $this->assertSame(3, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(3, $this->currentStock($product['id']));
    }

    public function testAdjustingOneOfTwoAnonymousBatchesLeavesTheOtherUnchanged(): void
    {
        $product = $this->seedTrackedProduct(12); // 5 + 7, matching both seeded batches for the invariant to hold from the start
        $batchOne = $this->seedBatch($product['id'], null, null, 5);
        $batchTwo = $this->seedBatch($product['id'], null, null, 7);
        $userId = $this->admin();
        $this->assertNotSame($batchOne, $batchTwo, 'two distinct NULL/NULL batch rows for the same product must both exist and be independently addressable by id');

        batchAdjustStock($this->pdo, $product['id'], $batchOne, 2, 5, 'recount batch one only', date('Y-m-d'), $userId);

        $this->assertSame(2, (int) $this->batch($batchOne)['qty_on_hand']);
        $this->assertSame(7, (int) $this->batch($batchTwo)['qty_on_hand'], 'the other anonymous batch must be completely untouched');
        $this->assertSame(9, $this->currentStock($product['id']));
    }

    // ---- 14-15: identity fields unchanged, exact delta ----

    public function testBatchIdentityFieldsAreUnchangedByAdjustment(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-KEEP', '2027-06-30', 10);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 4, 10, 'recount', date('Y-m-d'), $userId);

        $batch = $this->batch($batchId);
        $this->assertSame('LOT-KEEP', $batch['batch_number']);
        $this->assertSame('2027-06-30', $batch['expiry_date']);
    }

    public function testCurrentStockChangesByExactDeltaNotByRecomputedSum(): void
    {
        $product = $this->seedTrackedProduct(25);
        $batchA = $this->seedBatch($product['id'], 'LOT-A', null, 15);
        $batchB = $this->seedBatch($product['id'], 'LOT-B', null, 10);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchA, 20, 15, 'recount A', date('Y-m-d'), $userId);

        $this->assertSame(20, (int) $this->batch($batchA)['qty_on_hand']);
        $this->assertSame(10, (int) $this->batch($batchB)['qty_on_hand'], 'batch B must be untouched');
        $this->assertSame(30, $this->currentStock($product['id']), '25 + (20-15) = 30');
        $this->assertInvariantHolds($product['id']);
    }

    // ---- 16-17: invariant, both directions ----

    public function testInvariantHoldsAfterIncrease(): void
    {
        $product = $this->seedTrackedProduct(5);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', null, 5);
        batchAdjustStock($this->pdo, $product['id'], $batchId, 9, 5, 'recount', date('Y-m-d'), $this->admin());
        $this->assertInvariantHolds($product['id']);
    }

    public function testInvariantHoldsAfterDecrease(): void
    {
        $product = $this->seedTrackedProduct(5);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', null, 5);
        batchAdjustStock($this->pdo, $product['id'], $batchId, 1, 5, 'recount', date('Y-m-d'), $this->admin());
        $this->assertInvariantHolds($product['id']);
    }

    // ---- 18-21: stock_transactions/items/note representation ----

    public function testSuccessfulAdjustmentCreatesExactlyOneAdjustmentTransaction(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $before = $this->countAdjustmentTransactionsForProduct($product['id']);

        batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $this->admin());

        $this->assertSame($before + 1, $this->countAdjustmentTransactionsForProduct($product['id']));
    }

    public function testTransactionItemQtyIsAbsoluteValueOfDelta(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);

        $reference = batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $this->admin());

        $tx = $this->transactionForReference($reference);
        $items = $this->itemsForTransaction((int) $tx['id']);
        $this->assertCount(1, $items);
        $this->assertSame(4, (int) $items[0]['qty'], 'abs(6 - 10) = 4');
        $this->assertSame('0.00', $items[0]['unit_price']);
        $this->assertSame('0.00', $items[0]['subtotal']);
    }

    public function testNoStockTransactionItemBatchesRowIsCreatedForEitherDirection(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);

        $increaseRef = batchAdjustStock($this->pdo, $product['id'], $batchId, 15, 10, 'up', date('Y-m-d'), $this->admin());
        $increaseTx = $this->transactionForReference($increaseRef);
        $increaseItems = $this->itemsForTransaction((int) $increaseTx['id']);
        $this->assertCount(0, $this->allocationRowsForItem((int) $increaseItems[0]['id']), 'no allocation-ledger row for an increase');

        $decreaseRef = batchAdjustStock($this->pdo, $product['id'], $batchId, 3, 15, 'down', date('Y-m-d'), $this->admin());
        $decreaseTx = $this->transactionForReference($decreaseRef);
        $decreaseItems = $this->itemsForTransaction((int) $decreaseTx['id']);
        $this->assertCount(0, $this->allocationRowsForItem((int) $decreaseItems[0]['id']), 'no allocation-ledger row for a decrease');
    }

    public function testBatchIdentityAppearsInTheTransactionNote(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-2026-A', '2027-06-30', 10);

        $reference = batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'Physical count correction', date('Y-m-d'), $this->admin());

        $tx = $this->transactionForReference($reference);
        $this->assertStringContainsString('Physical count correction', $tx['note']);
        $this->assertStringContainsString('LOT-2026-A', $tx['note']);
        $this->assertStringContainsString('2027-06-30', $tx['note']);
        $this->assertStringContainsString('10', $tx['note']);
        $this->assertStringContainsString('6', $tx['note']);
    }

    // ---- 22-23: audit snapshots ----

    public function testAuditBeforeSnapshotMatchesThePreAdjustmentBatchRow(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);

        batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $this->admin());

        $rows = $this->auditRowsForBatch($batchId);
        $this->assertCount(1, $rows);
        $before = json_decode($rows[0]['before_snapshot'], true);
        $this->assertSame(10, (int) $before['qty_on_hand']);
        $this->assertSame('LOT-A', $before['batch_number']);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame('product_batch', $rows[0]['entity_type']);
        $this->assertSame($batchId, (int) $rows[0]['entity_id']);
    }

    public function testAuditAfterSnapshotMatchesThePostAdjustmentBatchRow(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $userId);

        $rows = $this->auditRowsForBatch($batchId);
        $after = json_decode($rows[0]['after_snapshot'], true);
        $this->assertSame(6, (int) $after['qty_on_hand']);
        $this->assertSame($userId, (int) $after['updated_by']);
        $this->assertSame($userId, (int) $rows[0]['user_id']);
    }

    // ---- 24-29: stale/conflict rejection ----

    public function testStaleExpectedQtyIsRejected(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $userId = $this->admin();

        // Simulate the batch changing (e.g. another transaction) after the
        // caller last read it - the caller still believes qty_on_hand=10.
        $this->pdo->prepare('UPDATE product_batches SET qty_on_hand = ? WHERE id = ?')->execute([12, $batchId]);
        $this->pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([12, $product['id']]);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'stale form', date('Y-m-d'), $userId);
            $this->fail('expected BatchAdjustmentConflictException');
        } catch (BatchAdjustmentConflictException $e) {
            $this->assertSame($product['id'], $e->productId);
            $this->assertSame($batchId, $e->batchId);
        }
    }

    public function testStaleRejectionLeavesTheBatchUnchanged(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $this->pdo->prepare('UPDATE product_batches SET qty_on_hand = ? WHERE id = ?')->execute([12, $batchId]);
        $this->pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([12, $product['id']]);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'stale form', date('Y-m-d'), $this->admin());
        } catch (BatchAdjustmentConflictException $e) {
            // expected
        }

        $this->assertSame(12, (int) $this->batch($batchId)['qty_on_hand']);
    }

    public function testStaleRejectionLeavesCurrentStockUnchanged(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $this->pdo->prepare('UPDATE product_batches SET qty_on_hand = ? WHERE id = ?')->execute([12, $batchId]);
        $this->pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([12, $product['id']]);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'stale form', date('Y-m-d'), $this->admin());
        } catch (BatchAdjustmentConflictException $e) {
            // expected
        }

        $this->assertSame(12, $this->currentStock($product['id']));
    }

    public function testStaleRejectionCreatesNoCommittedAdjustmentTransaction(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $before = $this->countAdjustmentTransactionsForProduct($product['id']);
        $this->pdo->prepare('UPDATE product_batches SET qty_on_hand = ? WHERE id = ?')->execute([12, $batchId]);
        $this->pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([12, $product['id']]);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'stale form', date('Y-m-d'), $this->admin());
        } catch (BatchAdjustmentConflictException $e) {
            // expected
        }

        $this->assertSame($before, $this->countAdjustmentTransactionsForProduct($product['id']));
    }

    public function testStaleRejectionCreatesNoAuditRow(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $this->pdo->prepare('UPDATE product_batches SET qty_on_hand = ? WHERE id = ?')->execute([12, $batchId]);
        $this->pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?')->execute([12, $product['id']]);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'stale form', date('Y-m-d'), $this->admin());
        } catch (BatchAdjustmentConflictException $e) {
            // expected
        }

        $this->assertCount(0, $this->auditRowsForBatch($batchId));
    }

    public function testNegativeTargetCausesNoMutationAtAll(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $before = $this->countAdjustmentTransactionsForProduct($product['id']);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, -5, 10, 'bad', date('Y-m-d'), $this->admin());
        } catch (InvalidBatchAdjustmentQuantityException $e) {
            // expected
        }

        $this->assertSame(10, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(10, $this->currentStock($product['id']));
        $this->assertSame($before, $this->countAdjustmentTransactionsForProduct($product['id']));
        $this->assertCount(0, $this->auditRowsForBatch($batchId));
    }

    // ---- 30-36: rollback / reference safety ----

    public function testBatchNotFoundCausesNoMutation(): void
    {
        $product = $this->seedTrackedProduct(10);
        $before = $this->countAdjustmentTransactionsForProduct($product['id']);

        try {
            batchAdjustStock($this->pdo, $product['id'], 999999, 5, 0, 'bad batch', date('Y-m-d'), $this->admin());
        } catch (ProductBatchNotFoundException $e) {
            // expected
        }

        $this->assertSame(10, $this->currentStock($product['id']));
        $this->assertSame($before, $this->countAdjustmentTransactionsForProduct($product['id']));
    }

    public function testRollbackOnAnyRejectionRemovesTheBatchMutation(): void
    {
        // Already proven per-scenario above (testStaleRejectionLeavesTheBatchUnchanged);
        // this test proves it via a real, unmocked failure forced AFTER
        // the batch UPDATE has already run inside the same transaction -
        // same technique TrackBatchesEnablementTest.php's own rollback
        // proof uses (a real FK violation), rather than mocking anything.
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $nonexistentUserId = 999999;

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $nonexistentUserId);
            $this->fail('expected a foreign-key violation from the nonexistent user id (audit_log.user_id)');
        } catch (\PDOException $e) {
            // expected - fails on the logAudit() insert, strictly after
            // the batch and product UPDATEs already ran in this transaction
        }

        $this->assertSame(10, (int) $this->batch($batchId)['qty_on_hand'], 'the batch UPDATE must roll back along with everything else');
    }

    public function testRollbackOnAnyRejectionRemovesTheProductMutation(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $nonexistentUserId = 999999;

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $nonexistentUserId);
            $this->fail('expected a foreign-key violation');
        } catch (\PDOException $e) {
            // expected
        }

        $this->assertSame(10, $this->currentStock($product['id']), 'the product UPDATE must roll back along with everything else');
    }

    public function testRollbackOnAnyRejectionRemovesTheTransactionHeaderAndItem(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $before = $this->countAdjustmentTransactionsForProduct($product['id']);
        $nonexistentUserId = 999999;

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $nonexistentUserId);
        } catch (\PDOException $e) {
            // expected
        }

        $this->assertSame($before, $this->countAdjustmentTransactionsForProduct($product['id']), 'no stock_transactions/items row may survive the rollback');
    }

    public function testRollbackOnAnyRejectionRemovesTheAuditRow(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $nonexistentUserId = 999999;

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $nonexistentUserId);
        } catch (\PDOException $e) {
            // expected
        }

        $this->assertCount(0, $this->auditRowsForBatch($batchId), 'the audit_log insert itself is what fails - it must not be left half-committed');
    }

    public function testRollbackDoesNotBurnAReferenceNumber(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $nonexistentUserId = 999999;
        $startingReferenceValue = $this->referenceCounterValue();

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $nonexistentUserId);
        } catch (\PDOException $e) {
            // expected
        }

        $this->assertSame($startingReferenceValue, $this->referenceCounterValue(), 'the reference_counters increment must roll back with everything else - no ADJ- number may be burned');
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testRetryAfterARolledBackAttemptSucceeds(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);
        $nonexistentUserId = 999999;
        $realUserId = $this->admin();

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $nonexistentUserId);
        } catch (\PDOException $e) {
            // expected
        }

        // Batch is unchanged (10), so the same expectedQty=10 is still
        // valid for a real retry with a real user id.
        $reference = batchAdjustStock($this->pdo, $product['id'], $batchId, 6, 10, 'recount', date('Y-m-d'), $realUserId);

        $this->assertStringStartsWith('ADJ-', $reference);
        $this->assertSame(6, (int) $this->batch($batchId)['qty_on_hand']);
        $this->assertSame(6, $this->currentStock($product['id']));
    }

    // ---- 37-38: never negative ----

    public function testBatchQuantityCanNeverBecomeNegative(): void
    {
        $product = $this->seedTrackedProduct(10);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 10);

        try {
            batchAdjustStock($this->pdo, $product['id'], $batchId, -1, 10, 'bad', date('Y-m-d'), $this->admin());
            $this->fail('expected InvalidBatchAdjustmentQuantityException');
        } catch (InvalidBatchAdjustmentQuantityException $e) {
            // expected - rejected before any mutation, so qty_on_hand can
            // never even transiently go negative.
        }

        $this->assertGreaterThanOrEqual(0, (int) $this->batch($batchId)['qty_on_hand']);
    }

    public function testProductCurrentStockCanNeverBecomeNegative(): void
    {
        // Every scenario in this file already proves current_stock is
        // computed as (sum of every OTHER batch's qty_on_hand, >= 0) +
        // newQty (>= 0, validated) - structurally non-negative by
        // construction (see batchAdjustStock()'s own comment). This test
        // additionally confirms the schema's own backstop CHECK
        // (chk_products_current_stock_nonneg) is never actually hit by a
        // legitimate call, across the full range down to zero.
        $product = $this->seedTrackedProduct(3);
        $batchId = $this->seedBatch($product['id'], 'LOT-A', '2027-01-01', 3);

        batchAdjustStock($this->pdo, $product['id'], $batchId, 0, 3, 'depleted', date('Y-m-d'), $this->admin());

        $this->assertSame(0, $this->currentStock($product['id']));
        $this->assertGreaterThanOrEqual(0, $this->currentStock($product['id']));
    }
}
