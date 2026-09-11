<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Phase L1 (Low Stock Alert / Reorder Management) - pure-function tests
// for includes/stock_alert.php::lowStockTier(). No database or session
// state involved (same reasoning as CurrencyTest.php), so this does not
// extend Tests\TestCase.
final class LowStockTierTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/stock_alert.php';
    }

    public function testZeroStockAndZeroThresholdIsCritical(): void
    {
        $this->assertSame('critical', lowStockTier(0, 0));
    }

    public function testZeroStockAndPositiveThresholdIsCritical(): void
    {
        $this->assertSame('critical', lowStockTier(0, 5));
    }

    public function testStockBelowThresholdIsLow(): void
    {
        $this->assertSame('low', lowStockTier(1, 5));
    }

    public function testStockEqualToThresholdIsLow(): void
    {
        // Approved design: current_stock = min_stock is LOW (inclusive
        // boundary), matching the pre-existing current_stock <= min_stock
        // condition already shipped before this phase.
        $this->assertSame('low', lowStockTier(5, 5));
    }

    public function testStockAboveThresholdIsNormal(): void
    {
        $this->assertSame('normal', lowStockTier(6, 5));
    }

    public function testPositiveStockWithZeroThresholdIsNormal(): void
    {
        // min_stock = 0 does NOT disable alerting in general - it means
        // only current_stock = 0 can ever be flagged for that product.
        // Any positive stock with a zero threshold is NORMAL.
        $this->assertSame('normal', lowStockTier(1, 0));
    }

    public function testLargeStockFarAboveThresholdIsNormal(): void
    {
        $this->assertSame('normal', lowStockTier(500, 10));
    }

    public function testZeroStockIsAlwaysCriticalRegardlessOfHowHighTheThresholdIs(): void
    {
        $this->assertSame('critical', lowStockTier(0, 1000));
    }
}
