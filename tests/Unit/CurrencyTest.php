<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PriceConversionException;

// Pure-function tests for includes/currency.php::resolvePriceField() - no
// database or session state involved, so this does not extend
// Tests\TestCase (no transaction to wrap).
final class CurrencyTest extends TestCase
{
    public function testUsdAmountIsRoundedAndReturnedAsIs(): void
    {
        $usd = resolvePriceField(['price' => '12.345', 'price_currency' => 'USD'], 'price', 4100.0);
        $this->assertSame(12.35, $usd);
    }

    public function testKhrAmountIsConvertedUsingRate(): void
    {
        $usd = resolvePriceField(['price' => '41000', 'price_currency' => 'KHR'], 'price', 4100.0);
        $this->assertSame(10.0, $usd);
    }

    public function testMissingCurrencyFlagDefaultsToUsd(): void
    {
        $usd = resolvePriceField(['price' => '5'], 'price', 4100.0);
        $this->assertSame(5.0, $usd);
    }

    public function testNegativeUsdAmountThrows(): void
    {
        $this->expectException(PriceConversionException::class);
        resolvePriceField(['price' => '-1', 'price_currency' => 'USD'], 'price', 4100.0);
    }

    public function testNegativeKhrAmountThrows(): void
    {
        $this->expectException(PriceConversionException::class);
        resolvePriceField(['price' => '-1000', 'price_currency' => 'KHR'], 'price', 4100.0);
    }

    public function testKhrWithoutConfiguredRateThrows(): void
    {
        $this->expectException(PriceConversionException::class);
        resolvePriceField(['price' => '1000', 'price_currency' => 'KHR'], 'price', null);
    }

    public function testKhrWithZeroRateThrows(): void
    {
        $this->expectException(PriceConversionException::class);
        resolvePriceField(['price' => '1000', 'price_currency' => 'KHR'], 'price', 0.0);
    }

    public function testAmountThatRoundsToZeroFromNonzeroRawThrows(): void
    {
        // 3 Riel is a plausible "typed 3 instead of 3000" mistake - at a
        // realistic rate this rounds to $0.00, which resolvePriceField()
        // treats as a data-entry error rather than a valid free line.
        $this->expectException(PriceConversionException::class);
        resolvePriceField(['price' => '3', 'price_currency' => 'KHR'], 'price', 4100.0);
    }

    public function testZeroRawAmountIsValidFreeLine(): void
    {
        $usd = resolvePriceField(['price' => '0', 'price_currency' => 'USD'], 'price', 4100.0);
        $this->assertSame(0.0, $usd);
    }
}
