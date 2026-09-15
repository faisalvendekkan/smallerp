<?php

declare(strict_types=1);

namespace App\Core;

/**
 * English/Arabic interface strings.
 *
 * Arabic matters here: an SME in Doha will have Arabic-speaking staff, and a
 * printed invoice that a government department or a bank will accept is
 * expected to carry Arabic alongside English. Switching language also flips
 * the whole interface to right-to-left.
 *
 * Translations are a plain array rather than gettext so the app has no
 * extension requirements and a user can add a phrase without recompiling
 * anything.
 */
final class Lang
{
    private static string $locale = 'en';
    private static array $strings = [];

    public static function boot(string $fallback = 'en'): void
    {
        $locale = $_SESSION['locale'] ?? $fallback;
        self::setLocale(in_array($locale, ['en', 'ar'], true) ? $locale : 'en');
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
        $_SESSION['locale'] = self::$locale;

        $file = Config::root() . '/app/lang/' . self::$locale . '.php';
        self::$strings = is_file($file) ? (require $file) : [];
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function isRtl(): bool
    {
        return self::$locale === 'ar';
    }

    public static function dir(): string
    {
        return self::isRtl() ? 'rtl' : 'ltr';
    }

    /**
     * Translate a key, interpolating :placeholders.
     *
     * An unknown key falls back to a readable version of the key itself, so a
     * missing translation shows as "Credit limit" rather than a blank cell.
     */
    public static function get(string $key, array $replace = []): string
    {
        $value = self::$strings[$key] ?? self::humanise($key);
        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * Pick the Arabic or English column of a record, falling back to whichever
     * one is filled in. Used everywhere a contact or item has both names.
     */
    public static function pick(?array $row, string $base): string
    {
        if ($row === null) {
            return '';
        }
        $preferred = $base . '_' . self::$locale;
        $other = $base . '_' . (self::$locale === 'ar' ? 'en' : 'ar');

        $value = trim((string) ($row[$preferred] ?? ''));
        if ($value !== '') {
            return $value;
        }

        return trim((string) ($row[$other] ?? $row[$base] ?? ''));
    }

    private static function humanise(string $key): string
    {
        $tail = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return ucfirst(str_replace('_', ' ', $tail));
    }

    /** Format a date for display in the current locale. */
    public static function date(?string $date, bool $withTime = false): string
    {
        if (!$date) {
            return '—';
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return (string) $date;
        }

        return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
    }

    /** Month name for payroll periods and report headings. */
    public static function monthName(int $month): string
    {
        $en = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
        $ar = [
            1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
            'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
        ];

        return self::isRtl() ? ($ar[$month] ?? '') : ($en[$month] ?? '');
    }
}
