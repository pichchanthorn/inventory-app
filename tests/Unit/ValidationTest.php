<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Phase L1 (Low Stock Alert / Reorder Management) - pure-function tests
// for includes/validation.php::isNonNegativeIntegerString(). Originally
// written for K4-6-2's batch-specific Stock Adjustment (as a local
// function in stock-adjustment/index.php), extracted to
// includes/validation.php in this phase so product/index.php's
// min_stock/reorder_quantity validation reuses the exact same rule
// instead of a second, independently-written copy.
final class ValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/validation.php';
    }

    public function testZeroIsValid(): void
    {
        $this->assertTrue(isNonNegativeIntegerString('0'));
    }

    public function testPositiveIntegerIsValid(): void
    {
        $this->assertTrue(isNonNegativeIntegerString('42'));
    }

    public function testNegativeIntegerIsRejected(): void
    {
        $this->assertFalse(isNonNegativeIntegerString('-1'));
    }

    public function testDecimalIsRejectedNotTruncated(): void
    {
        // The whole point of this helper: (int) "5.7" would silently
        // become 5 - this must be rejected outright instead.
        $this->assertFalse(isNonNegativeIntegerString('5.7'));
    }

    public function testNonNumericStringIsRejected(): void
    {
        $this->assertFalse(isNonNegativeIntegerString('abc'));
    }

    public function testEmptyStringIsRejected(): void
    {
        $this->assertFalse(isNonNegativeIntegerString(''));
    }

    public function testLeadingWhitespaceIsRejected(): void
    {
        $this->assertFalse(isNonNegativeIntegerString(' 5'));
    }

    public function testTrailingWhitespaceIsRejected(): void
    {
        $this->assertFalse(isNonNegativeIntegerString('5 '));
    }

    public function testPlusSignIsRejected(): void
    {
        $this->assertFalse(isNonNegativeIntegerString('+5'));
    }

    public function testLeadingZeroIsStillAValidInteger(): void
    {
        $this->assertTrue(isNonNegativeIntegerString('05'));
    }
}
