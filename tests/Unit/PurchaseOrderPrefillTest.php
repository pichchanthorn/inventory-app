<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Phase P3-B2: buildAssistedPoPrefill() (includes/purchase_order_prefill.php)
// is pure - it takes the supplier/product rows the page already loaded and
// a raw query map, and returns what the Purchase Order form should open
// with. No database, no superglobals, so every validation branch is
// directly testable here rather than only through rendered HTML.
//
// The rows below are shaped exactly like the SELECT * rows create.php
// passes in (string values, nullable supplier_id/reorder_quantity/
// cost_price), so these tests exercise the same types production does.
final class PurchaseOrderPrefillTest extends TestCase
{
    private const SUPPLIER_A = 7;
    private const SUPPLIER_B = 9;

    private function suppliers(): array
    {
        return [
            ['id' => self::SUPPLIER_A, 'name' => 'Supplier A'],
            ['id' => self::SUPPLIER_B, 'name' => 'Supplier B'],
        ];
    }

    private function product(int $id, ?int $supplierId, ?int $reorderQuantity, ?string $costPrice = '12.50'): array
    {
        return [
            'id' => (string) $id,
            'name' => "Product $id",
            'supplier_id' => $supplierId === null ? null : (string) $supplierId,
            'reorder_quantity' => $reorderQuantity === null ? null : (string) $reorderQuantity,
            'cost_price' => $costPrice,
        ];
    }

    private function products(): array
    {
        return [
            $this->product(1, self::SUPPLIER_A, 20),
            $this->product(2, self::SUPPLIER_A, 5),
            $this->product(3, self::SUPPLIER_B, 30),
            $this->product(4, null, 15),                       // no preferred supplier
            $this->product(5, self::SUPPLIER_A, null),          // reorder_quantity NULL
            $this->product(6, self::SUPPLIER_A, 0),             // reorder_quantity 0
            $this->product(7, self::SUPPLIER_A, -5),            // defensive: schema CHECK forbids this
            $this->product(8, self::SUPPLIER_A, 10, null),      // cost_price NULL
        ];
    }

    private function build(array $query, int $maxLines = 100): array
    {
        return buildAssistedPoPrefill($query, $this->suppliers(), $this->products(), $maxLines);
    }

    // ---- happy path ----

