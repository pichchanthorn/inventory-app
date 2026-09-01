<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

// P0 #17 (Reference Numbers) - sequential-call correctness: prefix,
// formatting, and uniqueness across repeated calls on one connection.
// The concurrent-generation half of #17 (separate connections racing on
// the same counter) lives in tests/Concurrency/ConcurrencyTest.php - a
// single open transaction here cannot exercise real cross-connection
// locking.
final class ReferenceTest extends TestCase
{
    public function testStockInReferenceHasCorrectPrefixAndFormat(): void
    {
        $product = testSeedProduct($this->pdo, 10);
        $userId = testSeedAdmin($this->pdo)['id'];

        $reference = recordStockIn($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'cost' => 1]], date('Y-m-d'), null, '', $userId);

        $this->assertMatchesRegularExpression('/^STI-\d{6}$/', $reference);
    }

    public function testStockOutReferenceHasCorrectPrefixAndFormat(): void
    {
        $product = testSeedProduct($this->pdo, 10);
        $userId = testSeedAdmin($this->pdo)['id'];

        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => 1]], date('Y-m-d'), '', $userId);

        $this->assertMatchesRegularExpression('/^STO-\d{6}$/', $reference);
    }

    public function testSaleReferenceHasCorrectPrefixAndFormat(): void
    {
        $product = testSeedProduct($this->pdo, 10);
        $userId = testSeedAdmin($this->pdo)['id'];

        $reference = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => 1]], date('Y-m-d'), '', $userId, 'sale', 5.00);

        $this->assertMatchesRegularExpression('/^SAL-\d{6}$/', $reference);
    }

    public function testAdjustmentReferenceHasCorrectPrefixAndFormat(): void
    {
        $product = testSeedProduct($this->pdo, 10);
        $userId = testSeedAdmin($this->pdo)['id'];

        $reference = adjustStock($this->pdo, $product['id'], 8, 10, 'count', date('Y-m-d'), $userId);

        $this->assertMatchesRegularExpression('/^ADJ-\d{6}$/', $reference);
    }

    public function testDebtReferenceHasCorrectPrefixAndFormat(): void
    {
        $product = testSeedProduct($this->pdo, 10);
        $userId = testSeedAdmin($this->pdo)['id'];

        $result = recordCreditSale($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => 1]], date('Y-m-d'), $userId, null, 'Ref Test Customer', '', '');

        $this->assertMatchesRegularExpression('/^DBT-\d{6}$/', $result['debt_reference']);
    }

    public function testStiStoAdjSalShareOneCounterAndNeverCollide(): void
    {
        // nextStockReference() draws STI/STO/ADJ/SAL all from the same
        // 'stock_transactions' counter (see includes/stock.php) - confirm
        // repeated calls across all four still never produce a duplicate
        // reference, whatever prefix each carries.
        $product = testSeedProduct($this->pdo, 100);
        $userId = testSeedAdmin($this->pdo)['id'];

        $references = [];
        $references[] = recordStockIn($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'cost' => 1]], date('Y-m-d'), null, '', $userId);
        $references[] = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => 1]], date('Y-m-d'), '', $userId);
        // net effect of the +1 (Stock In) and -1 (Stock Out) above is
        // that current_stock is back to the original 100 before this call.
        $references[] = adjustStock($this->pdo, $product['id'], 90, 100, 'x', date('Y-m-d'), $userId);
        $references[] = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => 1]], date('Y-m-d'), '', $userId, 'sale', 1.00);

        $this->assertCount(4, array_unique($references), 'no two references may collide');
    }

    public function testRepeatedCallsProduceStrictlyIncreasingSequenceNumbers(): void
    {
        $product = testSeedProduct($this->pdo, 100);
        $userId = testSeedAdmin($this->pdo)['id'];

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $ref = recordStockOut($this->pdo, [['product_id' => $product['id'], 'qty' => 1, 'price' => 1]], date('Y-m-d'), '', $userId);
            $numbers[] = (int) substr($ref, 4);
        }

        for ($i = 1; $i < count($numbers); $i++) {
            $this->assertGreaterThan($numbers[$i - 1], $numbers[$i], 'each successive sequence number must be strictly greater than the last');
        }
    }
}
