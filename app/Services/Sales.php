<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Support\Money;
use App\Support\ValidationException;

/**
 * Sales invoices and quotations.
 *
 * A draft invoice is editable and touches nothing else. Posting it is the
 * moment it becomes a real document: it books the revenue, moves the stock and
 * cannot be edited afterwards -- only voided, which reverses the journal and
 * leaves both entries visible.
 */
final class Sales
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    /**
     * Create or update a draft invoice.
     *
     * @param array<int,array<string,mixed>> $lineRows Raw rows from the form.
     */
    public static function saveInvoice(array $header, array $lineRows, ?int $invoiceId = null): int
    {
        $contactId = (int) ($header['contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new ValidationException('Choose a customer', 'contact_id');
        }
        $contact = Database::first('SELECT * FROM contacts WHERE id = ?', [$contactId]);
        if (!$contact) {
            throw new ValidationException('That customer does not exist', 'contact_id');
        }
        if (!in_array($contact['kind'], ['customer', 'both'], true)) {
            throw new ValidationException('That contact is a supplier, not a customer', 'contact_id');
        }

        $issueDate = (string) ($header['issue_date'] ?? date('Y-m-d'));
        $dueDate = (string) ($header['due_date'] ?? '');
        if ($dueDate === '') {
            $dueDate = date('Y-m-d', strtotime($issueDate . ' +' . (int) $contact['payment_terms_days'] . ' days'));
        }
        if ($dueDate < $issueDate) {
            throw new ValidationException('The due date cannot be before the invoice date', 'due_date');
        }

        $lines = DocumentTotals::prepareLines($lineRows, Settings::bool('prices_include_tax'));
        $totals = DocumentTotals::document($lines);

        return Database::transaction(static function () use ($header, $lines, $totals, $invoiceId, $contactId, $issueDate, $dueDate): int {
            $data = [
                'contact_id' => $contactId,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'lpo_number' => mb_substr((string) ($header['lpo_number'] ?? ''), 0, 60),
                'project' => mb_substr((string) ($header['project'] ?? ''), 0, 120),
                'warehouse_id' => (int) ($header['warehouse_id'] ?? 0) ?: null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'notes' => $header['notes'] ?? null,
                'terms' => $header['terms'] ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($invoiceId === null) {
                $data['number'] = Numbering::next('sales_invoice', $issueDate);
                $data['status'] = self::STATUS_DRAFT;
                $data['created_by'] = Auth::id();
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['quotation_id'] = (int) ($header['quotation_id'] ?? 0) ?: null;
                $invoiceId = Database::insert('sales_invoices', $data);
                AuditLog::record('invoice.create', 'sales_invoice', $invoiceId, ['number' => $data['number']]);
            } else {
                $existing = Database::first('SELECT * FROM sales_invoices WHERE id = ?', [$invoiceId]);
                if (!$existing) {
                    throw new ValidationException('That invoice does not exist');
                }
                if ($existing['status'] !== self::STATUS_DRAFT) {
                    throw new ValidationException(
                        'Invoice ' . $existing['number'] . ' has been posted and can no longer be edited. '
                        . 'Void it and raise a new one if it is wrong.'
                    );
                }
                Database::update('sales_invoices', $data, ['id' => $invoiceId]);
                Database::delete('sales_invoice_lines', ['invoice_id' => $invoiceId]);
                AuditLog::record('invoice.update', 'sales_invoice', $invoiceId);
            }

            foreach ($lines as $line) {
                Database::insert('sales_invoice_lines', [
                    'invoice_id' => $invoiceId,
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

            return $invoiceId;
        });
    }

    /**
     * Post a draft invoice: book the revenue, the receivable and the stock.
     *
     * Dr Accounts Receivable      total
     *   Cr Sales                  net of tax, per line's income account
     *   Cr Output Tax Payable     tax (zero until VAT commences)
     * and for stocked goods:
     * Dr Cost of Goods Sold       cost
     *   Cr Inventory              cost
     */
    public static function post(int $invoiceId): void
    {
        $invoice = self::find($invoiceId);
        if (!$invoice) {
            throw new ValidationException('That invoice does not exist');
        }
        if ($invoice['status'] !== self::STATUS_DRAFT) {
            throw new ValidationException('Only a draft invoice can be posted');
        }
        if ((int) $invoice['total'] <= 0) {
            throw new ValidationException('An invoice must be for more than zero');
        }

        Database::transaction(static function () use ($invoice, $invoiceId): void {
            $lines = [];
            $contactId = (int) $invoice['contact_id'];

            $lines[] = [
                'account_id' => ChartOfAccounts::id('accounts_receivable'),
                'debit' => (int) $invoice['total'],
                'credit' => 0,
                'memo' => 'Invoice ' . $invoice['number'],
                'contact_id' => $contactId,
            ];

            // Group revenue by the income account each item points at, so a
            // company selling both goods and services sees them apart on the P&L.
            $revenueByAccount = [];
            $costOfSales = 0;
            $warehouseId = (int) ($invoice['warehouse_id'] ?: 0);

            foreach ($invoice['lines'] as $line) {
                $item = $line['item_id']
                    ? Database::first('SELECT * FROM items WHERE id = ?', [(int) $line['item_id']])
                    : null;

                $accountId = $item && $item['income_account_id']
                    ? (int) $item['income_account_id']
                    : ChartOfAccounts::id(
                        $item && $item['kind'] === 'service' ? 'service_income' : 'sales_income'
                    );

                $revenueByAccount[$accountId] = ($revenueByAccount[$accountId] ?? 0) + (int) $line['line_subtotal'];

                if ($item && (int) $item['track_stock'] === 1) {
                    if ($warehouseId === 0) {
                        $warehouseId = Inventory::defaultWarehouseId();
                    }
                    $quantity = (float) $line['quantity'];
                    $available = Inventory::quantityOnHand((int) $item['id'], $warehouseId);
                    if ($available < $quantity) {
                        throw new ValidationException(sprintf(
                            'Not enough stock of %s: %s on hand, %s needed',
                            $item['sku'],
                            rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.')
                        ));
                    }

                    $unitCost = Inventory::averageCost((int) $item['id']);
                    $costOfSales += Money::multiply($unitCost, $quantity);

                    Inventory::move(
                        (int) $item['id'],
                        $warehouseId,
                        -$quantity,
                        $unitCost,
                        (string) $invoice['issue_date'],
                        'sales_invoice',
                        $invoiceId,
                        'Invoice ' . $invoice['number']
                    );
                }
            }

            foreach ($revenueByAccount as $accountId => $amount) {
                $lines[] = [
                    'account_id' => (int) $accountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Invoice ' . $invoice['number'],
                    'contact_id' => $contactId,
                ];
            }

            if ((int) $invoice['tax_total'] > 0) {
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('output_tax'),
                    'debit' => 0,
                    'credit' => (int) $invoice['tax_total'],
                    'memo' => 'Tax on ' . $invoice['number'],
                    'contact_id' => $contactId,
                ];
            }

            if ($costOfSales > 0) {
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('cost_of_goods_sold'),
                    'debit' => $costOfSales,
                    'credit' => 0,
                    'memo' => 'Cost of sales — ' . $invoice['number'],
                ];
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('inventory'),
                    'debit' => 0,
                    'credit' => $costOfSales,
                    'memo' => 'Cost of sales — ' . $invoice['number'],
                ];
            }

            $journalId = Ledger::post(
                (string) $invoice['issue_date'],
                $lines,
                'Sales invoice ' . $invoice['number'],
                'sales_invoice',
                $invoiceId
            );

            Database::update('sales_invoices', [
                'status' => self::STATUS_POSTED,
                'journal_id' => $journalId,
                'posted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $invoiceId]);

            AuditLog::record('invoice.post', 'sales_invoice', $invoiceId, [
                'number' => $invoice['number'],
                'total' => Money::toDecimalString((int) $invoice['total']),
            ]);
        });
    }

    /**
     * Void a posted invoice by reversing its journal and returning the stock.
     *
     * An invoice that has been paid cannot be voided: the receipt has to be
     * reversed first, or the cash book would show money against nothing.
     */
    public static function void(int $invoiceId, string $reason): void
    {
        $invoice = self::find($invoiceId);
        if (!$invoice) {
            throw new ValidationException('That invoice does not exist');
        }
        if ($invoice['status'] === self::STATUS_VOID) {
            throw new ValidationException('That invoice is already void');
        }
        if ((int) $invoice['amount_paid'] > 0) {
            throw new ValidationException(
                'This invoice has payments against it. Void or unallocate the receipts first.'
            );
        }
        if (trim($reason) === '') {
            throw new ValidationException('Give a reason for voiding the invoice', 'void_reason');
        }

        Database::transaction(static function () use ($invoice, $invoiceId, $reason): void {
            if ($invoice['journal_id']) {
                Ledger::reverse(
                    (int) $invoice['journal_id'],
                    date('Y-m-d'),
                    'Void of invoice ' . $invoice['number'] . ' — ' . $reason
                );
            }
            Inventory::reverseSource('sales_invoice', $invoiceId);

            Database::update('sales_invoices', [
                'status' => self::STATUS_VOID,
                'voided_at' => date('Y-m-d H:i:s'),
                'void_reason' => mb_substr($reason, 0, 255),
            ], ['id' => $invoiceId]);

            AuditLog::record('invoice.void', 'sales_invoice', $invoiceId, [
                'number' => $invoice['number'],
                'reason' => $reason,
            ]);
        });
    }

    /** Refresh an invoice's paid amount and status from its allocations. */
    public static function refreshPaymentStatus(int $invoiceId): void
    {
        $invoice = Database::first('SELECT * FROM sales_invoices WHERE id = ?', [$invoiceId]);
        if (!$invoice || $invoice['status'] === self::STATUS_VOID) {
            return;
        }

        $paid = (int) Database::value(
            "SELECT COALESCE(SUM(pa.amount), 0)
             FROM payment_allocations pa
             JOIN payments p ON p.id = pa.payment_id
             WHERE pa.doc_type = 'sales_invoice' AND pa.doc_id = ? AND p.status = 'posted'",
            [$invoiceId],
            0
        );

        $total = (int) $invoice['total'];
        $status = match (true) {
            $paid <= 0 => self::STATUS_POSTED,
            $paid >= $total => self::STATUS_PAID,
            default => self::STATUS_PARTIAL,
        };

        // A draft that somehow has money against it stays a draft until posted.
        if ($invoice['status'] === self::STATUS_DRAFT) {
            $status = self::STATUS_DRAFT;
        }

        Database::update('sales_invoices', ['amount_paid' => $paid, 'status' => $status], ['id' => $invoiceId]);
    }

    /** @return array<string,mixed>|null Invoice with its lines and customer. */
    public static function find(int $invoiceId): ?array
    {
        $invoice = Database::first(
            'SELECT si.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar,
                    c.address AS contact_address, c.phone AS contact_phone, c.email AS contact_email,
                    c.cr_number AS contact_cr_number, c.tax_id AS contact_tax_id, c.po_box AS contact_po_box,
                    c.city AS contact_city
             FROM sales_invoices si
             JOIN contacts c ON c.id = si.contact_id
             WHERE si.id = ?',
            [$invoiceId]
        );
        if (!$invoice) {
            return null;
        }

        $invoice['lines'] = Database::all(
            'SELECT sil.*, i.sku, i.name_en AS item_name_en, i.name_ar AS item_name_ar
             FROM sales_invoice_lines sil
             LEFT JOIN items i ON i.id = sil.item_id
             WHERE sil.invoice_id = ? ORDER BY sil.line_no',
            [$invoiceId]
        );
        $invoice['balance_due'] = (int) $invoice['total'] - (int) $invoice['amount_paid'];
        $invoice['is_overdue'] = $invoice['status'] !== self::STATUS_PAID
            && $invoice['status'] !== self::STATUS_VOID
            && $invoice['status'] !== self::STATUS_DRAFT
            && $invoice['due_date'] < date('Y-m-d');

        $invoice['payments'] = Database::all(
            "SELECT p.number, p.payment_date, p.method, p.reference, pa.amount
             FROM payment_allocations pa
             JOIN payments p ON p.id = pa.payment_id
             WHERE pa.doc_type = 'sales_invoice' AND pa.doc_id = ? AND p.status = 'posted'
             ORDER BY p.payment_date",
            [$invoiceId]
        );

        return $invoice;
    }

    /**
     * List invoices with filters and paging.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function listInvoices(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'si.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 'si.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'si.issue_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'si.issue_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(si.number LIKE ? OR c.name_en LIKE ? OR c.name_ar LIKE ? OR si.lpo_number LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term);
        }
        if (!empty($filters['overdue'])) {
            $where[] = "si.due_date < ? AND si.status IN ('posted', 'partial')";
            $params[] = date('Y-m-d');
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM sales_invoices si JOIN contacts c ON c.id = si.contact_id WHERE {$clause}",
            $params,
            0
        );

        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            "SELECT si.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar
             FROM sales_invoices si
             JOIN contacts c ON c.id = si.contact_id
             WHERE {$clause}
             ORDER BY si.issue_date DESC, si.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $row['balance_due'] = (int) $row['total'] - (int) $row['amount_paid'];
            $row['is_overdue'] = in_array($row['status'], [self::STATUS_POSTED, self::STATUS_PARTIAL], true)
                && $row['due_date'] < date('Y-m-d');
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /** Outstanding invoices for a customer, for the receipt allocation screen. */
    public static function openInvoices(int $contactId): array
    {
        return Database::all(
            "SELECT id, number, issue_date, due_date, total, amount_paid,
                    (total - amount_paid) AS balance_due
             FROM sales_invoices
             WHERE contact_id = ? AND status IN ('posted', 'partial') AND total > amount_paid
             ORDER BY due_date, id",
            [$contactId]
        );
    }
}
