<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Document numbering.
 *
 * Numbers are allocated from a table row rather than from MAX(number)+1, so
 * two users saving an invoice at the same moment cannot be handed the same
 * number -- which, on a tax invoice, is the kind of thing that gets noticed
 * during an inspection.
 *
 * The default shape is PREFIX-YYYY-NNNN, e.g. INV-2026-0042, restarting each
 * year. Prefixes are configurable under Settings.
 */
final class Numbering
{
    public const DEFAULTS = [
        'sales_invoice' => ['prefix' => 'INV', 'padding' => 4, 'yearly' => true],
        'quotation' => ['prefix' => 'QTN', 'padding' => 4, 'yearly' => true],
        'purchase_bill' => ['prefix' => 'BILL', 'padding' => 4, 'yearly' => true],
        'receipt' => ['prefix' => 'RCT', 'padding' => 4, 'yearly' => true],
        'payment' => ['prefix' => 'PAY', 'padding' => 4, 'yearly' => true],
        'journal' => ['prefix' => 'JV', 'padding' => 4, 'yearly' => true],
        'contact' => ['prefix' => 'C', 'padding' => 4, 'yearly' => false],
        'supplier' => ['prefix' => 'S', 'padding' => 4, 'yearly' => false],
        'item' => ['prefix' => 'ITM', 'padding' => 4, 'yearly' => false],
        'employee' => ['prefix' => 'EMP', 'padding' => 4, 'yearly' => false],
    ];

    /**
     * Allocate the next number for a document type.
     *
     * @param string      $docType One of the keys in DEFAULTS.
     * @param string|null $date    The document date, which decides the year period.
     */
    public static function next(string $docType, ?string $date = null): string
    {
        $config = self::DEFAULTS[$docType] ?? ['prefix' => strtoupper(substr($docType, 0, 3)), 'padding' => 4, 'yearly' => true];
        $period = $config['yearly'] ? date('Y', strtotime($date ?? 'now')) : '';

        return Database::transaction(static function () use ($docType, $period, $config): string {
            $row = Database::first(
                'SELECT * FROM number_sequences WHERE doc_type = ? AND period = ?',
                [$docType, $period]
            );

            if ($row === null) {
                $prefix = self::configuredPrefix($docType, $config['prefix']);
                Database::insert('number_sequences', [
                    'doc_type' => $docType,
                    'period' => $period,
                    'prefix' => $prefix,
                    'next_value' => 2,
                    'padding' => $config['padding'],
                ]);
                $value = 1;
                $padding = (int) $config['padding'];
            } else {
                $prefix = (string) $row['prefix'];
                $value = (int) $row['next_value'];
                $padding = (int) $row['padding'];
                Database::update(
                    'number_sequences',
                    ['next_value' => $value + 1],
                    ['doc_type' => $docType, 'period' => $period]
                );
            }

            $parts = array_filter([$prefix, $period, str_pad((string) $value, $padding, '0', STR_PAD_LEFT)]);

            return implode('-', $parts);
        });
    }

    /** Look up a prefix the user has overridden under Settings. */
    private static function configuredPrefix(string $docType, string $fallback): string
    {
        $value = Database::value(
            'SELECT setting_value FROM settings WHERE setting_key = ?',
            ['numbering_' . $docType . '_prefix']
        );

        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Preview the next number without consuming it, for a "new document" form.
     */
    public static function preview(string $docType, ?string $date = null): string
    {
        $config = self::DEFAULTS[$docType] ?? ['prefix' => 'DOC', 'padding' => 4, 'yearly' => true];
        $period = $config['yearly'] ? date('Y', strtotime($date ?? 'now')) : '';
        $row = Database::first(
            'SELECT * FROM number_sequences WHERE doc_type = ? AND period = ?',
            [$docType, $period]
        );

        $prefix = $row['prefix'] ?? self::configuredPrefix($docType, $config['prefix']);
        $value = (int) ($row['next_value'] ?? 1);
        $padding = (int) ($row['padding'] ?? $config['padding']);
        $parts = array_filter([$prefix, $period, str_pad((string) $value, $padding, '0', STR_PAD_LEFT)]);

        return implode('-', $parts);
    }
}
