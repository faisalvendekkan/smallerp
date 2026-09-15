<?php

declare(strict_types=1);

namespace App\Support;

/** Small string helpers shared across the app, including Arabic digit handling. */
final class Text
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    private const EXTENDED_ARABIC_INDIC = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** Convert Arabic-Indic digits to 0-9 so Arabic keyboard input parses. */
    public static function westernDigits(string $value): string
    {
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace(
            array_merge(self::ARABIC_INDIC, self::EXTENDED_ARABIC_INDIC),
            array_merge($western, $western),
            $value
        );
    }

    /** Keep only digits -- used for QIDs, CR numbers and phone numbers. */
    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', self::westernDigits((string) $value)) ?? '';
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value) ?? '';

        return trim(mb_strtolower($value), $separator);
    }

    public static function truncate(string $value, int $length, string $suffix = '…'): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . $suffix;
    }

    /** True if the string contains Arabic script, used to pick text direction. */
    public static function isArabic(string $value): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $value);
    }

    /** Escape for HTML output. Views use this via the e() helper. */
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Wrap a value for a CSV cell, neutralising spreadsheet formula injection. */
    public static function csvCell(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'" . $value;
        }

        return $value;
    }
}
