<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

// P0 #9 (POS cash sale). Exercises recordStockOut(..., type: 'sale', ...)
// - the exact function pos/index.php's cash-sale path calls.
final class SalesTest extends TestCase
{
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
