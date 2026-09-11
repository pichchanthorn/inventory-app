<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use Tests\TestCase;

// Phase P1 (Purchase / Supplier Order Management - draft-only PO core).
// Exercises includes/purchase_order.php's real functions against the
// isolated test database - no mocking of the DB layer, same discipline
// as StockTest.php/DebtTest.php.
final class PurchaseOrderTest extends TestCase
{
    private function admin(): int
    {
        return testSeedAdmin($this->pdo)['id'];
    }

    // ---- Create ----

    public function testCreatePurchaseOrderProducesCorrectHeaderAndReference(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo,
            $supplier['id'],
            date('Y-m-d'),
            null,
            'test note',
            [['product_id' => $product['id'], 'ordered_qty' => 10, 'unit_cost' => 2.50]],
            $userId
        );

        $this->assertStringStartsWith('PUR-', $result['reference']);
        $this->assertIsInt($result['id']);

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $stmt->execute([$result['id']]);
        $po = $stmt->fetch();
        $this->assertSame('draft', $po['status']);
        $this->assertSame($supplier['id'], (int) $po['supplier_id']);
        $this->assertSame('test note', $po['note']);
        $this->assertNull($po['expected_date']);
    }

    public function testCreatePurchaseOrderStoresCorrectItemsAndSubtotal(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [['product_id' => $product['id'], 'ordered_qty' => 7, 'unit_cost' => 3.25]],
            $userId
        );

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$result['id']]);
        $item = $stmt->fetch();
        $this->assertSame(7, (int) $item['ordered_qty']);
        $this->assertSame('3.25', $item['unit_cost']);
        $this->assertSame('22.75', $item['subtotal'], 'subtotal must equal ordered_qty * unit_cost (generated column)');
        $this->assertSame(0, (int) $item['received_qty'], 'P1 must never write anything but 0 to received_qty');
    }

    public function testCreatePurchaseOrderTotalIsSumOfLineSubtotals(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $p1 = testSeedProduct($this->pdo);
        $p2 = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [
                ['product_id' => $p1['id'], 'ordered_qty' => 4, 'unit_cost' => 2.00],
                ['product_id' => $p2['id'], 'ordered_qty' => 3, 'unit_cost' => 5.00],
            ],
            $userId
        );

        $total = (float) $this->pdo->query('SELECT COALESCE(SUM(subtotal),0) FROM purchase_order_items WHERE purchase_order_id = ' . $result['id'])->fetchColumn();
        $this->assertSame(23.0, $total, '4*2.00 + 3*5.00 = 23.00');
    }

    public function testCreatePurchaseOrderAllowsDuplicateProductLines(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [
                ['product_id' => $product['id'], 'ordered_qty' => 5, 'unit_cost' => 1.00],
                ['product_id' => $product['id'], 'ordered_qty' => 8, 'unit_cost' => 1.50],
            ],
            $userId
        );

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ' . $result['id'])->fetchColumn();
        $this->assertSame(2, $count, 'two separate lines for the same product must both persist, matching stock_transaction_items precedent');
    }

    public function testCreatePurchaseOrderWithExpectedDateStoresIt(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), '2030-01-15', null,
            [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]],
            $userId
        );

        $stmt = $this->pdo->prepare('SELECT expected_date FROM purchase_orders WHERE id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame('2030-01-15', $stmt->fetchColumn());
    }

    public function testCreatePurchaseOrderRejectsNonexistentSupplier(): void
    {
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $countBefore = $this->countRows('purchase_orders');

        $this->expectException(\InvalidArgumentException::class);
        try {
            createPurchaseOrder(
                $this->pdo, 999999999, date('Y-m-d'), null, null,
                [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]],
                $userId
            );
        } finally {
            $this->assertSame($countBefore, $this->countRows('purchase_orders'), 'no PO may be created when supplier does not exist');
        }
    }

    public function testCreatePurchaseOrderRejectsNonexistentProduct(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $userId = $this->admin();
        $countBefore = $this->countRows('purchase_orders');
        $refCounterBefore = $this->referenceCounterValue();

        $this->expectException(\InvalidArgumentException::class);
        try {
            createPurchaseOrder(
                $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
                [['product_id' => 999999999, 'ordered_qty' => 1, 'unit_cost' => 1.00]],
                $userId
            );
        } finally {
            $this->assertSame($countBefore, $this->countRows('purchase_orders'), 'no PO header may survive when a line references a nonexistent product');
            $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must roll back too');
        }
    }

    public function testCreatePurchaseOrderRejectsZeroOrderedQtyAtTheDatabaseLevel(): void
    {
        // The page layer is the primary guard (Http tests cover that);
        // this proves the CHECK constraint backstop actually fires if a
        // caller somehow bypasses page-level validation.
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $countBefore = $this->countRows('purchase_orders');

        $this->expectException(PDOException::class);
        try {
            createPurchaseOrder(
                $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
                [['product_id' => $product['id'], 'ordered_qty' => 0, 'unit_cost' => 1.00]],
                $userId
            );
        } finally {
            $this->assertSame($countBefore, $this->countRows('purchase_orders'));
        }
    }

    public function testCreatePurchaseOrderRejectsNegativeUnitCostAtTheDatabaseLevel(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $countBefore = $this->countRows('purchase_orders');

        $this->expectException(PDOException::class);
        try {
            createPurchaseOrder(
                $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
                [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => -5.00]],
                $userId
            );
        } finally {
            $this->assertSame($countBefore, $this->countRows('purchase_orders'));
        }
    }

    public function testCreatePurchaseOrderAcceptsZeroUnitCost(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 0]],
            $userId
        );

        $stmt = $this->pdo->prepare('SELECT unit_cost, subtotal FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$result['id']]);
        $item = $stmt->fetch();
        $this->assertSame('0.00', $item['unit_cost']);
        $this->assertSame('0.00', $item['subtotal']);
    }

    public function testCreatePurchaseOrderAuditsCorrectly(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [['product_id' => $product['id'], 'ordered_qty' => 5, 'unit_cost' => 1.00]],
            $userId
        );

        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'create'");
        $stmt->execute([$result['id']]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'a create audit row must exist');
        $this->assertNull($row['before_snapshot']);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame($result['reference'], $after['reference']);
        $this->assertSame('draft', $after['status']);
        $this->assertCount(1, $after['items']);
    }

    // ---- Idempotency (create) ----

    public function testCreatePurchaseOrderIsIdempotentUnderDuplicateToken(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $token = testRandomToken();

        createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]],
            $userId, $token
        );
        $countAfterFirst = $this->countRows('purchase_orders');

        $this->expectException(\IdempotencyConflictException::class);
        try {
            createPurchaseOrder(
                $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
                [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]],
                $userId, $token
            );
        } finally {
            $this->assertSame($countAfterFirst, $this->countRows('purchase_orders'), 'a replayed token must not create a second PO');
        }
    }

    public function testFailedCreateReleasesTheIdempotencyTokenForRetry(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $token = testRandomToken();

        try {
            createPurchaseOrder(
                $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
                [['product_id' => 999999999, 'ordered_qty' => 1, 'unit_cost' => 1.00]],
                $userId, $token
            );
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        // Same token, now against a valid line - must succeed since the
        // failed attempt's claim rolled back with everything else.
        $result = createPurchaseOrder(
            $this->pdo, $supplier['id'], date('Y-m-d'), null, null,
            [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]],
            $userId, $token
        );
        $this->assertStringStartsWith('PUR-', $result['reference']);
    }

    // ---- Reference ----

    public function testReferencesAreUnique(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $r1 = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);
        $r2 = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        $this->assertNotSame($r1['reference'], $r2['reference']);
    }

    public function testReferenceCounterDoesNotShareTheStockTransactionsCounter(): void
    {
        $stmt = $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'purchase_orders'");
        $poCounterBefore = (int) $stmt->fetchColumn();
        $stmt = $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stockCounterBefore = (int) $stmt->fetchColumn();

        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        $stmt = $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'");
        $stockCounterAfter = (int) $stmt->fetchColumn();
        $this->assertSame($stockCounterBefore, $stockCounterAfter, 'creating a PO must never advance the stock_transactions counter');

        $stmt = $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'purchase_orders'");
        $poCounterAfter = (int) $stmt->fetchColumn();
        $this->assertGreaterThan($poCounterBefore, $poCounterAfter);
    }

    // ---- Edit ----

    public function testUpdatePurchaseOrderChangesHeaderFieldsAndReplacesItems(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $supplier2 = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $product2 = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $result = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, 'original', [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        updatePurchaseOrder(
            $this->pdo, $result['id'], $supplier2['id'], '2029-05-01', '2029-06-01', 'updated note',
            [['product_id' => $product2['id'], 'ordered_qty' => 9, 'unit_cost' => 4.00]],
            $userId
        );

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $stmt->execute([$result['id']]);
        $po = $stmt->fetch();
        $this->assertSame($supplier2['id'], (int) $po['supplier_id']);
        $this->assertSame('2029-05-01', $po['order_date']);
        $this->assertSame('2029-06-01', $po['expected_date']);
        $this->assertSame('updated note', $po['note']);
        $this->assertSame('draft', $po['status'], 'edit must never change status');

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$result['id']]);
        $items = $stmt->fetchAll();
        $this->assertCount(1, $items, 'old line must be fully replaced, not appended to');
        $this->assertSame($product2['id'], (int) $items[0]['product_id']);
        $this->assertSame(9, (int) $items[0]['ordered_qty']);
    }

    public function testUpdatePurchaseOrderAuditsBeforeAndAfter(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $result = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, 'v1', [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        updatePurchaseOrder($this->pdo, $result['id'], $supplier['id'], date('Y-m-d'), null, 'v2', [['product_id' => $product['id'], 'ordered_qty' => 2, 'unit_cost' => 1.00]], $userId);

        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'update'");
        $stmt->execute([$result['id']]);
        $row = $stmt->fetch();
        $before = json_decode($row['before_snapshot'], true);
        $after = json_decode($row['after_snapshot'], true);
        $this->assertSame('v1', $before['note']);
        $this->assertSame('v2', $after['note']);
    }

    public function testUpdatePurchaseOrderRejectsNonexistentId(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();

        $this->expectException(\PurchaseOrderNotFoundException::class);
        updatePurchaseOrder($this->pdo, 999999999, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);
    }

    public function testUpdatePurchaseOrderRejectsNonDraftStatusAndMutatesNothing(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $result = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, 'unchanged', [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        $this->pdo->exec("UPDATE purchase_orders SET status = 'ordered' WHERE id = " . $result['id']);

        try {
            updatePurchaseOrder($this->pdo, $result['id'], $supplier['id'], date('Y-m-d'), null, 'attempted change', [['product_id' => $product['id'], 'ordered_qty' => 99, 'unit_cost' => 99.00]], $userId);
            $this->fail('expected PurchaseOrderNotDraftException');
        } catch (\PurchaseOrderNotDraftException $e) {
            $this->assertSame('ordered', $e->status);
        }

        $stmt = $this->pdo->prepare('SELECT note FROM purchase_orders WHERE id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame('unchanged', $stmt->fetchColumn(), 'a rejected edit must leave the PO completely untouched');

        $stmt = $this->pdo->prepare('SELECT ordered_qty FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'items must be untouched too');
    }

    public function testUpdatePurchaseOrderNeverWritesStatusEvenIfSomehowInvoked(): void
    {
        // updatePurchaseOrder() has no $status parameter at all - this
        // test documents/proves that guarantee by reflection rather than
        // by data, since there is no way to even attempt passing one.
        $ref = new \ReflectionFunction('updatePurchaseOrder');
        $paramNames = array_map(fn($p) => $p->getName(), $ref->getParameters());
        $this->assertNotContains('status', $paramNames);
        $this->assertNotContains('receivedQty', $paramNames);
        $this->assertNotContains('received_qty', $paramNames);
    }

    // ---- Delete ----

    public function testDeletePurchaseOrderRemovesHeaderAndCascadesItems(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $result = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        deletePurchaseOrder($this->pdo, $result['id'], $userId);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'items must cascade-delete with the header');
    }

    public function testDeletePurchaseOrderAuditsWithFullBeforeSnapshot(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $result = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, 'to be deleted', [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        deletePurchaseOrder($this->pdo, $result['id'], $userId);

        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'purchase_order' AND entity_id = ? AND action = 'delete'");
        $stmt->execute([$result['id']]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertNull($row['after_snapshot']);
        $before = json_decode($row['before_snapshot'], true);
        $this->assertSame($result['reference'], $before['reference']);
        $this->assertSame('to be deleted', $before['note']);
    }

    public function testDeletePurchaseOrderRejectsNonexistentId(): void
    {
        $userId = $this->admin();
        $this->expectException(\PurchaseOrderNotFoundException::class);
        deletePurchaseOrder($this->pdo, 999999999, $userId);
    }

    public function testDeletePurchaseOrderRejectsNonDraftStatusAndDoesNotDelete(): void
    {
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        $result = createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);
        $this->pdo->exec("UPDATE purchase_orders SET status = 'received' WHERE id = " . $result['id']);

        try {
            deletePurchaseOrder($this->pdo, $result['id'], $userId);
            $this->fail('expected PurchaseOrderNotDraftException');
        } catch (\PurchaseOrderNotDraftException $e) {
            $this->assertSame('received', $e->status);
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE id = ?');
        $stmt->execute([$result['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'a non-draft PO must survive a rejected delete attempt');
    }

    public function testDeletingAProductWithPurchaseOrderHistoryIsRejectedByForeignKey(): void
    {
        // purchase_order_items.product_id has no ON DELETE clause
        // (RESTRICT by default), matching stock_transaction_items.
        // product_id exactly - confirms products with PO history can't
        // be deleted out from under it.
        $supplier = testSeedSupplier($this->pdo);
        $product = testSeedProduct($this->pdo);
        $userId = $this->admin();
        createPurchaseOrder($this->pdo, $supplier['id'], date('Y-m-d'), null, null, [['product_id' => $product['id'], 'ordered_qty' => 1, 'unit_cost' => 1.00]], $userId);

        $this->expectException(PDOException::class);
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
    }

    // ---- helpers ----

    private function referenceCounterValue(): int
    {
        return (int) $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'purchase_orders'")->fetchColumn();
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
