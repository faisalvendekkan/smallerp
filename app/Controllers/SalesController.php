<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Services\DocumentTotals;
use App\Services\Numbering;
use App\Services\Sales;
use App\Services\Settings;
use App\Support\Money;
use App\Support\ValidationException;

/** Quotations and sales invoices. */
final class SalesController extends Controller
{
    // ------------------------------------------------------------ Invoices

    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $filters = [
            'status' => $request->string('status'),
            'contact_id' => $request->int('contact_id'),
            'from' => $request->date('from'),
            'to' => $request->date('to'),
            'search' => $request->string('search'),
            'overdue' => $request->bool('overdue'),
        ];

        $result = Sales::listInvoices($filters, $page);

        return $this->view('sales/index', [
            'title' => __('nav.invoices'),
            'rows' => $result['rows'],
            'pagination' => $this->paginate($result['total'], $page),
            'filters' => $filters,
            'customers' => $this->customers(),
            'summary' => $this->summary($filters),
        ]);
    }

    /** Totals across the whole filtered set, not just the current page. */
    private function summary(array $filters): array
    {
        $result = Sales::listInvoices($filters, 1, 100000);
        $total = 0;
        $outstanding = 0;
        foreach ($result['rows'] as $row) {
            if ($row['status'] === Sales::STATUS_VOID) {
                continue;
            }
            $total += (int) $row['total'];
            $outstanding += (int) $row['balance_due'];
        }

        return ['count' => $result['total'], 'total' => $total, 'outstanding' => $outstanding];
    }

    public function create(Request $request): Response
    {
        return $this->view('sales/form', $this->formData(null, $request));
    }

    public function edit(Request $request): Response
    {
        $invoice = $this->findOr404(Sales::find($request->routeInt('id')), 'invoice');
        if ($invoice['status'] !== Sales::STATUS_DRAFT) {
            return $this->redirect(
                '/invoices/' . $invoice['id'],
                'Invoice ' . $invoice['number'] . ' has been posted and can no longer be edited.',
                'warning'
            );
        }

        return $this->view('sales/form', $this->formData($invoice, $request));
    }

    private function formData(?array $invoice, Request $request): array
    {
        $quotationId = $request->int('from_quotation');
        $lines = $invoice['lines'] ?? [];

        // Starting an invoice from an accepted quotation is the normal flow
        // for the project work these companies do.
        if ($invoice === null && $quotationId > 0) {
            $quotation = $this->quotation($quotationId);
            if ($quotation) {
                $lines = $quotation['lines'];
                $invoice = [
                    'contact_id' => $quotation['contact_id'],
                    'notes' => $quotation['notes'],
                    'terms' => $quotation['terms'],
                    'quotation_id' => $quotation['id'],
                ];
            }
        }

        return [
            'title' => $invoice && isset($invoice['number'])
                ? __('action.edit') . ' ' . $invoice['number']
                : __('action.new') . ' ' . __('nav.invoices'),
            'invoice' => $invoice,
            'lines' => $lines,
            'customers' => $this->customers(),
            'items' => $this->items(),
            'itemsJson' => $this->itemCatalogue(),
            'warehouses' => Database::all('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY code'),
            'nextNumber' => Numbering::preview('sales_invoice'),
            'taxEnabled' => Settings::bool('tax_enabled'),
            'defaultTaxRate' => Settings::defaultTaxRate(),
            'pricesIncludeTax' => Settings::bool('prices_include_tax'),
            'defaultTerms' => Settings::get('invoice_terms_' . Lang::locale())
                ?: Settings::get('invoice_terms_en'),
        ];
    }

    public function store(Request $request): Response
    {
        $id = Sales::saveInvoice($this->header($request), $request->rows('lines'));

        if ($request->bool('post_now')) {
            Sales::post($id);

            return $this->redirect('/invoices/' . $id, 'Invoice created and posted.');
        }

        return $this->redirect('/invoices/' . $id, 'Invoice saved as a draft.');
    }

    public function update(Request $request): Response
    {
        $id = $request->routeInt('id');
        Sales::saveInvoice($this->header($request), $request->rows('lines'), $id);

        if ($request->bool('post_now')) {
            Sales::post($id);

            return $this->redirect('/invoices/' . $id, 'Invoice updated and posted.');
        }

        return $this->redirect('/invoices/' . $id, 'Invoice updated.');
    }

    private function header(Request $request): array
    {
        return [
            'contact_id' => $request->int('contact_id'),
            'issue_date' => $request->date('issue_date') ?? date('Y-m-d'),
            'due_date' => $request->date('due_date'),
            'lpo_number' => $request->string('lpo_number'),
            'project' => $request->string('project'),
            'warehouse_id' => $request->int('warehouse_id'),
            'notes' => $request->string('notes') ?: null,
            'terms' => $request->string('terms') ?: null,
            'quotation_id' => $request->int('quotation_id'),
        ];
    }

    public function show(Request $request): Response
    {
        $invoice = $this->findOr404(Sales::find($request->routeInt('id')), 'invoice');

        return $this->view('sales/show', [
            'title' => $invoice['number'],
            'invoice' => $invoice,
            'taxBreakdown' => DocumentTotals::taxBreakdown($invoice['lines']),
            'journal' => $invoice['journal_id']
                ? \App\Services\Ledger::journal((int) $invoice['journal_id'])
                : null,
        ]);
    }

    public function post(Request $request): Response
    {
        $id = $request->routeInt('id');
        Sales::post($id);

        return $this->redirect('/invoices/' . $id, 'Invoice posted. The revenue and stock have been booked.');
    }

    public function void(Request $request): Response
    {
        $id = $request->routeInt('id');
        Sales::void($id, $request->string('void_reason'));

        return $this->redirect('/invoices/' . $id, 'Invoice voided and its journal reversed.');
    }

    public function printInvoice(Request $request): Response
    {
        $invoice = $this->findOr404(Sales::find($request->routeInt('id')), 'invoice');

        return $this->printView('print/invoice', [
            'title' => 'Invoice ' . $invoice['number'],
            'invoice' => $invoice,
            'settings' => Settings::all(),
            'taxBreakdown' => DocumentTotals::taxBreakdown($invoice['lines']),
            'backUrl' => url('/invoices/' . $invoice['id']),
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = [
            'status' => $request->string('status'),
            'from' => $request->date('from'),
            'to' => $request->date('to'),
            'search' => $request->string('search'),
        ];
        $rows = Sales::listInvoices($filters, 1, 100000)['rows'];

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['number'],
                $row['issue_date'],
                $row['due_date'],
                $row['contact_name_en'],
                $row['lpo_number'],
                Money::toDecimalString((int) $row['subtotal']),
                Money::toDecimalString((int) $row['tax_total']),
                Money::toDecimalString((int) $row['total']),
                Money::toDecimalString((int) $row['amount_paid']),
                Money::toDecimalString((int) $row['balance_due']),
                $row['status'],
            ];
        }

        return $this->csv(
            'invoices-' . date('Y-m-d') . '.csv',
            ['Number', 'Date', 'Due', 'Customer', 'LPO', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance', 'Status'],
            $data
        );
    }

    // ---------------------------------------------------------- Quotations

    public function quotations(Request $request): Response
    {
        $page = $this->page($request);
        $perPage = 25;
        $status = $request->string('status');
        $search = $request->string('search');

        $where = ['1 = 1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'q.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(q.number LIKE ? OR q.subject LIKE ? OR c.name_en LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM quotations q JOIN contacts c ON c.id = q.contact_id WHERE {$clause}",
            $params,
            0
        );
        $pagination = $this->paginate($total, $page, $perPage);
        $offset = ($pagination['page'] - 1) * $perPage;

        $rows = Database::all(
            "SELECT q.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar
             FROM quotations q JOIN contacts c ON c.id = q.contact_id
             WHERE {$clause} ORDER BY q.issue_date DESC, q.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // A quotation past its validity date is stale, whatever its status says.
        foreach ($rows as &$row) {
            $row['is_expired'] = $row['valid_until']
                && $row['valid_until'] < date('Y-m-d')
                && !in_array($row['status'], ['accepted', 'invoiced', 'rejected'], true);
        }
        unset($row);

        return $this->view('sales/quotations', [
            'title' => __('nav.quotations'),
            'rows' => $rows,
            'pagination' => $pagination,
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function createQuotation(Request $request): Response
    {
        return $this->view('sales/quotation_form', $this->quotationFormData(null));
    }

    public function editQuotation(Request $request): Response
    {
        $quotation = $this->findOr404($this->quotation($request->routeInt('id')), 'quotation');
        if ($quotation['status'] === 'invoiced') {
            return $this->redirect(
                '/quotations/' . $quotation['id'],
                'This quotation has been converted to an invoice and can no longer be edited.',
                'warning'
            );
        }

        return $this->view('sales/quotation_form', $this->quotationFormData($quotation));
    }

    private function quotationFormData(?array $quotation): array
    {
        return [
            'title' => $quotation
                ? __('action.edit') . ' ' . $quotation['number']
                : __('action.new') . ' ' . __('nav.quotations'),
            'quotation' => $quotation,
            'lines' => $quotation['lines'] ?? [],
            'customers' => $this->customers(),
            'items' => $this->items(),
            'itemsJson' => $this->itemCatalogue(),
            'nextNumber' => Numbering::preview('quotation'),
            'taxEnabled' => Settings::bool('tax_enabled'),
            'defaultTaxRate' => Settings::defaultTaxRate(),
            'pricesIncludeTax' => Settings::bool('prices_include_tax'),
            'defaultTerms' => Settings::get('invoice_terms_' . Lang::locale())
                ?: Settings::get('invoice_terms_en'),
        ];
    }

    public function storeQuotation(Request $request): Response
    {
        $id = $this->saveQuotation($request, null);

        return $this->redirect('/quotations/' . $id, 'Quotation saved.');
    }

    public function updateQuotation(Request $request): Response
    {
        $id = $request->routeInt('id');
        $this->saveQuotation($request, $id);

        return $this->redirect('/quotations/' . $id, 'Quotation updated.');
    }

    private function saveQuotation(Request $request, ?int $quotationId): int
    {
        $contactId = $request->int('contact_id');
        if ($contactId <= 0) {
            throw new ValidationException('Choose a customer', 'contact_id');
        }

        $issueDate = $request->date('issue_date') ?? date('Y-m-d');
        $validUntil = $request->date('valid_until')
            // Thirty days is the convention for a commercial quotation here.
            ?? date('Y-m-d', strtotime($issueDate . ' +30 days'));

        if ($validUntil < $issueDate) {
            throw new ValidationException('The validity date cannot be before the quotation date', 'valid_until');
        }

        $lines = DocumentTotals::prepareLines($request->rows('lines'), Settings::bool('prices_include_tax'));
        $totals = DocumentTotals::document($lines);

        return Database::transaction(static function () use ($request, $quotationId, $contactId, $issueDate, $validUntil, $lines, $totals): int {
            $data = [
                'contact_id' => $contactId,
                'issue_date' => $issueDate,
                'valid_until' => $validUntil,
                'subject' => mb_substr($request->string('subject'), 0, 200),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'notes' => $request->string('notes') ?: null,
                'terms' => $request->string('terms') ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($quotationId === null) {
                $data['number'] = Numbering::next('quotation', $issueDate);
                $data['status'] = 'draft';
                $data['created_by'] = Auth::id();
                $data['created_at'] = date('Y-m-d H:i:s');
                $quotationId = Database::insert('quotations', $data);
                AuditLog::record('quotation.create', 'quotation', $quotationId, ['number' => $data['number']]);
            } else {
                Database::update('quotations', $data, ['id' => $quotationId]);
                Database::delete('quotation_lines', ['quotation_id' => $quotationId]);
                AuditLog::record('quotation.update', 'quotation', $quotationId);
            }

            foreach ($lines as $line) {
                Database::insert('quotation_lines', [
                    'quotation_id' => $quotationId,
                    'item_id' => $line['item_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'uom' => $line['uom'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'tax_rate' => $line['tax_rate'],
                    'line_subtotal' => $line['line_subtotal'],
                    'line_tax' => $line['line_tax'],
                    'line_total' => $line['line_total'],
                    'line_no' => $line['line_no'],
                ]);
            }

            return $quotationId;
        });
    }

    public function showQuotation(Request $request): Response
    {
        $quotation = $this->findOr404($this->quotation($request->routeInt('id')), 'quotation');

        return $this->view('sales/quotation_show', [
            'title' => $quotation['number'],
            'quotation' => $quotation,
        ]);
    }

    public function quotationStatus(Request $request): Response
    {
        $id = $request->routeInt('id');
        $status = $request->string('status');
        if (!in_array($status, ['draft', 'sent', 'accepted', 'rejected', 'expired'], true)) {
            throw new ValidationException('That is not a valid quotation status');
        }

        $quotation = $this->findOr404($this->quotation($id), 'quotation');
        if ($quotation['status'] === 'invoiced') {
            throw new ValidationException('This quotation has already been invoiced');
        }

        Database::update('quotations', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        AuditLog::record('quotation.status', 'quotation', $id, ['status' => $status]);

        return $this->redirect('/quotations/' . $id, 'Quotation marked as ' . $status . '.');
    }

    /** Turn an accepted quotation into a draft invoice. */
    public function convertQuotation(Request $request): Response
    {
        $id = $request->routeInt('id');
        $quotation = $this->findOr404($this->quotation($id), 'quotation');

        if ($quotation['status'] === 'invoiced') {
            return $this->redirect(
                '/invoices/' . $quotation['invoice_id'],
                'This quotation was already converted to an invoice.',
                'info'
            );
        }

        $lineRows = [];
        foreach ($quotation['lines'] as $line) {
            $lineRows[] = [
                'item_id' => $line['item_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'uom' => $line['uom'],
                'unit_price' => Money::toDecimalString((int) $line['unit_price']),
                'discount_pct' => $line['discount_pct'],
                'tax_rate' => $line['tax_rate'],
            ];
        }

        $invoiceId = Database::transaction(static function () use ($quotation, $lineRows, $id): int {
            $invoiceId = Sales::saveInvoice([
                'contact_id' => (int) $quotation['contact_id'],
                'issue_date' => date('Y-m-d'),
                'notes' => $quotation['notes'],
                'terms' => $quotation['terms'],
                'quotation_id' => $id,
            ], $lineRows);

            Database::update('quotations', [
                'status' => 'invoiced',
                'invoice_id' => $invoiceId,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);

            AuditLog::record('quotation.convert', 'quotation', $id, ['invoice_id' => $invoiceId]);

            return $invoiceId;
        });

        return $this->redirect(
            '/invoices/' . $invoiceId . '/edit',
            'Draft invoice created from quotation ' . $quotation['number'] . '. Review it, then post it.'
        );
    }

    public function printQuotation(Request $request): Response
    {
        $quotation = $this->findOr404($this->quotation($request->routeInt('id')), 'quotation');

        return $this->printView('print/quotation', [
            'title' => 'Quotation ' . $quotation['number'],
            'quotation' => $quotation,
            'settings' => Settings::all(),
            'backUrl' => url('/quotations/' . $quotation['id']),
        ]);
    }

    private function quotation(int $id): ?array
    {
        $quotation = Database::first(
            'SELECT q.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar,
                    c.address AS contact_address, c.phone AS contact_phone, c.email AS contact_email,
                    c.cr_number AS contact_cr_number, c.po_box AS contact_po_box, c.city AS contact_city
             FROM quotations q JOIN contacts c ON c.id = q.contact_id WHERE q.id = ?',
            [$id]
        );
        if (!$quotation) {
            return null;
        }

        $quotation['lines'] = Database::all(
            'SELECT ql.*, i.sku, i.name_en AS item_name_en, i.name_ar AS item_name_ar
             FROM quotation_lines ql LEFT JOIN items i ON i.id = ql.item_id
             WHERE ql.quotation_id = ? ORDER BY ql.line_no',
            [$id]
        );

        return $quotation;
    }

    // ------------------------------------------------------------- Helpers

    private function customers(): array
    {
        return Database::all(
            "SELECT id, code, name_en, name_ar, payment_terms_days, credit_limit
             FROM contacts WHERE kind IN ('customer', 'both') AND is_active = 1 ORDER BY name_en"
        );
    }

    private function items(): array
    {
        return Database::all('SELECT * FROM items WHERE is_active = 1 ORDER BY sku');
    }

    /**
     * Item details the line editor needs, as JSON for the browser.
     *
     * Keeping it inline avoids a round trip on every line the user adds, and
     * an SME's catalogue is small enough that this stays tiny.
     */
    private function itemCatalogue(string $priceField = 'sale_price'): string
    {
        $catalogue = [];
        foreach ($this->items() as $item) {
            $catalogue[(string) $item['id']] = [
                'name' => Lang::pick($item, 'name'),
                'sale_price' => Money::toDecimalString((int) $item['sale_price']),
                'cost_price' => Money::toDecimalString((int) $item['cost_price']),
                'uom' => $item['uom'],
                'tax_rate' => (string) (float) $item['tax_rate'],
            ];
        }

        return json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
