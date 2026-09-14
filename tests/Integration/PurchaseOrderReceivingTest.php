<?php
declare(strict_types=1);

namespace Tests\Integration;

use PurchaseOrderItemMismatchException;
use PurchaseOrderNotDraftException;
use PurchaseOrderNotFoundException;
use PurchaseOrderNotReceivableException;
use PurchaseOrderOverReceiveException;
use Tests\TestCase;

// Phase P2 (Purchase Order Receiving / Stock In Integration). Exercises
// includes/purchase_order.php's submitPurchaseOrder()/receivePurchaseOrder()
// against the isolated test database - no mocking of the DB layer, same
// discipline as PurchaseOrderTest.php (P1) and StockTest.php.
final class PurchaseOrderReceivingTest extends TestCase
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

    // ---- Submit ----

    public function testSubmitTransitionsDraftToOrdered(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);

        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$po['id']]);
        $this->assertSame('ordered', $stmt->fetchColumn());
    }

    public function testSubmitAuditsStatusChange(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);

        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'update' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$po['id']]);
        $row = $stmt->fetch();
        $before = json_decode($row['before_snapshot'], true);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame('draft', $before['status']);
        $this->assertSame('ordered', $after['status']);
    }

    public function testCannotSubmitANonDraftPurchaseOrder(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        $this->expectException(PurchaseOrderNotDraftException::class);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
    }

    public function testCannotEditAPurchaseOrderAfterSubmit(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);

        try {
            updatePurchaseOrder($this->pdo, $po['id'], 1, date('Y-m-d'), null, 'tamper', [['product_id' => $product['id'], 'ordered_qty' => 999, 'unit_cost' => 999]], $userId);
            $this->fail('expected PurchaseOrderNotDraftException');
        } catch (PurchaseOrderNotDraftException $e) {
            $this->assertSame('ordered', $e->status);
        }

        $stmt = $this->pdo->prepare('SELECT ordered_qty FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$po['id']]);
        $this->assertSame(10, (int) $stmt->fetchColumn(), 'ordered_qty must remain immutable after submit');
    }

    // ---- Full / partial receiving ----

    public function testFullReceiveInOneDeliveryTransitionsToReceived(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 25, 'unit_cost' => 2.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $result = receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 25, 'unit_cost' => 2.00, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $this->assertSame('received', $result['status']);
        $this->assertStringStartsWith('STI-', $result['stock_reference']);

        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$po['id']]);
        $this->assertSame('received', $stmt->fetchColumn());

        $this->assertSame(25, $this->currentStock($product['id']));
    }

    public function testPartialReceiveTransitionsToPartiallyReceived(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 100, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $result = receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 60, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $this->assertSame('partially_received', $result['status']);
        $this->assertSame(60, $this->currentStock($product['id']));

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame(60, (int) $stmt->fetchColumn());
    }

    public function testMultiplePartialReceiptsAccumulateToReceived(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 100, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $r1 = receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 30, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
        $this->assertSame('partially_received', $r1['status']);

        $r2 = receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 30, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
        $this->assertSame('partially_received', $r2['status']);

        $r3 = receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 40, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
        $this->assertSame('received', $r3['status']);

        $this->assertSame(100, $this->currentStock($product['id']));
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_order_receipts WHERE purchase_order_item_id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame(3, (int) $stmt->fetchColumn(), 'three separate receiving events must produce three receipt rows');
    }

    public function testEverySuccessfulReceiveAuditsExactlyOnceIncludingPartialToPartial(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [
            ['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00],
        ]);
        // Add a second line so the PO stays 'partially_received' across
        // both receiving events - proving the audit fires even when the
        // aggregate status label does NOT change between them.
        $product2 = testSeedProduct($this->pdo, 0);
        updatePurchaseOrder($this->pdo, $po['id'], 1, date('Y-m-d'), null, null, [
            ['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00],
            ['product_id' => $product2['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00],
        ], $userId);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        $itemIds = $this->poItemIds($po['id']);

        $countBefore = $this->countRows('audit_log');

        // Receive 1: only 5 of item[0] - status ordered -> partially_received
        receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $itemIds[0], 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());
        $this->assertSame($countBefore + 1, $this->countRows('audit_log'));

        // Receive 2: only 5 more of item[0] - status stays partially_received
        // (item[1] still fully outstanding) - the audit MUST still fire.
        receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $itemIds[0], 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());
        $this->assertSame($countBefore + 2, $this->countRows('audit_log'), 'a partially_received -> partially_received receive must still audit');

        $stmt = $this->pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
        $stmt->execute([$po['id']]);
        $this->assertSame('partially_received', $stmt->fetchColumn());

        // The second audit row's before/after must both show 'partially_received'
        // (unchanged status) and its own received_this_event.
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'update' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$po['id']]);
        $row = $stmt->fetch();
        $before = json_decode($row['before_snapshot'], true);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame('partially_received', $before['status']);
        $this->assertSame('partially_received', $after['status']);
        $this->assertArrayHasKey('received_this_event', $after);
        $this->assertCount(1, $after['received_this_event']);
        $this->assertSame($itemIds[0], $after['received_this_event'][0]['purchase_order_item_id']);
        $this->assertSame(5, $after['received_this_event'][0]['qty']);
    }

    // ---- Rejections ----

    public function testReceiveOnFullyReceivedPurchaseOrderIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);
        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 10, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());

        $this->expectException(PurchaseOrderNotReceivableException::class);
        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 1, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    public function testReceiveOnADraftPurchaseOrderIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        [$poItemId] = $this->poItemIds($po['id']);

        $this->expectException(PurchaseOrderNotReceivableException::class);
        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 1, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    public function testOverReceivingIsRejectedWithZeroMutation(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $countBefore = $this->countRows('stock_transactions');
        $stockBefore = $this->currentStock($product['id']);

        try {
            receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 11, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
            $this->fail('expected PurchaseOrderOverReceiveException');
        } catch (PurchaseOrderOverReceiveException $e) {
            // expected
        }

        $this->assertSame($stockBefore, $this->currentStock($product['id']), 'stock must be unchanged after a rejected over-receive');
        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'no Stock In transaction may be created for a rejected over-receive');
        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $stmt = $this->pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
        $stmt->execute([$po['id']]);
        $this->assertSame('ordered', $stmt->fetchColumn(), 'status must not have moved');
    }

    public function testOverReceivingAcrossTwoSeparatePartialCallsIsRejectedOnTheSecondCall(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 7, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());

        $this->expectException(PurchaseOrderOverReceiveException::class);
        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    public function testZeroQuantityIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $this->expectException(\InvalidArgumentException::class);
        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 0, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $this->expectException(\InvalidArgumentException::class);
        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => -3, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    public function testTamperedItemIdFromAnotherPurchaseOrderIsRejectedWithNoMutation(): void
    {
        $userId = $this->admin();
        $productA = testSeedProduct($this->pdo, 0);
        $productB = testSeedProduct($this->pdo, 0);
        $poA = $this->draftPo($userId, [['product_id' => $productA['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        $poB = $this->draftPo($userId, [['product_id' => $productB['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $poA['id'], $userId);
        submitPurchaseOrder($this->pdo, $poB['id'], $userId);
        [$poBItemId] = $this->poItemIds($poB['id']);

        try {
            receivePurchaseOrder($this->pdo, $poA['id'], [['purchase_order_item_id' => $poBItemId, 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
            $this->fail('expected PurchaseOrderItemMismatchException');
        } catch (PurchaseOrderItemMismatchException $e) {
            $this->assertSame($poA['id'], $e->poId);
            $this->assertSame($poBItemId, $e->poItemId);
        }

        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poBItemId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'PO B item must be untouched');
        $stmt = $this->pdo->prepare("SELECT status FROM purchase_orders WHERE id = ?");
        $stmt->execute([$poA['id']]);
        $this->assertSame('ordered', $stmt->fetchColumn(), 'PO A status must be untouched');
    }

    public function testNonexistentPurchaseOrderIsRejected(): void
    {
        $userId = $this->admin();
        $this->expectException(PurchaseOrderNotFoundException::class);
        receivePurchaseOrder($this->pdo, 999999999, [['purchase_order_item_id' => 1, 'qty' => 1, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());
    }

    // ---- Rollback on downstream failure ----

    public function testMultiLineReceiveRollsBackEntirelyWhenOneLineOverReceives(): void
    {
        $userId = $this->admin();
        $product1 = testSeedProduct($this->pdo, 0);
        $product2 = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [
            ['product_id' => $product1['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00],
            ['product_id' => $product2['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00],
        ]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$item1, $item2] = $this->poItemIds($po['id']);

        $countBefore = $this->countRows('stock_transactions');

        try {
            receivePurchaseOrder($this->pdo, $po['id'], [
                ['purchase_order_item_id' => $item1, 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null], // valid alone
                ['purchase_order_item_id' => $item2, 'qty' => 11, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null], // over-receives
            ], date('Y-m-d'), null, $userId, testRandomToken());
            $this->fail('expected PurchaseOrderOverReceiveException');
        } catch (PurchaseOrderOverReceiveException $e) {
            // expected
        }

        // Line 1's already-applied guarded UPDATE must roll back too -
        // proving the whole transaction, not just line 2, was undone.
        $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$item1]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'line 1 must roll back even though it was individually valid');
        $this->assertSame(0, $this->currentStock($product1['id']), 'product 1 stock must roll back too');
        $this->assertSame(0, $this->currentStock($product2['id']));
        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'no Stock In transaction may exist - insertStockInTransaction() must never have been reached');
    }

    // ---- Multi-line / mapping contract ----

    public function testMultipleLinesReceivedInOneOperationMapToCorrectStockTransactionItems(): void
    {
        $userId = $this->admin();
        $productA = testSeedProduct($this->pdo, 0);
        $productB = testSeedProduct($this->pdo, 0);
        $productC = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [
            ['product_id' => $productA['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00],
            ['product_id' => $productB['id'], 'ordered_qty' => 20, 'unit_cost' => 2.00],
            ['product_id' => $productC['id'], 'ordered_qty' => 30, 'unit_cost' => 3.00],
        ]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$itemA, $itemB, $itemC] = $this->poItemIds($po['id']);

        // Deliberately submit in a DIFFERENT order than the PO's own line
        // order, to prove the mapping is positional-to-THIS-CALL's input,
        // not assumed to match the PO's original line order.
        $result = receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $itemC, 'qty' => 30, 'unit_cost' => 3.00, 'batch_number' => null, 'expiry_date' => null],
            ['purchase_order_item_id' => $itemA, 'qty' => 10, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null],
            ['purchase_order_item_id' => $itemB, 'qty' => 20, 'unit_cost' => 2.00, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $this->assertSame('received', $result['status']);
        $this->assertCount(3, $result['receipts']);

        // Verify EACH receipt row links to a stock_transaction_items row
        // for the CORRECT product/quantity - not merely that 3 rows exist.
        foreach ($result['receipts'] as $receipt) {
            $stmt = $this->pdo->prepare('SELECT product_id, qty FROM stock_transaction_items WHERE id = ?');
            $stmt->execute([$receipt['stock_transaction_item_id']]);
            $sti = $stmt->fetch();

            $stmt = $this->pdo->prepare('SELECT product_id FROM purchase_order_items WHERE id = ?');
            $stmt->execute([$receipt['purchase_order_item_id']]);
            $expectedProductId = (int) $stmt->fetchColumn();

            $this->assertSame($expectedProductId, (int) $sti['product_id'], 'the linked stock_transaction_items row must belong to the SAME product as the purchase_order_items row it is linked from');
            $this->assertSame($receipt['qty'], (int) $sti['qty'], 'quantities must match between the receipt and the Stock In line it produced');
        }

        $this->assertSame(10, $this->currentStock($productA['id']));
        $this->assertSame(20, $this->currentStock($productB['id']));
        $this->assertSame(30, $this->currentStock($productC['id']));
    }

    public function testPurchaseOrderReceiptsRowsAreCreatedCorrectly(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $result = receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 10, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_receipts WHERE purchase_order_item_id = ?');
        $stmt->execute([$poItemId]);
        $row = $stmt->fetch();
        $this->assertSame(10, (int) $row['qty']);
        $this->assertSame($result['receipts'][0]['stock_transaction_item_id'], (int) $row['stock_transaction_item_id']);
        $this->assertSame($userId, (int) $row['created_by']);
    }

    // ---- Batch/expiry ----

    public function testTrackedProductReceivingCreatesABatchWithTheGivenIdentity(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0, ['track_batches' => 1]);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 20, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 20, 'unit_cost' => 1.50, 'batch_number' => 'PO-LOT-1', 'expiry_date' => '2030-01-01'],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT batch_number, expiry_date, qty_on_hand, qty_received FROM product_batches WHERE product_id = ?');
        $stmt->execute([$product['id']]);
        $batch = $stmt->fetch();
        $this->assertSame('PO-LOT-1', $batch['batch_number']);
        $this->assertSame('2030-01-01', $batch['expiry_date']);
        $this->assertSame(20, (int) $batch['qty_on_hand']);
        $this->assertSame(20, (int) $batch['qty_received']);
        $this->assertSame(20, $this->currentStock($product['id']));
    }

    public function testTrackedProductReceivedAcrossTwoDeliveriesCreatesTwoDistinctBatches(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0, ['track_batches' => 1]);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 20, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 8, 'unit_cost' => 1.00, 'batch_number' => 'LOT-A', 'expiry_date' => '2030-01-01'],
        ], date('Y-m-d'), null, $userId, testRandomToken());
        receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 12, 'unit_cost' => 1.20, 'batch_number' => 'LOT-B', 'expiry_date' => '2031-06-01'],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT batch_number, qty_on_hand FROM product_batches WHERE product_id = ? ORDER BY batch_number');
        $stmt->execute([$product['id']]);
        $batches = $stmt->fetchAll();
        $this->assertCount(2, $batches);
        $this->assertSame('LOT-A', $batches[0]['batch_number']);
        $this->assertSame(8, (int) $batches[0]['qty_on_hand']);
        $this->assertSame('LOT-B', $batches[1]['batch_number']);
        $this->assertSame(12, (int) $batches[1]['qty_on_hand']);
        $this->assertSame(20, $this->currentStock($product['id']));
    }

    public function testUntrackedProductReceivingCreatesNoBatchRow(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 15, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 15, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $this->assertSame(15, $this->currentStock($product['id']));
    }

    // ---- Cost variance ----

    public function testReceivingCostDifferentFromPoQuotedCostIsRecordedAsActuallyReceived(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 2.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $result = receivePurchaseOrder($this->pdo, $po['id'], [
            ['purchase_order_item_id' => $poItemId, 'qty' => 10, 'unit_cost' => 2.75, 'batch_number' => null, 'expiry_date' => null],
        ], date('Y-m-d'), null, $userId, testRandomToken());

        $stmt = $this->pdo->prepare('SELECT unit_price FROM stock_transaction_items WHERE id = ?');
        $stmt->execute([$result['receipts'][0]['stock_transaction_item_id']]);
        $this->assertSame('2.75', $stmt->fetchColumn(), 'the actual receiving cost must be recorded, not the PO quoted cost');

        // The PO's own quoted unit_cost must remain unchanged.
        $stmt = $this->pdo->prepare('SELECT unit_cost FROM purchase_order_items WHERE id = ?');
        $stmt->execute([$poItemId]);
        $this->assertSame('2.00', $stmt->fetchColumn());
    }

    // ---- Idempotency ----

    public function testDuplicateReceiveTokenIsRejectedAndDoesNotDoubleStock(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);
        $token = testRandomToken();

        receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, $token);

        $this->expectException(\IdempotencyConflictException::class);
        try {
            receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 5, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, $token);
        } finally {
            $this->assertSame(5, $this->currentStock($product['id']), 'a replayed token must not double-increment stock');
            $stmt = $this->pdo->prepare('SELECT received_qty FROM purchase_order_items WHERE id = ?');
            $stmt->execute([$poItemId]);
            $this->assertSame(5, (int) $stmt->fetchColumn(), 'a replayed token must not double-count received_qty');
        }
    }

    public function testFailedReceiveReleasesTheIdempotencyTokenForRetry(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);
        $token = testRandomToken();

        try {
            receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 999, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, $token);
            $this->fail('expected PurchaseOrderOverReceiveException');
        } catch (PurchaseOrderOverReceiveException $e) {
            // expected
        }

        // Same token, now a valid quantity - must succeed since the
        // failed attempt's claim rolled back with everything else.
        $result = receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 10, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, $token);
        $this->assertSame('received', $result['status']);
    }

    // ---- Reference counter unaffected ----

    public function testReceivingDrawsAStockInReferenceFromTheSharedStockTransactionsCounter(): void
    {
        $userId = $this->admin();
        $product = testSeedProduct($this->pdo, 0);
        $po = $this->draftPo($userId, [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 1.00]]);
        submitPurchaseOrder($this->pdo, $po['id'], $userId);
        [$poItemId] = $this->poItemIds($po['id']);

        $stmt = $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'purchase_orders'");
        $poCounterBefore = (int) $stmt->fetchColumn();

        $result = receivePurchaseOrder($this->pdo, $po['id'], [['purchase_order_item_id' => $poItemId, 'qty' => 10, 'unit_cost' => 1.00, 'batch_number' => null, 'expiry_date' => null]], date('Y-m-d'), null, $userId, testRandomToken());

        $this->assertStringStartsWith('STI-', $result['stock_reference'], 'receiving must draw from the STI (stock_transactions) counter, not purchase_orders');

        $stmt = $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'purchase_orders'");
        $poCounterAfter = (int) $stmt->fetchColumn();
        $this->assertSame($poCounterBefore, $poCounterAfter, 'receiving must never advance the purchase_orders reference counter - no new PUR reference is drawn by receiving');
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
}
