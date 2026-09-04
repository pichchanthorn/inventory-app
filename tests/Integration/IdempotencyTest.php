<?php
declare(strict_types=1);

namespace Tests\Integration;

use IdempotencyConflictException;
use Tests\TestCase;

// P0 #10 (Idempotency / Duplicate Prevention). Sequential replay of the
// same token, as a genuine double-click or browser retry would produce
// (see tests/Concurrency/ConcurrencyTest.php for the *simultaneous*
// replay case).
final class IdempotencyTest extends TestCase
{
    public function testReplayingTheSameTokenOnStockOutDoesNotCreateASecondTransaction(): void
    {
        $product = testSeedProduct($this->pdo, 50);
        $userId = testSeedUserRole($this->pdo)['id'];
        $token = testRandomToken();

        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, $token);

        $this->expectException(IdempotencyConflictException::class);
        try {
            recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, $token);
        } finally {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE reference = '$reference'")->fetchColumn();
            $this->assertSame(1, $count, 'only the first submission may exist');
            $this->assertSame(45, $this->currentStock($product['id']), 'stock must be decremented exactly once');
        }
    }

    public function testReplayingTheSameTokenOnCreditSaleDoesNotCreateASecondSaleOrDebt(): void
    {
        $product = testSeedProduct($this->pdo, 50);
        $userId = testSeedUserRole($this->pdo)['id'];
        $token = testRandomToken();

        recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 2]], date('Y-m-d'), $userId, null, 'Idempotency Test Customer', '', '', $token);
        $debtCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn();
        $customerCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

        try {
            recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 2]], date('Y-m-d'), $userId, null, 'Idempotency Test Customer', '', '', $token);
            $this->fail('Expected IdempotencyConflictException was not thrown.');
        } catch (IdempotencyConflictException $e) {
            // expected
        }

        $this->assertSame($debtCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customer_debts')->fetchColumn());
        $this->assertSame($customerCountBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(), 'the inline new-customer insert must not repeat either');
        $this->assertSame(45, $this->currentStock($product['id']), 'stock must be decremented exactly once');
    }

    public function testADifferentTokenIsNotAffectedByAPreviousClaim(): void
    {
        $product = testSeedProduct($this->pdo, 50);
        $userId = testSeedUserRole($this->pdo)['id'];

        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, testRandomToken());
        // A second, genuinely different submission with its own token must succeed normally.
        recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 5, 'price' => 1]], date('Y-m-d'), '', $userId, 'out', null, testRandomToken());

        $this->assertSame(40, $this->currentStock($product['id']));
    }

    // ---- K2a: batch bookkeeping must be idempotent too ----

    public function testReplayingTheSameTokenOnStockInForATrackedProductDoesNotCreateASecondBatchOrAllocation(): void
    {
        $product = testSeedProduct($this->pdo, 0, ['track_batches' => 1]);
        $userId = testSeedUserRole($this->pdo)['id'];
        $token = testRandomToken();
        $line = ['product_id' => $product['id'], 'qty' => 5, 'cost' => 2, 'batch_number' => 'IDEMP-LOT', 'expiry_date' => '2027-01-01'];

        $reference = recordStockIn($this->pdo, [$line], date('Y-m-d'), null, '', $userId, $token);

        $batches = $this->batchesForProduct($product['id']);
        $this->assertCount(1, $batches, 'the first submission must create exactly one batch row');
        $this->assertSame(5, (int) $batches[0]['qty_on_hand']);
        $batchId = (int) $batches[0]['id'];

        $allocationCountBefore = $this->countAllocationsForBatch($batchId);
        $this->assertSame(1, $allocationCountBefore, 'the first submission must create exactly one allocation ledger row');

        $this->expectException(IdempotencyConflictException::class);
        try {
            recordStockIn($this->pdo, [$line], date('Y-m-d'), null, '', $userId, $token);
        } finally {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM stock_transactions WHERE reference = '$reference'")->fetchColumn();
            $this->assertSame(1, $count, 'only the first submission may exist');

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
            $stmt->execute([$product['id']]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), 'no second stock_transaction_items row');

            $batchesAfter = $this->batchesForProduct($product['id']);
            $this->assertCount(1, $batchesAfter, 'no second product_batches row');
            $this->assertSame(5, (int) $batchesAfter[0]['qty_on_hand'], 'batch qty must be unchanged by the rejected replay');
            $this->assertSame(5, (int) $batchesAfter[0]['qty_received']);

            $this->assertSame(1, $this->countAllocationsForBatch($batchId), 'no second allocation ledger row');
            $this->assertSame(5, $this->currentStock($product['id']), 'stock must be incremented exactly once');
        }
    }

    private function batchesForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    private function countAllocationsForBatch(int $batchId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_item_batches WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        return (int) $stmt->fetchColumn();
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }
}
