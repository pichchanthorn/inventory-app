<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Formalizes the ad-hoc "array_diff(array_keys($en), array_keys($km))"
// check that has been run manually (never as a committed test) at the
// end of every phase since K4-6-1 - every user-facing string must exist
// in both lang/en.php and lang/km.php. No database/session state
// involved, so this does not extend Tests\TestCase.
final class LocalizationParityTest extends TestCase
{
    public function testEveryEnglishKeyHasAKhmerCounterpart(): void
    {
        $en = require dirname(__DIR__, 2) . '/lang/en.php';
        $km = require dirname(__DIR__, 2) . '/lang/km.php';

        $missingInKm = array_diff(array_keys($en), array_keys($km));
        $this->assertSame([], array_values($missingInKm), 'lang/km.php is missing keys present in lang/en.php: ' . implode(', ', $missingInKm));
    }

    public function testEveryKhmerKeyHasAnEnglishCounterpart(): void
    {
        $en = require dirname(__DIR__, 2) . '/lang/en.php';
        $km = require dirname(__DIR__, 2) . '/lang/km.php';

        $missingInEn = array_diff(array_keys($km), array_keys($en));
        $this->assertSame([], array_values($missingInEn), 'lang/en.php is missing keys present in lang/km.php: ' . implode(', ', $missingInEn));
    }

    public function testNoTranslationValueIsAnEmptyString(): void
    {
        // A present-but-blank key is a different bug than a missing key
        // (it passes the parity check above but still renders nothing) -
        // catches that class of mistake too.
        foreach (['en', 'km'] as $lang) {
            $table = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
            foreach ($table as $key => $value) {
                $this->assertNotSame('', $value, "lang/{$lang}.php key '{$key}' is an empty string");
            }
        }
    }
}
