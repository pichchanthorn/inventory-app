<?php
declare(strict_types=1);

namespace Tests\Integration;

use BatchConsumptionRequiredException;
use Tests\TestCase;

// P0 #9 (POS cash sale). Exercises recordStockOut(..., type: 'sale', ...)
// - the exact function pos/index.php's cash-sale path calls.
final class SalesTest extends TestCase
{
    // ---- K3-1: POS safety gate for track_batches=1 products ----
    //
    // pos/index.php's cash-sale call site is NOT modified by K3-1 - it
    // still calls recordStockOut() without a $consumeBatches argument,
    // which defaults to false. These tests exercise that exact,
    // unmodified call shape directly, confirming the new safety gate in
    // insertStockOutLines() (includes/stock.php) rejects a tracked
    // product cleanly rather than letting POS silently decrement
    // current_stock with no corresponding batch decrement.

    public function testCashSaleOfATrackedProductIsRejectedWithoutMutatingAnything(): void
    {
        $product = testSeedProduct($this->pdo, 20, ['track_batches' => 1]);
        $userId = testSeedUserRole($this->pdo)['id'];
        $stmt = $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand) VALUES (?,?,?,?,?)');
        $stmt->execute([$product['id'], 'LOT-POS', '2027-01-01', 20, 20]);
        $batchId = (int) $this->pdo->lastInsertId();
        $countBefore = $this->countRows('stock_transactions');
        $refCounterBefore = $this->referenceCounterValue();
        $token = testRandomToken();