    public function testPrefillsASingleEligibleProduct(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['1']]);

        $this->assertSame(self::SUPPLIER_A, $result['supplier_id']);
        $this->assertSame(
            [['product_id' => 1, 'qty' => '20', 'cost' => '12.50']],
            $result['lines']
        );
    }

    public function testPrefillsMultipleProductsInTheOrderGiven(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['2', '1']]);

        $this->assertCount(2, $result['lines']);
        $this->assertSame(2, $result['lines'][0]['product_id']);
        $this->assertSame('5', $result['lines'][0]['qty']);
        $this->assertSame(1, $result['lines'][1]['product_id']);
        $this->assertSame('20', $result['lines'][1]['qty']);
    }

    public function testCostPrefillsFromTheProductsCurrentCostPrice(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['1']]);
        $this->assertSame('12.50', $result['lines'][0]['cost'], 'unit cost must default to the product cost_price');
    }

    public function testNullCostPriceYieldsABlankCostRatherThanTheStringNull(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['8']]);
        $this->assertSame('', $result['lines'][0]['cost']);
    }

    // ---- quantity rules ----

    public function testPositiveReorderQuantityIsPrefilled(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['1']]);
        $this->assertSame('20', $result['lines'][0]['qty']);
    }

    public function testNullReorderQuantityLeavesQuantityBlank(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['5']]);
        $this->assertCount(1, $result['lines'], 'the line is still suggested; only its quantity is blank');
        $this->assertSame('', $result['lines'][0]['qty']);
    }

    public function testZeroReorderQuantityLeavesQuantityBlank(): void
    {
        // 0 is a legitimately stored value, but create.php rejects a
        // non-positive ordered_qty - prefilling 0 would hand the user a
        // line guaranteed to fail validation.
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['6']]);
        $this->assertCount(1, $result['lines']);
        $this->assertSame('', $result['lines'][0]['qty']);
    }

    public function testNegativeReorderQuantityIsNeverTrusted(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['7']]);
        $this->assertCount(1, $result['lines']);
        $this->assertSame('', $result['lines'][0]['qty'], 'a negative quantity must never reach the form');
    }

    // ---- supplier validation ----

    public function testUnknownSupplierYieldsNothing(): void
    {
        $result = $this->build(['supplier_id' => '4242', 'product_id' => ['1']]);
        $this->assertSame(['supplier_id' => null, 'lines' => []], $result);
    }

    public function testNonNumericSupplierYieldsNothing(): void
    {
        $this->assertSame(['supplier_id' => null, 'lines' => []], $this->build(['supplier_id' => "7 OR 1=1", 'product_id' => ['1']]));
        $this->assertSame(['supplier_id' => null, 'lines' => []], $this->build(['supplier_id' => '-7', 'product_id' => ['1']]));
        $this->assertSame(['supplier_id' => null, 'lines' => []], $this->build(['supplier_id' => '', 'product_id' => ['1']]));
        $this->assertSame(['supplier_id' => null, 'lines' => []], $this->build(['supplier_id' => ['7'], 'product_id' => ['1']]));
    }

    public function testMissingSupplierYieldsNothing(): void
    {
        $this->assertSame(['supplier_id' => null, 'lines' => []], $this->build(['product_id' => ['1']]));
    }

    public function testValidSupplierWithNoEligibleProductsStillPreselectsTheSupplier(): void
    {
        $result = $this->build(['supplier_id' => '7']);
        $this->assertSame(self::SUPPLIER_A, $result['supplier_id']);
        $this->assertSame([], $result['lines']);
    }

    // ---- product / ownership validation (the core security property) ----

    public function testAProductBelongingToAnotherSupplierIsDropped(): void
    {
        // Product 3 is Supplier B's. Asking for it under Supplier A must
        // never produce a suggested line.
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['1', '3']]);

        $this->assertSame(self::SUPPLIER_A, $result['supplier_id']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(1, $result['lines'][0]['product_id'], 'only the supplier-owned product may survive');
    }

    public function testAProductWithNoSupplierIsDropped(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['4']]);
        $this->assertSame([], $result['lines']);
    }

    public function testUnknownProductIdIsDropped(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['999999', '1']]);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(1, $result['lines'][0]['product_id']);
    }

    public function testNonNumericAndNestedProductIdsAreDropped(): void
    {
        $result = $this->build([
            'supplier_id' => '7',
            'product_id' => ['abc', '', '-1', '1.5', ['1'], '1'],
        ]);
        $this->assertCount(1, $result['lines'], 'only the one well-formed id may survive');
        $this->assertSame(1, $result['lines'][0]['product_id']);
    }

    public function testDuplicateProductIdsCollapseToOneLine(): void
    {
        $result = $this->build(['supplier_id' => '7', 'product_id' => ['1', '1', '1']]);
        $this->assertCount(1, $result['lines']);
    }

    public function testAScalarProductIdIsAccepted(): void
    {
        // ?product_id=1 (not product_id[]=1) still arrives as a string.
        $result = $this->build(['supplier_id' => '7', 'product_id' => '1']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(1, $result['lines'][0]['product_id']);
    }

    public function testMissingProductIdParameterYieldsNoLines(): void
    {
        $result = $this->build(['supplier_id' => '7']);
        $this->assertSame([], $result['lines']);
    }

    public function testLineCountIsCapped(): void
    {
        $query = ['supplier_id' => '7', 'product_id' => ['1', '2', '5', '6']];
        $result = $this->build($query, 2);
        $this->assertCount(2, $result['lines'], 'a hand-crafted URL must not be able to prefill unbounded rows');
    }
}
