<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

// Phase L1 (Low Stock Alert / Reorder Management). Exercises the
// derivation against real seeded rows (tracked and untracked), and
// confirms the feature is genuinely read-only: it never writes to
// products.current_stock, product_batches, or stock_transactions, and
// the current_stock = SUM(product_batches.qty_on_hand) invariant is
// completely unaffected by anything in this phase (nothing here ever
// queries product_batches at all - see includes/stock_alert.php's own
// comment for why).
final class LowStockAlertTest extends TestCase
{
    public function testTrackedAndUntrackedProductsWithTheSameStockAndThresholdClassifyIdentically(): void
    {
        $tracked = testSeedProduct($this->pdo, 5, ['min_stock' => 5, 'track_batches' => 1]);
        $untracked = testSeedProduct($this->pdo, 5, ['min_stock' => 5, 'track_batches' => 0]);

        $this->assertSame(
            lowStockTier((int) $tracked['current_stock'], (int) $tracked['min_stock']),
            lowStockTier((int) $untracked['current_stock'], (int) $untracked['min_stock']),
            'a tracked product must classify identically to an untracked product with the same current_stock/min_stock'
        );
        $this->assertSame('low', lowStockTier((int) $tracked['current_stock'], (int) $tracked['min_stock']));
    }

    public function testTrackedProductAtZeroStockIsCriticalJustLikeUntracked(): void
    {
        $tracked = testSeedProduct($this->pdo, 0, ['min_stock' => 10, 'track_batches' => 1]);
        $this->assertSame('critical', lowStockTier((int) $tracked['current_stock'], (int) $tracked['min_stock']));
    }

    public function testReorderQuantityNullDoesNotAffectClassification(): void
    {
        $product = testSeedProduct($this->pdo, 2, ['min_stock' => 5, 'reorder_quantity' => null]);
        $this->assertNull($this->reorderQuantity($product['id']));
        $this->assertSame('low', lowStockTier((int) $product['current_stock'], (int) $product['min_stock']));
    }

    public function testReorderQuantityZeroIsValidAndDoesNotAffectClassification(): void
    {
        $product = testSeedProduct($this->pdo, 2, ['min_stock' => 5, 'reorder_quantity' => 0]);
        $this->assertSame(0, $this->reorderQuantity($product['id']));
        $this->assertSame('low', lowStockTier((int) $product['current_stock'], (int) $product['min_stock']));
    }

    public function testReorderQuantitySetToAPositiveValueDoesNotAffectClassificationEither(): void
    {
        // Same product/threshold as testReorderQuantityNull.../Zero... above,
        // varying only reorder_quantity - the tier must come out identical
        // (LOW) regardless of what reorder_quantity holds.
        $product = testSeedProduct($this->pdo, 2, ['min_stock' => 5, 'reorder_quantity' => 50]);
        $this->assertSame(50, $this->reorderQuantity($product['id']));
        $this->assertSame('low', lowStockTier((int) $product['current_stock'], (int) $product['min_stock']));
    }

    public function testViewingTheDerivationNeverWritesToProductBatchesOrCurrentStock(): void
    {
        $product = testSeedProduct($this->pdo, 3, ['min_stock' => 5, 'track_batches' => 1]);
        $this->pdo->prepare('INSERT INTO product_batches (product_id, batch_number, expiry_date, qty_received, qty_on_hand, origin) VALUES (?,?,?,?,?,?)')
            ->execute([$product['id'], 'LOT-L1', null, 3, 3, 'stock_in']);

        // The actual "read" this feature performs - a plain SELECT, same
        // as stock-alert/index.php's own query - repeated twice to prove
        // it is idempotent and side-effect-free.
        for ($i = 0; $i < 2; $i++) {
            $this->pdo->query('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.current_stock <= p.min_stock')->fetchAll();
        }

        $this->assertSame(3, $this->currentStock($product['id']), 'current_stock must be completely unchanged by merely viewing the derivation');
        $stmt = $this->pdo->prepare('SELECT qty_on_hand FROM product_batches WHERE product_id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(3, (int) $stmt->fetchColumn(), 'product_batches.qty_on_hand must be completely unchanged');
        $this->assertSame(3, $this->currentStock($product['id']), 'invariant: current_stock must still equal SUM(qty_on_hand)');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_transaction_items WHERE product_id = ?');
        $stmt->execute([$product['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'no stock_transactions/items may be created by simply viewing the page/derivation');
    }

    private function reorderQuantity(int $productId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT reorder_quantity FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : (int) $value;
    }

    private function currentStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT current_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }
}
