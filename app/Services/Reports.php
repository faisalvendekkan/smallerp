<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Money;
use App\Support\Qatar;

/**
 * Financial and management reports.
 *
 * Everything here reads from the ledger rather than re-summing the documents,
 * so a report and the trial balance can never disagree.
 */
final class Reports
{
    /**
     * Profit and loss for a period.
     *
     * @return array<string,mixed>
     */
    public static function profitAndLoss(string $from, string $to): array
    {
        $rows = Ledger::trialBalance($from, $to);

        $income = [];
        $costOfSales = [];
        $expenses = [];
        $incomeTotal = 0;
        $cogsTotal = 0;
        $expenseTotal = 0;

        foreach ($rows as $row) {
            $net = (int) $row['total_debit'] - (int) $row['total_credit'];
            if ($row['type'] === 'income') {
                $amount = -$net; // Income is credit-normal.
                $income[] = $row + ['amount' => $amount];
                $incomeTotal += $amount;
            } elseif ($row['type'] === 'expense') {
                if ($row['subtype'] === 'cogs') {
                    $costOfSales[] = $row + ['amount' => $net];
                    $cogsTotal += $net;
                } else {
                    $expenses[] = $row + ['amount' => $net];
                    $expenseTotal += $net;
                }
            }
        }

        $grossProfit = $incomeTotal - $cogsTotal;
        $netProfit = $grossProfit - $expenseTotal;

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'income_total' => $incomeTotal,
            'cost_of_sales' => $costOfSales,
            'cost_of_sales_total' => $cogsTotal,
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $incomeTotal > 0 ? round($grossProfit / $incomeTotal * 100, 1) : 0.0,
            'expenses' => $expenses,
            'expenses_total' => $expenseTotal,
            'net_profit' => $netProfit,
            'net_margin_pct' => $incomeTotal > 0 ? round($netProfit / $incomeTotal * 100, 1) : 0.0,
            // Indicative only: whether a company actually pays this depends on
            // its ownership, and the return is filed with the GTA separately.
            'indicative_income_tax' => $netProfit > 0
                ? Money::percentage($netProfit, Qatar::CORPORATE_INCOME_TAX_RATE)
                : 0,
        ];
    }

    /**
     * Balance sheet as at a date.
     *
     * Retained earnings are derived from cumulative income less expenses
     * rather than stored, so the sheet balances without a year-end close.
     */
    public static function balanceSheet(string $asOf): array
    {
        $rows = Ledger::trialBalance(null, $asOf);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $assetTotal = 0;
        $liabilityTotal = 0;
        $equityTotal = 0;
        $incomeTotal = 0;
        $expenseTotal = 0;

        foreach ($rows as $row) {
            $net = (int) $row['total_debit'] - (int) $row['total_credit'];
            switch ($row['type']) {
                case 'asset':
                    $assets[] = $row + ['amount' => $net];
                    $assetTotal += $net;
                    break;
                case 'liability':
                    $liabilities[] = $row + ['amount' => -$net];
                    $liabilityTotal += -$net;
                    break;
                case 'equity':
                    $equity[] = $row + ['amount' => -$net];
                    $equityTotal += -$net;
                    break;
                case 'income':
                    $incomeTotal += -$net;
                    break;
                case 'expense':
                    $expenseTotal += $net;
                    break;
            }
        }

        $retained = $incomeTotal - $expenseTotal;
        $totalEquity = $equityTotal + $retained;

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'assets_total' => $assetTotal,
            'liabilities' => $liabilities,
            'liabilities_total' => $liabilityTotal,
            'equity' => $equity,
            'equity_total' => $equityTotal,
            'retained_earnings' => $retained,
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $liabilityTotal + $totalEquity,
            'balanced' => $assetTotal === $liabilityTotal + $totalEquity,
        ];
    }

    /**
     * Receivables or payables aged into the usual buckets.
     *
     * 30/60/90 is what a Qatari SME's bank and auditor will both ask for.
     */
    public static function ageing(string $direction = 'receivable', ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $isReceivable = $direction === 'receivable';
        $table = $isReceivable ? 'sales_invoices' : 'purchase_bills';

        $docs = Database::all(
            "SELECT d.*, c.name_en, c.name_ar, c.phone, c.id AS contact_id
             FROM {$table} d
             JOIN contacts c ON c.id = d.contact_id
             WHERE d.status IN ('posted', 'partial') AND d.total > d.amount_paid AND d.issue_date <= ?
             ORDER BY c.name_en, d.due_date",
            [$asOf]
        );

        $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0];
        $byContact = [];
        $total = 0;

        foreach ($docs as $doc) {
            $outstanding = (int) $doc['total'] - (int) $doc['amount_paid'];
            $daysOverdue = (int) ((strtotime($asOf) - strtotime((string) $doc['due_date'])) / 86400);

            $bucket = match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => 'd30',
                $daysOverdue <= 60 => 'd60',
                $daysOverdue <= 90 => 'd90',
                default => 'd90plus',
            };

            $buckets[$bucket] += $outstanding;
            $total += $outstanding;

            $contactId = (int) $doc['contact_id'];
            $byContact[$contactId] ??= [
                'contact_id' => $contactId,
                'name_en' => $doc['name_en'],
                'name_ar' => $doc['name_ar'],
                'phone' => $doc['phone'],
                'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0,
                'total' => 0,
                'documents' => [],
            ];
            $byContact[$contactId][$bucket] += $outstanding;
            $byContact[$contactId]['total'] += $outstanding;
            $byContact[$contactId]['documents'][] = $doc + [
                'outstanding' => $outstanding,
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
            ];
        }

        usort($byContact, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'as_of' => $asOf,
            'direction' => $direction,
            'buckets' => $buckets,
            'contacts' => array_values($byContact),
            'total' => $total,
        ];
    }

    /**
     * Tax return summary for a period.
     *
     * Dormant while Qatar has no VAT, but it means the day the law commences
     * an SME already has the numbers rather than a software migration.
     */
    public static function taxReturn(string $from, string $to): array
    {
        $sales = Database::all(
            "SELECT sil.tax_rate,
                    COALESCE(SUM(sil.line_subtotal), 0) AS taxable,
                    COALESCE(SUM(sil.line_tax), 0) AS tax
             FROM sales_invoice_lines sil
             JOIN sales_invoices si ON si.id = sil.invoice_id
             WHERE si.status <> 'draft' AND si.status <> 'void'
               AND si.issue_date BETWEEN ? AND ?
             GROUP BY sil.tax_rate ORDER BY sil.tax_rate DESC",
            [$from, $to]
        );

        $purchases = Database::all(
            "SELECT pbl.tax_rate,
                    COALESCE(SUM(pbl.line_subtotal), 0) AS taxable,
                    COALESCE(SUM(pbl.line_tax), 0) AS tax
             FROM purchase_bill_lines pbl
             JOIN purchase_bills pb ON pb.id = pbl.bill_id
             WHERE pb.status <> 'draft' AND pb.status <> 'void'
               AND pb.issue_date BETWEEN ? AND ?
             GROUP BY pbl.tax_rate ORDER BY pbl.tax_rate DESC",
            [$from, $to]
        );

        $outputTax = array_sum(array_map(static fn ($r) => (int) $r['tax'], $sales));
        $inputTax = array_sum(array_map(static fn ($r) => (int) $r['tax'], $purchases));

        return [
            'from' => $from,
            'to' => $to,
            'sales' => $sales,
            'purchases' => $purchases,
            'total_sales' => array_sum(array_map(static fn ($r) => (int) $r['taxable'], $sales)),
            'total_purchases' => array_sum(array_map(static fn ($r) => (int) $r['taxable'], $purchases)),
            'output_tax' => $outputTax,
            'input_tax' => $inputTax,
            'net_payable' => $outputTax - $inputTax,
            'vat_in_force' => Qatar::VAT_IN_FORCE,
            'notice' => Qatar::taxNotice(\App\Core\Lang::locale()),
        ];
    }

    /** Sales by customer over a period. */
    public static function salesByCustomer(string $from, string $to, int $limit = 50): array
    {
        return Database::all(
            "SELECT c.id, c.name_en, c.name_ar, c.phone,
                    COUNT(si.id) AS invoice_count,
                    COALESCE(SUM(si.subtotal), 0) AS net_sales,
                    COALESCE(SUM(si.total), 0) AS total_sales,
                    COALESCE(SUM(si.total - si.amount_paid), 0) AS outstanding
             FROM sales_invoices si
             JOIN contacts c ON c.id = si.contact_id
             WHERE si.status <> 'draft' AND si.status <> 'void' AND si.issue_date BETWEEN ? AND ?
             GROUP BY c.id, c.name_en, c.name_ar, c.phone
             ORDER BY total_sales DESC
             LIMIT ?",
            [$from, $to, $limit]
        );
    }

    /** Sales by item, with gross margin at weighted average cost. */
    public static function salesByItem(string $from, string $to, int $limit = 50): array
    {
        $rows = Database::all(
            "SELECT i.id, i.sku, i.name_en, i.name_ar, i.uom,
                    COALESCE(SUM(sil.quantity), 0) AS quantity_sold,
                    COALESCE(SUM(sil.line_subtotal), 0) AS net_sales
             FROM sales_invoice_lines sil
             JOIN sales_invoices si ON si.id = sil.invoice_id
             JOIN items i ON i.id = sil.item_id
             WHERE si.status <> 'draft' AND si.status <> 'void' AND si.issue_date BETWEEN ? AND ?
             GROUP BY i.id, i.sku, i.name_en, i.name_ar, i.uom
             ORDER BY net_sales DESC
             LIMIT ?",
            [$from, $to, $limit]
        );

        foreach ($rows as &$row) {
            $cost = Inventory::averageCost((int) $row['id'], $to);
            $row['cost_of_sales'] = Money::multiply($cost, (float) $row['quantity_sold']);
            $row['gross_profit'] = (int) $row['net_sales'] - $row['cost_of_sales'];
            $row['margin_pct'] = (int) $row['net_sales'] > 0
                ? round($row['gross_profit'] / (int) $row['net_sales'] * 100, 1)
                : 0.0;
        }
        unset($row);

        return $rows;
    }

    /** Monthly revenue and expense, for the dashboard chart. */
    public static function monthlyTrend(int $months = 12): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = date('Y-m-01', strtotime("-{$i} months"));
            $end = date('Y-m-t', strtotime($start));
            $totals = Ledger::totalsByType($start, $end);
            $out[] = [
                'month' => $start,
                'label' => date('M y', strtotime($start)),
                'income' => $totals['income'],
                'expense' => $totals['expense'],
                'profit' => $totals['income'] - $totals['expense'],
            ];
        }

        return $out;
    }

    /**
     * Everything the dashboard shows, in one pass.
     */
    public static function dashboard(): array
    {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $yearStart = date('Y-01-01');

        $receivable = (int) Database::value(
            "SELECT COALESCE(SUM(total - amount_paid), 0) FROM sales_invoices
             WHERE status IN ('posted', 'partial')",
            [],
            0
        );
        $payable = (int) Database::value(
            "SELECT COALESCE(SUM(total - amount_paid), 0) FROM purchase_bills
             WHERE status IN ('posted', 'partial')",
            [],
            0
        );
        $overdueReceivable = (int) Database::value(
            "SELECT COALESCE(SUM(total - amount_paid), 0) FROM sales_invoices
             WHERE status IN ('posted', 'partial') AND due_date < ?",
            [$today],
            0
        );

        $cash = 0;
        foreach (Payments::cashAccounts() as $account) {
            $cash += Ledger::accountBalance((int) $account['id']);
        }

        $monthTotals = Ledger::totalsByType($monthStart, $today);
        $yearTotals = Ledger::totalsByType($yearStart, $today);

        $expiries = Hr::upcomingExpiries();
        $lowStock = Inventory::lowStock();

        return [
            'cash' => $cash,
            'receivable' => $receivable,
            'payable' => $payable,
            'overdue_receivable' => $overdueReceivable,
            'month_income' => $monthTotals['income'],
            'month_expense' => $monthTotals['expense'],
            'month_profit' => $monthTotals['income'] - $monthTotals['expense'],
            'year_income' => $yearTotals['income'],
            'year_profit' => $yearTotals['income'] - $yearTotals['expense'],
            'stock_value' => Inventory::totalStockValue(),
            'expiries' => $expiries,
            'critical_expiries' => array_values(array_filter(
                $expiries,
                static fn (array $e): bool => in_array($e['level'], ['expired', 'critical'], true)
            )),
            'low_stock' => $lowStock,
            'post_dated_cheques' => Payments::postDatedCheques(),
            'draft_invoices' => (int) Database::value(
                "SELECT COUNT(*) FROM sales_invoices WHERE status = 'draft'",
                [],
                0
            ),
            'employee_count' => (int) Database::value(
                "SELECT COUNT(*) FROM employees WHERE status <> 'terminated'",
                [],
                0
            ),
            'unpaid_payroll' => Database::all(
                "SELECT * FROM payroll_runs WHERE status <> 'paid' ORDER BY period_year DESC, period_month DESC LIMIT 3"
            ),
            'trend' => self::monthlyTrend(6),
            'recent_invoices' => Database::all(
                "SELECT si.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar
                 FROM sales_invoices si JOIN contacts c ON c.id = si.contact_id
                 ORDER BY si.id DESC LIMIT 5"
            ),
        ];
    }

    /**
     * Statement of account for one contact.
     *
     * The document a Qatari SME emails a customer when chasing payment.
     */
    public static function contactStatement(int $contactId, string $from, string $to): array
    {
        $contact = Database::first('SELECT * FROM contacts WHERE id = ?', [$contactId]);
        if (!$contact) {
            return [];
        }

        $isSupplier = $contact['kind'] === 'supplier';
        $docTable = $isSupplier ? 'purchase_bills' : 'sales_invoices';
        $direction = $isSupplier ? 'out' : 'in';

        $documents = Database::all(
            "SELECT number, issue_date AS entry_date, total AS amount, 'invoice' AS entry_type, due_date
             FROM {$docTable}
             WHERE contact_id = ? AND status <> 'draft' AND status <> 'void' AND issue_date BETWEEN ? AND ?",
            [$contactId, $from, $to]
        );

        $payments = Database::all(
            "SELECT number, payment_date AS entry_date, amount, 'payment' AS entry_type, NULL AS due_date
             FROM payments
             WHERE contact_id = ? AND direction = ? AND status = 'posted' AND payment_date BETWEEN ? AND ?",
            [$contactId, $direction, $from, $to]
        );

        $entries = array_merge($documents, $payments);
        usort($entries, static fn (array $a, array $b): int => [$a['entry_date'], $a['number']] <=> [$b['entry_date'], $b['number']]);

        // Opening balance: everything before the statement period.
        $openingInvoices = (int) Database::value(
            "SELECT COALESCE(SUM(total), 0) FROM {$docTable}
             WHERE contact_id = ? AND status <> 'draft' AND status <> 'void' AND issue_date < ?",
            [$contactId, $from],
            0
        );
        $openingPayments = (int) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE contact_id = ? AND direction = ? AND status = 'posted' AND payment_date < ?",
            [$contactId, $direction, $from],
            0
        );

        $balance = (int) $contact['opening_balance'] + $openingInvoices - $openingPayments;
        $opening = $balance;

        foreach ($entries as &$entry) {
            $amount = (int) $entry['amount'];
            if ($entry['entry_type'] === 'invoice') {
                $entry['debit'] = $amount;
                $entry['credit'] = 0;
                $balance += $amount;
            } else {
                $entry['debit'] = 0;
                $entry['credit'] = $amount;
                $balance -= $amount;
            }
            $entry['balance'] = $balance;
        }
        unset($entry);

        return [
            'contact' => $contact,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'entries' => $entries,
            'closing_balance' => $balance,
        ];
    }
}