        try {
            // Exactly pos/index.php's real, unmodified call shape - no
            // $consumeBatches argument, so it uses the safe default.
            recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 3, 'price' => 5.00]], date('Y-m-d'), '', $userId, 'sale', 20.00, $token);
            $this->fail('Expected BatchConsumptionRequiredException was not thrown.');
        } catch (BatchConsumptionRequiredException $e) {
            $this->assertSame($product['id'], $e->productId);
        }

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(20, (int) $stmt->fetchColumn(), 'current_stock must be unchanged after a rejected sale');

        $stmt = $this->pdo->prepare('SELECT qty_on_hand FROM product_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        $this->assertSame(20, (int) $stmt->fetchColumn(), 'batch qty_on_hand must be unchanged after a rejected sale');

        $this->assertSame($countBefore, $this->countRows('stock_transactions'), 'no stock_transactions row must survive');
        $this->assertSame(0, $this->countItemsForProduct($product['id']), 'no stock_transaction_items row must survive');
        $this->assertSame(0, $this->countAllocationsForBatch($batchId), 'no allocation ledger row must survive');
        $this->assertSame($refCounterBefore, $this->referenceCounterValue(), 'the reference counter must be rolled back');

        // The idempotency claim must have rolled back too - the same
        // token must still be usable, here against a plain untracked
        // product, proving the claim was genuinely released.
        $untracked = testSeedProduct($this->pdo, 10);
        $reference = recordStockOut($this->pdo, [['product_id' => $untracked['id'], 'qty' => 2, 'price' => 1.00]], date('Y-m-d'), '', $userId, 'sale', 5.00, $token);
        $this->assertStringStartsWith('SAL-', $reference);
    }

    public function testCashSaleOfAnUntrackedProductRemainsUnaffectedByTheSafetyGate(): void
    {
        // Regression: the new, unconditional per-line track_batches read
        // must be a no-op for the overwhelmingly common case - no
        // BatchConsumptionRequiredException, no behavior change at all.
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 3, 'price' => 5.00]], date('Y-m-d'), '', $userId, 'sale', 20.00);

        $this->assertStringStartsWith('SAL-', $reference);
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(17, (int) $stmt->fetchColumn());
    }

    private function countItemsForProduct(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    private function countAllocationsForBatch(int $batchId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_item_batches WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    private function referenceCounterValue(): int
    {
        return (int) $this->pdo->query("SELECT next_value FROM reference_counters WHERE counter_key = 'stock_transactions'")->fetchColumn();
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    public function testCashSaleIsCreatedWithCorrectTypeAndReference(): void
    {
        $product = testSeedProduct($this->pdo, 20, ['sale_price' => 5.00]);
        $userId = testSeedUserRole($this->pdo)['id'];

        $reference = recordStockOut(
            $this->pdo,
            [['product_id' => $product['id'], 'qty' => 3, 'price' => 5.00]],
            date('Y-m-d'),
            '',
            $userId,
            'sale',
            20.00
        );

        $this->assertStringStartsWith('SAL-', $reference);
        $stmt = $this->pdo->prepare("SELECT type FROM stock_transactions WHERE reference = ?");
        $stmt->execute([$reference]);
        $this->assertSame('sale', $stmt->fetchColumn());
    }

    public function testCashSaleDecrementsStockCorrectly(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 3, 'price' => 5.00]], date('Y-m-d'), '', $userId, 'sale', 20.00);

        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(17, (int) $stmt->fetchColumn());
    }

    public function testCashSaleTotalIsCorrectAcrossMultipleLines(): void
    {
        $p1 = testSeedProduct($this->pdo, 20);
        $p2 = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $reference = recordStockOut(
            $this->pdo,
            [
                ['product_id' => $p1['id'], 'qty' => 2, 'price' => 5.00],  // 10.00
                ['product_id' => $p2['id'], 'qty' => 3, 'price' => 2.50],  // 7.50
            ],
            date('Y-m-d'),
            '',
            $userId,
            'sale',
            20.00
        );

        $stmt = $this->pdo->prepare('SELECT SUM(subtotal) FROM stock_transaction_items i JOIN stock_transactions t ON t.id = i.transaction_id WHERE t.reference = ?');
        $stmt->execute([$reference]);
        $this->assertEqualsWithDelta(17.50, (float) $stmt->fetchColumn(), 0.001);
    }

    public function testCashReceivedIsPersistedForASale(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 2, 'price' => 5.00]], date('Y-m-d'), '', $userId, 'sale', 20.00);

        $stmt = $this->pdo->prepare('SELECT cash_received FROM stock_transactions WHERE reference = ?');
        $stmt->execute([$reference]);
        $this->assertEqualsWithDelta(20.00, (float) $stmt->fetchColumn(), 0.001);
    }

    public function testCashReceivedIsNullForANonSaleStockOut(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        // Stock Out's own call site never passes cash_received - confirm
        // the column stays NULL (not $0.00) for a plain 'out' movement.
        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 2, 'price' => 5.00]], date('Y-m-d'), 'damaged', $userId);

        $stmt = $this->pdo->prepare('SELECT cash_received FROM stock_transactions WHERE reference = ?');
        $stmt->execute([$reference]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testChangeDueIsDerivedCorrectlyFromCashReceivedAndTotal(): void
    {
        $product = testSeedProduct($this->pdo, 20);
        $userId = testSeedUserRole($this->pdo)['id'];

        // total = 3 * 5.00 = 15.00, cash received = 20.00 -> change = 5.00.
        // change_due is deliberately not stored anywhere (see
        // includes/stock.php's own comment) - it is derived the same way
        // the app derives it at read time, from the persisted total and
        // cash_received.
        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 3, 'price' => 5.00]], date('Y-m-d'), '', $userId, 'sale', 20.00);

        $stmt = $this->pdo->prepare('SELECT t.cash_received, SUM(i.subtotal) AS total
                                       FROM stock_transactions t
                                       JOIN stock_transaction_items i ON i.transaction_id = t.id
                                       WHERE t.reference = ? GROUP BY t.id');
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        $changeDue = (float) $row['cash_received'] - (float) $row['total'];

        $this->assertEqualsWithDelta(5.00, $changeDue, 0.001);
    }
}
