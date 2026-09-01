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

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }
}
