<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ChartOfAccounts;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Numbering;
use App\Services\Settings;
use App\Support\Money;
use App\Support\ValidationException;

/** Items, warehouses and stock. */
final class InventoryController extends Controller
{
    // -------------------------------------------------------------- Items

    public function items(Request $request): Response
    {
        $page = $this->page($request);
        $perPage = 25;
        $search = $request->string('search');
        $kind = $request->string('kind');
        $showArchived = $request->bool('archived');

        $where = ['1 = 1'];
        $params = [];
        if (!$showArchived) {
            $where[] = 'is_active = 1';
        }
        if ($kind !== '') {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        if ($search !== '') {
            $where[] = '(sku LIKE ? OR name_en LIKE ? OR name_ar LIKE ? OR barcode LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM items WHERE {$clause}", $params, 0);
        $pagination = $this->paginate($total, $page, $perPage);
        $offset = ($pagination['page'] - 1) * $perPage;

        $rows = Database::all(
            "SELECT * FROM items WHERE {$clause} ORDER BY sku LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $row['qty_on_hand'] = (int) $row['track_stock'] === 1
                ? Inventory::quantityOnHand((int) $row['id'])
                : null;
        }
        unset($row);

        return $this->view('inventory/items', [
            'title' => __('nav.items'),
            'rows' => $rows,
            'pagination' => $pagination,
            'search' => $search,
            'kind' => $kind,
            'archived' => $showArchived,
        ]);
    }

    public function createItem(Request $request): Response
    {
        return $this->view('inventory/item_form', $this->itemFormData(null));
    }

    public function editItem(Request $request): Response
    {
        $item = $this->findOr404(
            Database::first('SELECT * FROM items WHERE id = ?', [$request->routeInt('id')]),
            'item'
        );

        return $this->view('inventory/item_form', $this->itemFormData($item));
    }

    private function itemFormData(?array $item): array
    {
        return [
            'title' => $item ? __('action.edit') . ' ' . $item['sku'] : __('action.new') . ' ' . __('nav.items'),
            'item' => $item,
            'incomeAccounts' => Database::all(
                "SELECT * FROM accounts WHERE type = 'income' AND is_active = 1 ORDER BY code"
            ),
            'expenseAccounts' => Database::all(
                "SELECT * FROM accounts WHERE type = 'expense' AND is_active = 1 ORDER BY code"
            ),
            'inventoryAccounts' => Database::all(
                "SELECT * FROM accounts WHERE type = 'asset' AND subtype = 'inventory' AND is_active = 1 ORDER BY code"
            ),
            'taxEnabled' => Settings::bool('tax_enabled'),
            'defaultTaxRate' => Settings::defaultTaxRate(),
            'nextSku' => Numbering::preview('item'),
        ];
    }

    public function storeItem(Request $request): Response
    {
        $id = $this->saveItem($request, null);

        return $this->redirect('/items/' . $id, 'Item saved.');
    }

    public function updateItem(Request $request): Response
    {
        $id = $request->routeInt('id');
        $this->saveItem($request, $id);

        return $this->redirect('/items/' . $id, 'Item updated.');
    }

    private function saveItem(Request $request, ?int $itemId): int
    {
        $nameEn = $request->required('name_en', 'Item name');
        $kind = $request->string('kind', 'goods') === 'service' ? 'service' : 'goods';
        $sku = strtoupper($request->string('sku')) ?: Numbering::next('item');

        $duplicate = Database::first(
            'SELECT id FROM items WHERE sku = ?' . ($itemId ? ' AND id <> ?' : ''),
            $itemId ? [$sku, $itemId] : [$sku]
        );
        if ($duplicate) {
            throw new ValidationException('An item with the code ' . $sku . ' already exists', 'sku');
        }

        $taxRate = $request->float('tax_rate');
        if ($taxRate < 0 || $taxRate > 100) {
            throw new ValidationException('Tax rate must be between 0 and 100 percent', 'tax_rate');
        }

        // Only goods can hold stock; a service has nothing to count.
        $trackStock = $kind === 'goods' && $request->bool('track_stock', true);

        $data = [
            'sku' => mb_substr($sku, 0, 40),
            'barcode' => mb_substr($request->string('barcode'), 0, 40),
            'name_en' => mb_substr($nameEn, 0, 160),
            'name_ar' => mb_substr($request->string('name_ar'), 0, 160),
            'description' => mb_substr($request->string('description'), 0, 255),
            'kind' => $kind,
            'uom' => mb_substr($request->string('uom', 'PCS'), 0, 20) ?: 'PCS',
            'sale_price' => $request->money('sale_price'),
            'cost_price' => $request->money('cost_price'),
            'tax_rate' => $taxRate,
            'track_stock' => $trackStock ? 1 : 0,
            'reorder_level' => max(0.0, $request->float('reorder_level')),
            'income_account_id' => $request->int('income_account_id') ?: null,
            'expense_account_id' => $request->int('expense_account_id') ?: null,
            'inventory_account_id' => $trackStock ? ($request->int('inventory_account_id') ?: null) : null,
            'is_active' => $request->bool('is_active', true) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($itemId === null) {
            $data['created_at'] = date('Y-m-d H:i:s');
            $openingQty = $request->float('opening_qty');
            $data['opening_qty'] = $openingQty;
            $itemId = Database::insert('items', $data);
            AuditLog::record('item.create', 'item', $itemId, ['sku' => $sku]);

            // An opening stock figure has to reach the ledger as well as the
            // stock card, or the balance sheet will not agree with the store.
            if ($trackStock && $openingQty > 0) {
                $this->recordOpeningStock($itemId, $openingQty, $data['cost_price']);
            }
        } else {
            Database::update('items', $data, ['id' => $itemId]);
            AuditLog::record('item.update', 'item', $itemId);
        }

        return $itemId;
    }

    private function recordOpeningStock(int $itemId, float $quantity, int $unitCost): void
    {
        $warehouseId = Inventory::defaultWarehouseId();
        $value = Money::multiply($unitCost, $quantity);

        Inventory::move(
            $itemId,
            $warehouseId,
            $quantity,
            $unitCost,
            date('Y-m-d'),
            'opening',
            $itemId,
            'Opening stock'
        );

        if ($value > 0) {
            Ledger::post(
                date('Y-m-d'),
                [
                    [
                        'account_id' => ChartOfAccounts::id('inventory'),
                        'debit' => $value,
                        'credit' => 0,
                        'memo' => 'Opening stock',
                    ],
                    [
                        'account_id' => ChartOfAccounts::id('opening_balance_equity'),
                        'debit' => 0,
                        'credit' => $value,
                        'memo' => 'Opening stock',
                    ],
                ],
                'Opening stock',
                'opening_stock',
                $itemId
            );
        }
    }

    public function showItem(Request $request): Response
    {
        $id = $request->routeInt('id');
        $item = $this->findOr404(Database::first('SELECT * FROM items WHERE id = ?', [$id]), 'item');

        return $this->view('inventory/item_show', [
            'title' => $item['sku'] . ' — ' . $this->name($item),
            'item' => $item,
            'onHand' => Inventory::quantityOnHand($id),
            'averageCost' => Inventory::averageCost($id),
            'stockValue' => Inventory::stockValue($id),
            'movements' => (int) $item['track_stock'] === 1 ? Inventory::movements($id, 50) : [],
        ]);
    }

    // -------------------------------------------------------------- Stock

    public function stock(Request $request): Response
    {
        $asOf = $request->date('as_of') ?? date('Y-m-d');
        $warehouseId = $request->int('warehouse_id') ?: null;
        $rows = Inventory::stockReport($asOf, $warehouseId);

        return $this->view('inventory/stock', [
            'title' => __('nav.stock'),
            'rows' => $rows,
            'asOf' => $asOf,
            'warehouseId' => $warehouseId,
            'warehouses' => Database::all('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY code'),
            'totalValue' => array_sum(array_map(static fn ($r) => (int) $r['stock_value'], $rows)),
            'lowCount' => count(array_filter($rows, static fn ($r) => (bool) $r['below_reorder'])),
        ]);
    }

    public function exportStock(Request $request): Response
    {
        $asOf = $request->date('as_of') ?? date('Y-m-d');
        $data = [];
        foreach (Inventory::stockReport($asOf) as $row) {
            $data[] = [
                $row['sku'],
                $row['name_en'],
                $row['name_ar'],
                $row['uom'],
                $row['qty_on_hand'],
                $row['reorder_level'],
                Money::toDecimalString((int) $row['average_cost']),
                Money::toDecimalString((int) $row['stock_value']),
                $row['below_reorder'] ? 'REORDER' : '',
            ];
        }

        return $this->csv(
            'stock-' . $asOf . '.csv',
            ['SKU', 'Name (EN)', 'Name (AR)', 'UoM', 'On hand', 'Reorder level',
             'Average cost', 'Stock value', 'Flag'],
            $data
        );
    }

    public function adjustForm(Request $request): Response
    {
        return $this->view('inventory/adjust', [
            'title' => 'Stock adjustment',
            'items' => Database::all(
                'SELECT * FROM items WHERE track_stock = 1 AND is_active = 1 ORDER BY sku'
            ),
            'warehouses' => Database::all('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY code'),
            'itemId' => $request->int('item_id'),
        ]);
    }

    /**
     * Adjust stock up or down, posting the difference to the ledger.
     *
     * A stock count that finds less on the shelf than the system says is a
     * loss, and has to hit the P&L rather than vanish.
     */
    public function adjust(Request $request): Response
    {
        $itemId = $request->int('item_id');
        $warehouseId = $request->int('warehouse_id') ?: Inventory::defaultWarehouseId();
        $quantity = $request->float('quantity');
        $reason = $request->string('reason');
        $date = $request->date('move_date') ?? date('Y-m-d');

        $item = Database::first('SELECT * FROM items WHERE id = ?', [$itemId]);
        if (!$item) {
            throw new ValidationException('Choose an item', 'item_id');
        }
        if ((int) $item['track_stock'] !== 1) {
            throw new ValidationException('That item does not hold stock', 'item_id');
        }
        if ($quantity == 0.0) {
            throw new ValidationException('Enter the quantity to add or remove', 'quantity');
        }
        if ($reason === '') {
            throw new ValidationException('Give a reason for the adjustment', 'reason');
        }

        $onHand = Inventory::quantityOnHand($itemId, $warehouseId);
        if ($quantity < 0 && $onHand < abs($quantity)) {
            throw new ValidationException(sprintf(
                'Only %s of %s is on hand',
                rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.'),
                $item['sku']
            ), 'quantity');
        }

        $unitCost = $quantity > 0
            ? $request->money('unit_cost', Inventory::averageCost($itemId))
            : Inventory::averageCost($itemId);
        $value = Money::multiply($unitCost, abs($quantity));

        Database::transaction(static function () use ($itemId, $warehouseId, $quantity, $unitCost, $date, $reason, $value, $item): void {
            Inventory::move($itemId, $warehouseId, $quantity, $unitCost, $date, 'adjustment', null, $reason);

            if ($value > 0) {
                $inventoryAccount = $item['inventory_account_id']
                    ? (int) $item['inventory_account_id']
                    : ChartOfAccounts::id('inventory');
                $adjustmentAccount = ChartOfAccounts::id('inventory_adjustment');

                $lines = $quantity > 0
                    ? [
                        ['account_id' => $inventoryAccount, 'debit' => $value, 'credit' => 0, 'memo' => $reason],
                        ['account_id' => $adjustmentAccount, 'debit' => 0, 'credit' => $value, 'memo' => $reason],
                    ]
                    : [
                        ['account_id' => $adjustmentAccount, 'debit' => $value, 'credit' => 0, 'memo' => $reason],
                        ['account_id' => $inventoryAccount, 'debit' => 0, 'credit' => $value, 'memo' => $reason],
                    ];

                Ledger::post($date, $lines, 'Stock adjustment — ' . $item['sku'], 'stock_adjustment', $itemId);
            }

            AuditLog::record('stock.adjust', 'item', $itemId, [
                'quantity' => $quantity,
                'reason' => $reason,
            ]);
        });

        return $this->redirect('/items/' . $itemId, 'Stock adjusted.');
    }

    // ---------------------------------------------------------- Warehouses

    public function warehouses(Request $request): Response
    {
        $rows = Database::all('SELECT * FROM warehouses ORDER BY code');
        foreach ($rows as &$row) {
            $row['item_count'] = (int) Database::value(
                'SELECT COUNT(DISTINCT item_id) FROM stock_moves WHERE warehouse_id = ?',
                [(int) $row['id']],
                0
            );
        }
        unset($row);

        return $this->view('inventory/warehouses', [
            'title' => 'Warehouses',
            'rows' => $rows,
        ]);
    }

    public function storeWarehouse(Request $request): Response
    {
        $code = strtoupper($request->required('code', 'Code'));
        $nameEn = $request->required('name_en', 'Name');

        if (Database::first('SELECT id FROM warehouses WHERE code = ?', [$code])) {
            throw new ValidationException('A warehouse with that code already exists', 'code');
        }

        $isDefault = $request->bool('is_default');
        Database::transaction(static function () use ($code, $nameEn, $request, $isDefault): void {
            if ($isDefault) {
                Database::query('UPDATE warehouses SET is_default = 0');
            }
            Database::insert('warehouses', [
                'code' => mb_substr($code, 0, 20),
                'name_en' => mb_substr($nameEn, 0, 120),
                'name_ar' => mb_substr($request->string('name_ar'), 0, 120),
                'address' => mb_substr($request->string('address'), 0, 255),
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => 1,
            ]);
        });

        return $this->redirect('/warehouses', 'Warehouse added.');
    }
}
