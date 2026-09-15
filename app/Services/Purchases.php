<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Support\Money;
use App\Support\ValidationException;

/**
 * Supplier bills.
 *
 * The mirror of Sales: a draft is editable, posting books the payable and
 * receives the stock, and a mistake is corrected by voiding rather than
 * editing.
 */
final class Purchases
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    public static function saveBill(array $header, array $lineRows, ?int $billId = null): int
    {
        $contactId = (int) ($header['contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new ValidationException('Choose a supplier', 'contact_id');
        }
        $contact = Database::first('SELECT * FROM contacts WHERE id = ?', [$contactId]);
        if (!$contact) {
            throw new ValidationException('That supplier does not exist', 'contact_id');
        }
        if (!in_array($contact['kind'], ['supplier', 'both'], true)) {
            throw new ValidationException('That contact is a customer, not a supplier', 'contact_id');
        }

        $issueDate = (string) ($header['issue_date'] ?? date('Y-m-d'));
        $dueDate = (string) ($header['due_date'] ?? '');
        if ($dueDate === '') {
            $dueDate = date('Y-m-d', strtotime($issueDate . ' +' . (int) $contact['payment_terms_days'] . ' days'));
        }
        if ($dueDate < $issueDate) {
            throw new ValidationException('The due date cannot be before the bill date', 'due_date');
        }

        $supplierInvoiceNo = trim((string) ($header['supplier_invoice_no'] ?? ''));
        // The supplier's own invoice number is the natural duplicate check --
        // paying the same bill twice is a real and expensive mistake.
        if ($supplierInvoiceNo !== '') {
            $duplicate = Database::first(
                "SELECT number FROM purchase_bills
                 WHERE contact_id = ? AND supplier_invoice_no = ? AND status <> 'void'"
                . ($billId ? ' AND id <> ?' : ''),
                $billId ? [$contactId, $supplierInvoiceNo, $billId] : [$contactId, $supplierInvoiceNo]
            );
            if ($duplicate) {
                throw new ValidationException(sprintf(
                    'Supplier invoice %s is already recorded on bill %s',
                    $supplierInvoiceNo,
                    $duplicate['number']
                ), 'supplier_invoice_no');
            }
        }

        $lines = DocumentTotals::prepareLines($lineRows, Settings::bool('prices_include_tax'));
        $totals = DocumentTotals::document($lines);

        return Database::transaction(static function () use ($header, $lines, $totals, $billId, $contactId, $issueDate, $dueDate, $supplierInvoiceNo): int {
            $data = [
                'contact_id' => $contactId,
                'supplier_invoice_no' => mb_substr($supplierInvoiceNo, 0, 60),
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'warehouse_id' => (int) ($header['warehouse_id'] ?? 0) ?: null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'notes' => $header['notes'] ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($billId === null) {
                $data['number'] = Numbering::next('purchase_bill', $issueDate);
                $data['status'] = self::STATUS_DRAFT;
                $data['created_by'] = Auth::id();
                $data['created_at'] = date('Y-m-d H:i:s');
                $billId = Database::insert('purchase_bills', $data);
                AuditLog::record('bill.create', 'purchase_bill', $billId, ['number' => $data['number']]);
            } else {
                $existing = Database::first('SELECT * FROM purchase_bills WHERE id = ?', [$billId]);
                if (!$existing) {
                    throw new ValidationException('That bill does not exist');
                }
                if ($existing['status'] !== self::STATUS_DRAFT) {
                    throw new ValidationException(
                        'Bill ' . $existing['number'] . ' has been posted and can no longer be edited.'
                    );
                }
                Database::update('purchase_bills', $data, ['id' => $billId]);
                Database::delete('purchase_bill_lines', ['bill_id' => $billId]);
                AuditLog::record('bill.update', 'purchase_bill', $billId);
            }

            foreach ($lines as $line) {
                Database::insert('purchase_bill_lines', [
                    'bill_id' => $billId,
                    'item_id' => $line['item_id'],
                    'account_id' => $line['account_id'],
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

            return $billId;
        });
    }

    /**
     * Post a bill.
     *
     * Dr Inventory / the expense account   net of tax, per line
     * Dr Input Tax Recoverable             tax (zero until VAT commences)
     *   Cr Accounts Payable                total
     */
    public static function post(int $billId): void
    {
        $bill = self::find($billId);
        if (!$bill) {
            throw new ValidationException('That bill does not exist');
        }
        if ($bill['status'] !== self::STATUS_DRAFT) {
            throw new ValidationException('Only a draft bill can be posted');
        }
        if ((int) $bill['total'] <= 0) {
            throw new ValidationException('A bill must be for more than zero');
        }

        Database::transaction(static function () use ($bill, $billId): void {
            $lines = [];
            $contactId = (int) $bill['contact_id'];
            $debitsByAccount = [];
            $warehouseId = (int) ($bill['warehouse_id'] ?: 0);

            foreach ($bill['lines'] as $line) {
                $item = $line['item_id']
                    ? Database::first('SELECT * FROM items WHERE id = ?', [(int) $line['item_id']])
                    : null;

                $tracksStock = $item && (int) $item['track_stock'] === 1;

                if ($line['account_id']) {
                    // An expense bill (rent, Kahramaa, fuel) posted straight to
                    // the account the user chose on the line.
                    $accountId = (int) $line['account_id'];
                } elseif ($tracksStock) {
                    $accountId = $item['inventory_account_id']
                        ? (int) $item['inventory_account_id']
                        : ChartOfAccounts::id('inventory');
                } elseif ($item && $item['expense_account_id']) {
                    $accountId = (int) $item['expense_account_id'];
                } else {
                    $accountId = ChartOfAccounts::id('purchases');
                }

                $debitsByAccount[$accountId] = ($debitsByAccount[$accountId] ?? 0) + (int) $line['line_subtotal'];

                if ($tracksStock) {
                    if ($warehouseId === 0) {
                        $warehouseId = Inventory::defaultWarehouseId();
                    }
                    $quantity = (float) $line['quantity'];
                    // Receive at the net unit cost actually paid, so the
                    // weighted average reflects this purchase.
                    $unitCost = $quantity > 0
                        ? Money::roundHalfUp((int) $line['line_subtotal'] / $quantity)
                        : 0;

                    Inventory::move(
                        (int) $item['id'],
                        $warehouseId,
                        $quantity,
                        $unitCost,
                        (string) $bill['issue_date'],
                        'purchase_bill',
                        $billId,
                        'Bill ' . $bill['number']
                    );
                }
            }

            foreach ($debitsByAccount as $accountId => $amount) {
                $lines[] = [
                    'account_id' => (int) $accountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Bill ' . $bill['number'],
                    'contact_id' => $contactId,
                ];
            }

            if ((int) $bill['tax_total'] > 0) {
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('input_tax'),
                    'debit' => (int) $bill['tax_total'],
                    'credit' => 0,
                    'memo' => 'Tax on ' . $bill['number'],
                    'contact_id' => $contactId,
                ];
            }

            $lines[] = [
                'account_id' => ChartOfAccounts::id('accounts_payable'),
                'debit' => 0,
                'credit' => (int) $bill['total'],
                'memo' => 'Bill ' . $bill['number'],
                'contact_id' => $contactId,
            ];

            $journalId = Ledger::post(
                (string) $bill['issue_date'],
                $lines,
                'Purchase bill ' . $bill['number'],
                'purchase_bill',
                $billId
            );

            Database::update('purchase_bills', [
                'status' => self::STATUS_POSTED,
                'journal_id' => $journalId,
                'posted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $billId]);

            AuditLog::record('bill.post', 'purchase_bill', $billId, [
                'number' => $bill['number'],
                'total' => Money::toDecimalString((int) $bill['total']),
            ]);
        });
    }

    public static function void(int $billId, string $reason): void
    {
        $bill = self::find($billId);
        if (!$bill) {
            throw new ValidationException('That bill does not exist');
        }
        if ($bill['status'] === self::STATUS_VOID) {
            throw new ValidationException('That bill is already void');
        }
        if ((int) $bill['amount_paid'] > 0) {
            throw new ValidationException('This bill has payments against it. Void the payments first.');
        }
        if (trim($reason) === '') {
            throw new ValidationException('Give a reason for voiding the bill', 'void_reason');
        }

        Database::transaction(static function () use ($bill, $billId, $reason): void {
            if ($bill['journal_id']) {
                Ledger::reverse(
                    (int) $bill['journal_id'],
                    date('Y-m-d'),
                    'Void of bill ' . $bill['number'] . ' — ' . $reason
                );
            }

            // Returning received stock can push the balance negative if it has
            // already been sold on, which would corrupt the valuation.
            foreach ($bill['lines'] as $line) {
                if (!$line['item_id']) {
                    continue;
                }
                $quantity = (float) $line['quantity'];
                if ($quantity > 0 && Inventory::quantityOnHand((int) $line['item_id']) < $quantity) {
                    throw new ValidationException(sprintf(
                        'Stock received on this bill has already been sold, so it cannot be voided. '
                        . 'Raise a debit note for %s instead.',
                        $line['description'] ?: ('item #' . $line['item_id'])
                    ));
                }
            }
            Inventory::reverseSource('purchase_bill', $billId);

            Database::update('purchase_bills', [
                'status' => self::STATUS_VOID,
                'voided_at' => date('Y-m-d H:i:s'),
                'void_reason' => mb_substr($reason, 0, 255),
            ], ['id' => $billId]);

            AuditLog::record('bill.void', 'purchase_bill', $billId, [
                'number' => $bill['number'],
                'reason' => $reason,
            ]);
        });
    }

    public static function refreshPaymentStatus(int $billId): void
    {
        $bill = Database::first('SELECT * FROM purchase_bills WHERE id = ?', [$billId]);
        if (!$bill || $bill['status'] === self::STATUS_VOID) {
            return;
        }

        $paid = (int) Database::value(
            "SELECT COALESCE(SUM(pa.amount), 0)
             FROM payment_allocations pa
             JOIN payments p ON p.id = pa.payment_id
             WHERE pa.doc_type = 'purchase_bill' AND pa.doc_id = ? AND p.status = 'posted'",
            [$billId],
            0
        );

        $total = (int) $bill['total'];
        $status = match (true) {
            $paid <= 0 => self::STATUS_POSTED,
            $paid >= $total => self::STATUS_PAID,
            default => self::STATUS_PARTIAL,
        };
        if ($bill['status'] === self::STATUS_DRAFT) {
            $status = self::STATUS_DRAFT;
        }

        Database::update('purchase_bills', ['amount_paid' => $paid, 'status' => $status], ['id' => $billId]);
    }

    public static function find(int $billId): ?array
    {
        $bill = Database::first(
            'SELECT pb.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar,
                    c.address AS contact_address, c.phone AS contact_phone, c.cr_number AS contact_cr_number
             FROM purchase_bills pb
             JOIN contacts c ON c.id = pb.contact_id
             WHERE pb.id = ?',
            [$billId]
        );
        if (!$bill) {
            return null;
        }

        $bill['lines'] = Database::all(
            'SELECT pbl.*, i.sku, i.name_en AS item_name_en, i.name_ar AS item_name_ar,
                    a.code AS account_code, a.name_en AS account_name_en
             FROM purchase_bill_lines pbl
             LEFT JOIN items i ON i.id = pbl.item_id
             LEFT JOIN accounts a ON a.id = pbl.account_id
             WHERE pbl.bill_id = ? ORDER BY pbl.line_no',
            [$billId]
        );
        $bill['balance_due'] = (int) $bill['total'] - (int) $bill['amount_paid'];
        $bill['is_overdue'] = in_array($bill['status'], [self::STATUS_POSTED, self::STATUS_PARTIAL], true)
            && $bill['due_date'] < date('Y-m-d');

        $bill['payments'] = Database::all(
            "SELECT p.number, p.payment_date, p.method, p.reference, pa.amount
             FROM payment_allocations pa
             JOIN payments p ON p.id = pa.payment_id
             WHERE pa.doc_type = 'purchase_bill' AND pa.doc_id = ? AND p.status = 'posted'
             ORDER BY p.payment_date",
            [$billId]
        );

        return $bill;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public static function listBills(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'pb.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 'pb.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'pb.issue_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'pb.issue_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(pb.number LIKE ? OR pb.supplier_invoice_no LIKE ? OR c.name_en LIKE ? OR c.name_ar LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM purchase_bills pb JOIN contacts c ON c.id = pb.contact_id WHERE {$clause}",
            $params,
            0
        );

        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            "SELECT pb.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar
             FROM purchase_bills pb
             JOIN contacts c ON c.id = pb.contact_id
             WHERE {$clause}
             ORDER BY pb.issue_date DESC, pb.id DESC
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

    public static function openBills(int $contactId): array
    {
        return Database::all(
            "SELECT id, number, supplier_invoice_no, issue_date, due_date, total, amount_paid,
                    (total - amount_paid) AS balance_due
             FROM purchase_bills
             WHERE contact_id = ? AND status IN ('posted', 'partial') AND total > amount_paid
             ORDER BY due_date, id",
            [$contactId]
        );
    }
}
