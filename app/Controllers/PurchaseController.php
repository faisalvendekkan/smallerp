<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Services\DocumentTotals;
use App\Services\Numbering;
use App\Services\Purchases;
use App\Services\Settings;
use App\Support\Money;

/** Supplier bills. */
final class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $filters = [
            'status' => $request->string('status'),
            'contact_id' => $request->int('contact_id'),
            'from' => $request->date('from'),
            'to' => $request->date('to'),
            'search' => $request->string('search'),
        ];

        $result = Purchases::listBills($filters, $page);
        $all = Purchases::listBills($filters, 1, 100000)['rows'];
        $total = 0;
        $outstanding = 0;
        foreach ($all as $row) {
            if ($row['status'] === Purchases::STATUS_VOID) {
                continue;
            }
            $total += (int) $row['total'];
            $outstanding += (int) $row['balance_due'];
        }

        return $this->view('purchases/index', [
            'title' => __('nav.bills'),
            'rows' => $result['rows'],
            'pagination' => $this->paginate($result['total'], $page),
            'filters' => $filters,
            'suppliers' => $this->suppliers(),
            'summary' => ['count' => $result['total'], 'total' => $total, 'outstanding' => $outstanding],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('purchases/form', $this->formData(null));
    }

    public function edit(Request $request): Response
    {
        $bill = $this->findOr404(Purchases::find($request->routeInt('id')), 'bill');
        if ($bill['status'] !== Purchases::STATUS_DRAFT) {
            return $this->redirect(
                '/bills/' . $bill['id'],
                'Bill ' . $bill['number'] . ' has been posted and can no longer be edited.',
                'warning'
            );
        }

        return $this->view('purchases/form', $this->formData($bill));
    }

    private function formData(?array $bill): array
    {
        return [
            'title' => $bill
                ? __('action.edit') . ' ' . $bill['number']
                : __('action.new') . ' ' . __('nav.bills'),
            'bill' => $bill,
            'lines' => $bill['lines'] ?? [],
            'suppliers' => $this->suppliers(),
            'items' => Database::all('SELECT * FROM items WHERE is_active = 1 ORDER BY sku'),
            'itemsJson' => $this->itemCatalogue(),
            // Expense bills (rent, Kahramaa, fuel) are coded straight to an
            // account instead of an item, so the form offers both.
            'expenseAccounts' => Database::all(
                "SELECT * FROM accounts WHERE type = 'expense' AND is_active = 1 ORDER BY code"
            ),
            'warehouses' => Database::all('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY code'),
            'nextNumber' => Numbering::preview('purchase_bill'),
            'taxEnabled' => Settings::bool('tax_enabled'),
            'defaultTaxRate' => Settings::defaultTaxRate(),
            'pricesIncludeTax' => Settings::bool('prices_include_tax'),
        ];
    }

    public function store(Request $request): Response
    {
        $id = Purchases::saveBill($this->header($request), $request->rows('lines'));

        if ($request->bool('post_now')) {
            Purchases::post($id);

            return $this->redirect('/bills/' . $id, 'Bill created and posted.');
        }

        return $this->redirect('/bills/' . $id, 'Bill saved as a draft.');
    }

    public function update(Request $request): Response
    {
        $id = $request->routeInt('id');
        Purchases::saveBill($this->header($request), $request->rows('lines'), $id);

        if ($request->bool('post_now')) {
            Purchases::post($id);

            return $this->redirect('/bills/' . $id, 'Bill updated and posted.');
        }

        return $this->redirect('/bills/' . $id, 'Bill updated.');
    }

    private function header(Request $request): array
    {
        return [
            'contact_id' => $request->int('contact_id'),
            'supplier_invoice_no' => $request->string('supplier_invoice_no'),
            'issue_date' => $request->date('issue_date') ?? date('Y-m-d'),
            'due_date' => $request->date('due_date'),
            'warehouse_id' => $request->int('warehouse_id'),
            'notes' => $request->string('notes') ?: null,
        ];
    }

    public function show(Request $request): Response
    {
        $bill = $this->findOr404(Purchases::find($request->routeInt('id')), 'bill');

        return $this->view('purchases/show', [
            'title' => $bill['number'],
            'bill' => $bill,
            'taxBreakdown' => DocumentTotals::taxBreakdown($bill['lines']),
            'journal' => $bill['journal_id']
                ? \App\Services\Ledger::journal((int) $bill['journal_id'])
                : null,
        ]);
    }

    public function post(Request $request): Response
    {
        $id = $request->routeInt('id');
        Purchases::post($id);

        return $this->redirect('/bills/' . $id, 'Bill posted. The payable and stock have been booked.');
    }

    public function void(Request $request): Response
    {
        $id = $request->routeInt('id');
        Purchases::void($id, $request->string('void_reason'));

        return $this->redirect('/bills/' . $id, 'Bill voided and its journal reversed.');
    }

    public function export(Request $request): Response
    {
        $filters = [
            'status' => $request->string('status'),
            'from' => $request->date('from'),
            'to' => $request->date('to'),
            'search' => $request->string('search'),
        ];
        $rows = Purchases::listBills($filters, 1, 100000)['rows'];

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['number'],
                $row['supplier_invoice_no'],
                $row['issue_date'],
                $row['due_date'],
                $row['contact_name_en'],
                Money::toDecimalString((int) $row['subtotal']),
                Money::toDecimalString((int) $row['tax_total']),
                Money::toDecimalString((int) $row['total']),
                Money::toDecimalString((int) $row['amount_paid']),
                Money::toDecimalString((int) $row['balance_due']),
                $row['status'],
            ];
        }

        return $this->csv(
            'bills-' . date('Y-m-d') . '.csv',
            ['Number', 'Supplier invoice', 'Date', 'Due', 'Supplier', 'Subtotal', 'Tax',
             'Total', 'Paid', 'Balance', 'Status'],
            $data
        );
    }

    private function suppliers(): array
    {
        return Database::all(
            "SELECT id, code, name_en, name_ar, payment_terms_days
             FROM contacts WHERE kind IN ('supplier', 'both') AND is_active = 1 ORDER BY name_en"
        );
    }

    private function itemCatalogue(): string
    {
        $catalogue = [];
        foreach (Database::all('SELECT * FROM items WHERE is_active = 1') as $item) {
            $catalogue[(string) $item['id']] = [
                'name' => Lang::pick($item, 'name'),
                // A purchase line defaults to the cost price, not the sale price.
                'sale_price' => Money::toDecimalString((int) $item['cost_price']),
                'cost_price' => Money::toDecimalString((int) $item['cost_price']),
                'uom' => $item['uom'],
                'tax_rate' => (string) (float) $item['tax_rate'],
            ];
        }

        return json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
