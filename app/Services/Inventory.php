<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Support\Money;
use App\Support\ValidationException;

/**
 * Stock movements and valuation.
 *
 * Costing is weighted average, which is the method a small trading company in
 * Qatar will actually be able to explain to its auditor, and which does not
 * require tracking individual batches through the warehouse.
 */
final class Inventory
{
    /** Record a movement. Positive quantity is stock in, negative is stock out. */
    public static function move(
        int $itemId,
        int $warehouseId,
        float $quantity,
        int $unitCost,
        string $date,
        string $sourceType = 'adjustment',
        ?int $sourceId = null,
        string $note = ''
    ): int {
        if ($quantity == 0.0) {
            throw new ValidationException('A stock movement cannot be for zero quantity');
        }

        return Database::insert('stock_moves', [
            'move_date' => $date,
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => mb_substr($note, 0, 255),
            'created_by' => Auth::id(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Quantity on hand for an item, optionally in one warehouse. */
    public static function quantityOnHand(int $itemId, ?int $warehouseId = null, ?string $asOf = null): float
    {
        $where = ['item_id = ?'];
        $params = [$itemId];
        if ($warehouseId !== null) {
            $where[] = 'warehouse_id = ?';
            $params[] = $warehouseId;
        }
        if ($asOf !== null) {
            $where[] = 'move_date <= ?';
            $params[] = $asOf;
        }

        return (float) Database::value(
            'SELECT COALESCE(SUM(quantity), 0) FROM stock_moves WHERE ' . implode(' AND ', $where),
            $params,
            0
        );
    }

    /**
     * Weighted average unit cost of an item.
     *
     * Only inbound movements carry a purchase cost, so the average is taken
     * over those; outbound moves are valued at whatever the average was when
     * they happened.
     */
    public static function averageCost(int $itemId, ?string $asOf = null): int
    {
        $where = ['item_id = ?', 'quantity > 0'];
        $params = [$itemId];
        if ($asOf !== null) {
            $where[] = 'move_date <= ?';
            $params[] = $asOf;
        }

        $row = Database::first(
            'SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(quantity * unit_cost), 0) AS value
             FROM stock_moves WHERE ' . implode(' AND ', $where),
            $params
        );

        $qty = (float) ($row['qty'] ?? 0);
        if ($qty <= 0) {
            // Nothing has been received yet -- fall back to the item's cost price.
            return (int) Database::value('SELECT cost_price FROM items WHERE id = ?', [$itemId], 0);
        }

        return Money::roundHalfUp(((float) $row['value']) / $qty);
    }

    /** Value of stock on hand for an item at weighted average cost. */
    public static function stockValue(int $itemId, ?string $asOf = null): int
    {
        return Money::roundHalfUp(self::quantityOnHand($itemId, null, $asOf) * self::averageCost($itemId, $asOf));
    }

    /**
     * Stock position for every tracked item.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stockReport(?string $asOf = null, ?int $warehouseId = null): array
    {
        $params = [];
        $dateFilter = '';
        if ($asOf !== null) {
            $dateFilter .= ' AND sm.move_date <= ?';
            $params[] = $asOf;
        }
        $warehouseFilter = '';
        if ($warehouseId !== null) {
            $warehouseFilter = ' AND sm.warehouse_id = ?';
            $params[] = $warehouseId;
        }

        $rows = Database::all(
            'SELECT i.id, i.sku, i.name_en, i.name_ar, i.uom, i.reorder_level, i.cost_price, i.sale_price,
                    COALESCE(SUM(sm.quantity), 0) AS qty_on_hand,
                    COALESCE(SUM(CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END), 0) AS qty_in,
                    COALESCE(SUM(CASE WHEN sm.quantity > 0 THEN sm.quantity * sm.unit_cost ELSE 0 END), 0) AS inbound_value
             FROM items i
             LEFT JOIN stock_moves sm ON sm.item_id = i.id' . $dateFilter . $warehouseFilter . '
             WHERE i.track_stock = 1 AND i.is_active = 1
             GROUP BY i.id, i.sku, i.name_en, i.name_ar, i.uom, i.reorder_level, i.cost_price, i.sale_price
             ORDER BY i.sku',
            $params
        );

        foreach ($rows as &$row) {
            $qtyIn = (float) $row['qty_in'];
            $avgCost = $qtyIn > 0
                ? Money::roundHalfUp(((float) $row['inbound_value']) / $qtyIn)
                : (int) $row['cost_price'];
            $onHand = (float) $row['qty_on_hand'];

            $row['average_cost'] = $avgCost;
            $row['stock_value'] = Money::roundHalfUp($onHand * $avgCost);
            $row['below_reorder'] = (float) $row['reorder_level'] > 0 && $onHand <= (float) $row['reorder_level'];
        }
        unset($row);

        return $rows;
    }

    /** Total value of stock on hand, for the balance sheet and dashboard. */
    public static function totalStockValue(?string $asOf = null): int
    {
        $total = 0;
        foreach (self::stockReport($asOf) as $row) {
            $total += (int) $row['stock_value'];
        }

        return $total;
    }

    /** Items at or below their reorder level. */
    public static function lowStock(): array
    {
        return array_values(array_filter(
            self::stockReport(),
            static fn (array $row): bool => (bool) $row['below_reorder']
        ));
    }

    /** Movement history for one item. */
    public static function movements(int $itemId, int $limit = 200): array
    {
        return Database::all(
            'SELECT sm.*, w.name_en AS warehouse_name, w.code AS warehouse_code
             FROM stock_moves sm
             JOIN warehouses w ON w.id = sm.warehouse_id
             WHERE sm.item_id = ?
             ORDER BY sm.move_date DESC, sm.id DESC
             LIMIT ?',
            [$itemId, $limit]
        );
    }

    /** Remove the movements a document created, used when voiding it. */
    public static function reverseSource(string $sourceType, int $sourceId): void
    {
        Database::delete('stock_moves', ['source_type' => $sourceType, 'source_id' => $sourceId]);
    }

    public static function defaultWarehouseId(): int
    {
        $id = Database::value('SELECT id FROM warehouses WHERE is_default = 1 AND is_active = 1');
        $id ??= Database::value('SELECT id FROM warehouses WHERE is_active = 1 ORDER BY id LIMIT 1');

        if ($id === null) {
            throw new ValidationException('No warehouse has been set up yet — add one under Inventory');
        }

        return (int) $id;
    }
}
