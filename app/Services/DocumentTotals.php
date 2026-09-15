<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Money;
use App\Support\ValidationException;

/**
 * Line and document arithmetic, shared by quotations, invoices and bills.
 *
 * All of it happens in integer dirhams, and tax is computed per line rather
 * than on the document total, so a document with a mix of rates -- which is
 * what will happen the day Qatar switches VAT on -- still ties exactly.
 */
final class DocumentTotals
{
    /**
     * Compute one line.
     *
     * @return array{quantity:float,unit_price:int,discount_pct:float,tax_rate:float,line_subtotal:int,line_discount:int,line_tax:int,line_total:int}
     */
    public static function line(
        float $quantity,
        int $unitPrice,
        float $discountPct = 0.0,
        float $taxRate = 0.0,
        bool $priceIncludesTax = false
    ): array {
        if ($quantity < 0) {
            throw new ValidationException('Quantity cannot be negative');
        }
        if ($unitPrice < 0) {
            throw new ValidationException('Unit price cannot be negative');
        }
        if ($discountPct < 0 || $discountPct > 100) {
            throw new ValidationException('Discount must be between 0 and 100 percent');
        }
        if ($taxRate < 0 || $taxRate > 100) {
            throw new ValidationException('Tax rate must be between 0 and 100 percent');
        }

        $gross = Money::multiply($unitPrice, $quantity);
        $discount = Money::percentage($gross, $discountPct);
        $net = $gross - $discount;

        if ($priceIncludesTax && $taxRate > 0) {
            // The entered price already contains the tax, so extract it rather
            // than adding it on top.
            $taxable = Money::roundHalfUp($net / (1 + $taxRate / 100));
            $tax = $net - $taxable;
            $net = $taxable;
        } else {
            $tax = Money::percentage($net, $taxRate);
        }

        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_pct' => $discountPct,
            'tax_rate' => $taxRate,
            'line_subtotal' => $net,
            'line_discount' => $discount,
            'line_tax' => $tax,
            'line_total' => $net + $tax,
        ];
    }

    /**
     * Roll a set of computed lines into document totals.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array{subtotal:int,discount_total:int,tax_total:int,total:int}
     */
    public static function document(array $lines): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;

        foreach ($lines as $line) {
            $subtotal += (int) $line['line_subtotal'];
            $discount += (int) ($line['line_discount'] ?? 0);
            $tax += (int) $line['line_tax'];
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => $subtotal + $tax,
        ];
    }

    /**
     * Tax broken down by rate, which is what a VAT return needs.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,array{rate:float,taxable:int,tax:int}>
     */
    public static function taxBreakdown(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $rate = (float) ($line['tax_rate'] ?? 0);
            $key = number_format($rate, 2, '.', '');
            $groups[$key] ??= ['rate' => $rate, 'taxable' => 0, 'tax' => 0];
            $groups[$key]['taxable'] += (int) $line['line_subtotal'];
            $groups[$key]['tax'] += (int) $line['line_tax'];
        }
        krsort($groups);

        return $groups;
    }

    /**
     * Turn raw posted line rows into validated, computed lines.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function prepareLines(array $rows, bool $priceIncludesTax = false): array
    {
        $lines = [];
        $lineNo = 0;

        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $itemId = (int) ($row['item_id'] ?? 0) ?: null;
            $quantity = (float) str_replace(',', '', (string) ($row['quantity'] ?? 0));

            // Skip the blank rows a line-item form always posts.
            if ($itemId === null && $description === '' && $quantity == 0.0) {
                continue;
            }
            if ($description === '' && $itemId === null) {
                throw new ValidationException('Every line needs an item or a description');
            }
            if ($quantity <= 0) {
                throw new ValidationException(
                    sprintf('Quantity must be more than zero on line "%s"', $description ?: '#' . ($lineNo + 1))
                );
            }

            $computed = self::line(
                $quantity,
                Money::toDirhams($row['unit_price'] ?? 0, 'unit price'),
                (float) ($row['discount_pct'] ?? 0),
                (float) ($row['tax_rate'] ?? 0),
                $priceIncludesTax
            );

            // A blank unit falls back to the item's own, so picking "Annual
            // maintenance contract" does not silently become "PCS".
            $uom = mb_substr(trim((string) ($row['uom'] ?? '')), 0, 20);
            if ($uom === '' && $itemId !== null) {
                $uom = self::itemUom($itemId);
            }

            $lines[] = $computed + [
                'item_id' => $itemId,
                'description' => mb_substr($description, 0, 255),
                'uom' => $uom ?: 'PCS',
                'account_id' => (int) ($row['account_id'] ?? 0) ?: null,
                'line_no' => ++$lineNo,
            ];
        }

        if ($lines === []) {
            throw new ValidationException('Add at least one line before saving');
        }

        return $lines;
    }

    /** @var array<int,string> Per-request cache of item units. */
    private static array $uomCache = [];

    private static function itemUom(int $itemId): string
    {
        if (!isset(self::$uomCache[$itemId])) {
            self::$uomCache[$itemId] = (string) \App\Core\Database::value(
                'SELECT uom FROM items WHERE id = ?',
                [$itemId],
                'PCS'
            );
        }

        return self::$uomCache[$itemId];
    }
}
