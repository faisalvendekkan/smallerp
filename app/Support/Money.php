<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money handling for SmallERP.
 *
 * Every monetary amount is stored and passed around as an integer number of
 * dirhams (1 QAR = 100 dirhams). Floats are never used for money: a ledger
 * that is out by 0.01 because of binary rounding is a ledger nobody trusts.
 *
 * Rounding is half-up, which is what accountants in Qatar expect, rather than
 * PHP's default round-half-away behaviour on negative numbers.
 */
final class Money
{
    public const CURRENCY = 'QAR';
    public const SYMBOL = 'QR';

    /** Parse user input (string, int or float) into integer dirhams. */
    public static function toDirhams(mixed $value, string $field = 'amount'): int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException("{$field} must be a finite number");
            }
            return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
        }
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} is not a valid amount");
        }

        // Accept 1,234.50 and the Arabic thousands separator, plus Arabic-Indic digits.
        $clean = str_replace([',', "\u{066C}", ' ', "\u{00A0}"], '', trim($value));
        $clean = Text::westernDigits($clean);
        $clean = str_replace("\u{066B}", '.', $clean);

        if (!preg_match('/^[+-]?\d*(\.\d*)?$/', $clean) || $clean === '' || $clean === '+' || $clean === '-') {
            throw new \InvalidArgumentException("{$field} is not a valid amount: {$value}");
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '+-');
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;

        // Round the third decimal place half-up without ever touching a float.
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $dirhams = (int) $whole * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $dirhams++;
        }

        return $negative ? -$dirhams : $dirhams;
    }

    /** Render integer dirhams as a plain decimal string, e.g. "1234.50". */
    public static function toDecimalString(int $dirhams): string
    {
        $negative = $dirhams < 0;
        $abs = abs($dirhams);

        return ($negative ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Render integer dirhams for display, e.g. "1,234.50" or "QR 1,234.50". */
    public static function format(int $dirhams, bool $withSymbol = false): string
    {
        $negative = $dirhams < 0;
        $abs = abs($dirhams);
        $body = number_format(intdiv($abs, 100), 0, '.', ',')
            . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        $out = ($negative ? '-' : '') . $body;

        return $withSymbol ? self::SYMBOL . ' ' . $out : $out;
    }

    /**
     * Apply a percentage rate to an amount, rounded half-up.
     *
     * @param float|string $ratePercent whole percent, so 5 means 5%
     */
    public static function percentage(int $dirhams, float|string $ratePercent): int
    {
        $rate = (float) $ratePercent;

        return self::roundHalfUp($dirhams * $rate / 100);
    }

    /** Multiply an amount by a quantity that may have fractional units. */
    public static function multiply(int $dirhams, float|string $quantity): int
    {
        return self::roundHalfUp($dirhams * (float) $quantity);
    }

    /** Round a scaled value half-up, away from zero, to whole dirhams. */
    public static function roundHalfUp(float $value): int
    {
        return (int) ($value < 0 ? -floor(abs($value) + 0.5) : floor($value + 0.5));
    }

    /**
     * Split an amount into $parts whole dirham amounts that sum back exactly.
     *
     * Used when a discount or rounding difference has to be spread over lines
     * without the total drifting.
     *
     * @return int[]
     */
    public static function allocate(int $dirhams, int $parts): array
    {
        if ($parts <= 0) {
            throw new \InvalidArgumentException('parts must be positive');
        }
        $sign = $dirhams < 0 ? -1 : 1;
        $abs = abs($dirhams);
        $base = intdiv($abs, $parts);
        $remainder = $abs % $parts;

        $out = array_fill(0, $parts, $sign * $base);
        for ($i = 0; $i < $remainder; $i++) {
            $out[$i] += $sign;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Amount in words -- printed on invoices and required on cheques.
    // ------------------------------------------------------------------

    private const ONES_EN = [
        'Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];
    private const TENS_EN = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    private const ONES_AR = [
        'صفر', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية',
        'تسعة', 'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر',
        'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر',
    ];
    private const TENS_AR = [
        '', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون',
    ];
    private const HUNDREDS_AR = [
        '', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة',
    ];

    private static function intToWordsEn(int $n): string
    {
        if ($n < 20) {
            return self::ONES_EN[$n];
        }
        if ($n < 100) {
            $tens = intdiv($n, 10);
            $ones = $n % 10;

            return self::TENS_EN[$tens] . ($ones ? ' ' . self::ONES_EN[$ones] : '');
        }
        if ($n < 1000) {
            $head = self::ONES_EN[intdiv($n, 100)] . ' Hundred';
            $rest = $n % 100;

            return $rest ? $head . ' and ' . self::intToWordsEn($rest) : $head;
        }
        foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'] as $scale => $name) {
            if ($n >= $scale) {
                $head = self::intToWordsEn(intdiv($n, $scale)) . ' ' . $name;
                $rest = $n % $scale;
                if ($rest === 0) {
                    return $head;
                }

                return $head . ($rest < 100 ? ' and ' : ' ') . self::intToWordsEn($rest);
            }
        }

        return (string) $n;
    }

    private static function intToWordsAr(int $n): string
    {
        if ($n < 20) {
            return self::ONES_AR[$n];
        }
        if ($n < 100) {
            $tens = intdiv($n, 10);
            $ones = $n % 10;

            return $ones ? self::ONES_AR[$ones] . ' و' . self::TENS_AR[$tens] : self::TENS_AR[$tens];
        }
        if ($n < 1000) {
            $head = self::HUNDREDS_AR[intdiv($n, 100)];
            $rest = $n % 100;

            return $rest ? $head . ' و' . self::intToWordsAr($rest) : $head;
        }
        // Arabic pluralises by count: one, two, 3-10, then back to the singular.
        $scales = [
            1000000000 => ['مليار', 'ملياران', 'مليارات'],
            1000000    => ['مليون', 'مليونان', 'ملايين'],
            1000       => ['ألف', 'ألفان', 'آلاف'],
        ];
        foreach ($scales as $scale => [$single, $dual, $plural]) {
            if ($n >= $scale) {
                $count = intdiv($n, $scale);
                $rest = $n % $scale;
                $head = match (true) {
                    $count === 1 => $single,
                    $count === 2 => $dual,
                    $count <= 10 => self::intToWordsAr($count) . ' ' . $plural,
                    default      => self::intToWordsAr($count) . ' ' . $single,
                };

                return $rest ? $head . ' و' . self::intToWordsAr($rest) : $head;
            }
        }

        return (string) $n;
    }

    /** Spell out an amount, e.g. for the "Amount in words" line of an invoice. */
    public static function inWords(int $dirhams, string $lang = 'en'): string
    {
        $negative = $dirhams < 0;
        $abs = abs($dirhams);
        $riyals = intdiv($abs, 100);
        $subunits = $abs % 100;

        if ($lang === 'ar') {
            $text = self::intToWordsAr($riyals) . ' ريال قطري';
            if ($subunits > 0) {
                $text .= ' و' . self::intToWordsAr($subunits) . ' درهم';
            }
            $text .= ' فقط لا غير';

            return $negative ? 'سالب ' . $text : $text;
        }

        $text = 'Qatari Riyals ' . self::intToWordsEn($riyals);
        if ($subunits > 0) {
            $text .= ' and ' . self::intToWordsEn($subunits) . ' Dirhams';
        }
        $text .= ' Only';

        return $negative ? 'Minus ' . $text : $text;
    }
}
