<?php
declare(strict_types=1);

namespace Tests\Integration;

use PurchaseOrderNotCancellableException;
use PurchaseOrderNotFoundException;
use PurchaseOrderNotReceivableException;
use Tests\TestCase;

// Phase P3-A (Purchase Order Cancellation). Exercises
// includes/purchase_order.php's cancelPurchaseOrder() against the
// isolated test database - no mocking of the DB layer, same discipline
// as PurchaseOrderTest.php (P1) and PurchaseOrderReceivingTest.php (P2).
final class PurchaseOrderCancellationTest extends TestCase
{
    private function admin(): int
    {
        return testSeedAdmin($this->pdo)['id'];
    }

    private function draftPo(int $userId, array $lines): array
    {
        $supplier = testSeedSupplier($this->pdo);
        return createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, $lines, $userId);
    }

    private function poItemIds(int $poId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id');
        $stmt->execute([$poId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ---- Happy path ----

    public function testOrderedTransitionsToCancelled(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$po['id']]);
        $this->assertSame('cancelled', $stmt->fetchColumn());
    }

    public function testPartiallyReceivedTransitionsToCancelled(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);

        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 4, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$po['id']]);
        $this->assertSame('cancelled', $stmt->fetchColumn());
    }

    // ---- Rejections ----

    public function testDraftIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);

        $this->expectException(PurchaseOrderNotCancellableException::class);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);
    }

    public function testReceivedIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 5, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        $this->expectException(PurchaseOrderNotCancellableException::class);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);
    }

    public function testAlreadyCancelledIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $this->expectException(PurchaseOrderNotCancellableException::class);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);
    }

    public function testNonexistentPurchaseOrderIsRejected(): void
    {
        $userId = $this->admin();
        $this->expectException(PurchaseOrderNotFoundException::class);
        cancelPurchaseOrder($this->pdo, 999999999, $userId);
    }

    public function testRepeatedCancellationCreatesNoAdditionalAudit(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $countBefore = $this->countCancelAuditRows($po['id']);

        try {
            cancelPurchaseOrder($this->pdo, $po['id'], $userId);
            $this->fail('expected PurchaseOrderNotCancellableException');
        } catch (PurchaseOrderNotCancellableException $e) {
            // expected
        }

        $this->assertSame($countBefore, $this->countCancelAuditRows($po['id']), 'a rejected repeat cancellation must not create another audit row');
    }

    public function testCancelledPurchaseOrderCannotSubsequentlyBeReceived(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $this->expectException(PurchaseOrderNotReceivableException::class);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 1, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    // ---- Audit ----

    public function testExactlyOneAuditRowForSuccessfulCancellation(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        $countBeforeCancel = $this->countAllPoAuditRows($po['id']);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);
        $countAfterCancel = $this->countAllPoAuditRows($po['id']);

        $this->assertSame($countBeforeCancel + 1, $countAfterCancel, 'cancellation must write exactly one additional audit row');
    }

    public function testAuditContainsCorrectBeforeAfterStatus(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $row = $this->lastCancelAuditRow($po['id']);
        $before = json_decode($row['before_snapshot'], true);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame('ordered', $before['status']);
        $this->assertSame('cancelled', $after['status']);
    }

    public function testAuditCarriesOptionalReasonOnlyInAfterSnapshot(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId, 'supplier could not fulfill remaining order');

        $row = $this->lastCancelAuditRow($po['id']);
        $before = json_decode($row['before_snapshot'], true);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertArrayNotHasKey('cancel_reason', $before, 'the reason must never appear in the before snapshot');
        $this->assertSame('supplier could not fulfill remaining order', $after['cancel_reason']);

        // The reason must never be written to the PO's own note column.
        $stmt = $this->pdo->prepare('SELECT note FROM purchase_orders WHERE id = ?');
        $stmt->execute([$po['id']]);
        $this->assertNull($stmt->fetchColumn(), 'a cancellation reason must never be written to purchase_orders.note');
    }

    public function testNoReasonSuppliedOmitsReasonFromAuditEntirely(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $row = $this->lastCancelAuditRow($po['id']);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertArrayNotHasKey('cancel_reason', $after);
    }

    // ---- Zero-mutation guarantees (the core correctness requirement) ----

    public function testCancellationDoesNotChangeStock(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 50);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 4, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        $stockBefore = $this->currentStock($product['id']);
        cancelPurchaseOrder($this->pdo, $po['id'], $userId);
        $stockAfter = $this->currentStock($product['id']);

        $this->assertSame($stockBefore, $stockAfter, 'cancelling a partially received PO must never change product stock');
        $this->assertSame(54, $stockAfter, 'stock must reflect the 4 already-received units on top of the seeded 50, untouched by cancellation');
    }

    public function testCancellationDoesNotChangeReceivedQty(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 6, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT received_qty, ordered_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$itemIds[0]]);
        $before = $stmt->fetch(\PDO::FETCH_ASSOC);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt->execute([$itemIds[0]]);
        $after = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame($before['received_qty'], $after['received_qty']);
        $this->assertSame($before['ordered_qty'], $after['ordered_qty']);
        $this->assertSame(6, (int) $after['received_qty']);
        $this->assertSame(10, (int) $after['ordered_qty']);
    }

    public function testCancellationDoesNotChangePurchaseOrderReceipts(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 3, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_receipts WHERE purchase_order_item_id = ?');
        $stmt->execute([$itemIds[0]]);
        $before = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt->execute([$itemIds[0]]);
        $after = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame($before, $after, 'purchase_order_receipts rows must be byte-for-byte unchanged by cancellation');
        $this->assertCount(1, $after);
    }

    public function testCancellationDoesNotCreateStockTransactions(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        $countBefore = $this->countRows('stock_transactions');
        $itemCountBefore = $this->countRows('stock_transaction_items');

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'cancellation must never create a stock_transactions row');
        $this->assertSame($itemCountBefore, $this->countRows('stock_transaction_items'), 'cancellation must never create a stock_transaction_items row');
    }

    public function testCancellationDoesNotModifyPurchaseOrderItems(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 7, 'unit_cost' => 2.50]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$itemIds[0]]);
        $before = $stmt->fetch(\PDO::FETCH_ASSOC);

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt->execute([$itemIds[0]]);
        $after = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame($before, $after, 'purchase_order_items row must be byte-for-byte unchanged by cancellation');
    }

    // ---- Full before/after snapshot comparison for a partially received PO ----

    public function testPartiallyReceivedCancellationLeavesEverythingElseExactlyUnchanged(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 20);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [[
            'purchase_order_item_id' => $itemIds[0], 'qty' => 4, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null,
        ]], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$itemIds[0]]);
        $receivedQtyBefore = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_receipts WHERE purchase_order_item_id = ?');
        $stmt->execute([$itemIds[0]]);
        $receiptsBefore = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stockBefore = $this->currentStock($product['id']);
        $stiCountBefore = $this->countRows('stock_transaction_items');

        cancelPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$itemIds[0]]);
        $this->assertSame($receivedQtyBefore, $stmt->fetchColumn());

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_receipts WHERE purchase_order_item_id = ?');
        $stmt->execute([$itemIds[0]]);
        $this->assertSame($receiptsBefore, $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertSame($stockBefore, $this->currentStock($product['id']));
        $this->assertSame($stiCountBefore, $this->countRows('stock_transaction_items'));

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$po['id']]);
        $this->assertSame('cancelled', $stmt->fetchColumn());
    }

    // ---- helpers ----

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

    private function countAllPoAuditRows(int $poId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ?");
        $stmt->execute([$poId]);
        return (int) $stmt->fetchColumn();
    }

    private function countCancelAuditRows(int $poId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'update'");
        $stmt->execute([$poId]);
        return (int) $stmt->fetchColumn();
    }

    private function lastCancelAuditRow(int $poId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$poId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
