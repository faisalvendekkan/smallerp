<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Money;

/** Money parsing, rounding and the amount-in-words used on invoices. */
final class MoneyTest extends TestCase
{
    public function testParsesPlainDecimals(): void
    {
        $this->assertSame(0, Money::toDirhams('0'));
        $this->assertSame(100, Money::toDirhams('1'));
        $this->assertSame(150, Money::toDirhams('1.5'));
        $this->assertSame(150, Money::toDirhams('1.50'));
        $this->assertSame(123450, Money::toDirhams('1234.50'));
        $this->assertSame(-150, Money::toDirhams('-1.50'));
    }

    public function testParsesFormattedInput(): void
    {
        $this->assertSame(123450, Money::toDirhams('1,234.50'), 'thousands separator');
        $this->assertSame(123450, Money::toDirhams(' 1234.50 '), 'surrounding spaces');
        // An Arabic keyboard produces Arabic-Indic digits; they must parse.
        $this->assertSame(123450, Money::toDirhams('١٢٣٤.٥٠'), 'Arabic-Indic digits');
    }

    public function testRoundsHalfUpNotBankers(): void
    {
        // PHP's round() and many currency libraries round half-to-even, which
        // is not what an accountant in Doha expects.
        $this->assertSame(123451, Money::toDirhams('1234.505'));
        $this->assertSame(123452, Money::toDirhams('1234.515'));
        $this->assertSame(1, Money::toDirhams('0.005'));
        $this->assertSame(0, Money::toDirhams('0.004'));
    }

    public function testRejectsGarbage(): void
    {
        $this->assertThrows(static fn () => Money::toDirhams('abc'), 'not a valid amount');
        $this->assertThrows(static fn () => Money::toDirhams('1.2.3'), 'not a valid amount');
        $this->assertThrows(static fn () => Money::toDirhams(INF), 'finite');
    }

    public function testFormatsForDisplay(): void
    {
        $this->assertSame('1,234.50', Money::format(123450));
        $this->assertSame('QR 1,234.50', Money::format(123450, true));
        $this->assertSame('-1,234.50', Money::format(-123450));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('1234.50', Money::toDecimalString(123450));
        $this->assertSame('-1234.50', Money::toDecimalString(-123450));
    }

    public function testPercentageAndMultiply(): void
    {
        $this->assertSame(500, Money::percentage(10000, 5), '5% of 100.00');
        $this->assertSame(0, Money::percentage(10000, 0));
        $this->assertSame(30000, Money::multiply(10000, 3), '3 x 100.00');
        $this->assertSame(15000, Money::multiply(10000, 1.5));
        // 0.1 + 0.2 territory: this must not drift.
        $this->assertSame(3333, Money::multiply(1111, 3));
    }

    public function testAllocateSumsBackExactly(): void
    {
        $parts = Money::allocate(100, 3);
        $this->assertSame([34, 33, 33], $parts);
        $this->assertSame(100, array_sum($parts), 'allocation must sum back exactly');

        $negative = Money::allocate(-100, 3);
        $this->assertSame(-100, array_sum($negative));

        $this->assertSame(1000, array_sum(Money::allocate(1000, 7)));
    }

    public function testAmountInWordsEnglish(): void
    {
        $this->assertSame('Qatari Riyals Zero Only', Money::inWords(0));
        $this->assertSame('Qatari Riyals One Only', Money::inWords(100));
        $this->assertSame(
            'Qatari Riyals One Thousand Two Hundred and Thirty Four and Fifty Dirhams Only',
            Money::inWords(123450)
        );
        $this->assertSame(
            'Qatari Riyals One Million Only',
            Money::inWords(100000000)
        );
        $this->assertTrue(str_starts_with(Money::inWords(-5000), 'Minus'), 'negative prefix');
    }

    public function testAmountInWordsArabic(): void
    {
        $words = Money::inWords(123450, 'ar');
        $this->assertTrue(str_contains($words, 'ريال قطري'), 'names the currency');
        $this->assertTrue(str_contains($words, 'درهم'), 'names the subunit');
        $this->assertTrue(str_contains($words, 'فقط لا غير'), 'ends with the customary closing');
        // Arabic pluralises by count; two thousand is a dual form, not "2 alf".
        $this->assertTrue(str_contains(Money::inWords(200000, 'ar'), 'ألفان'), 'dual form for 2000');
    }
}
