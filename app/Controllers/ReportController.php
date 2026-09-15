<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Hr;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Reports;
use App\Services\Settings;
use App\Support\Money;
use App\Support\Qatar;

/** Financial and management reports. */
final class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('reports/index', [
            'title' => __('nav.reports'),
            'integrity' => Ledger::integrityCheck(),
        ]);
    }

    public function trialBalance(Request $request): Response
    {
        $from = $request->date('from') ?? date('Y-01-01');
        $to = $request->date('to') ?? date('Y-m-d');
        $rows = Ledger::trialBalance($from, $to);

        if ($request->string('format') === 'csv') {
            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    $row['code'],
                    $row['name_en'],
                    $row['type'],
                    Money::toDecimalString((int) $row['total_debit']),
                    Money::toDecimalString((int) $row['total_credit']),
                    Money::toDecimalString((int) $row['balance_debit']),
                    Money::toDecimalString((int) $row['balance_credit']),
                ];
            }

            return $this->csv(
                "trial-balance-{$from}-to-{$to}.csv",
                ['Code', 'Account', 'Type', 'Total debit', 'Total credit', 'Balance debit', 'Balance credit'],
                $data
            );
        }

        return $this->view('reports/trial_balance', [
            'title' => 'Trial balance',
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'totalDebit' => array_sum(array_map(static fn ($r) => (int) $r['balance_debit'], $rows)),
            'totalCredit' => array_sum(array_map(static fn ($r) => (int) $r['balance_credit'], $rows)),
        ]);
    }

    public function profitAndLoss(Request $request): Response
    {
        $from = $request->date('from') ?? date('Y-01-01');
        $to = $request->date('to') ?? date('Y-m-d');

        return $this->view('reports/profit_loss', [
            'title' => 'Profit and loss',
            'report' => Reports::profitAndLoss($from, $to),
            'from' => $from,
            'to' => $to,
            'taxRate' => Qatar::CORPORATE_INCOME_TAX_RATE,
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $asOf = $request->date('as_of') ?? date('Y-m-d');

        return $this->view('reports/balance_sheet', [
            'title' => 'Balance sheet',
            'report' => Reports::balanceSheet($asOf),
            'asOf' => $asOf,
            // Gratuity accrues from day one under Art. 54 and is often the
            // largest liability an established SME has never booked.
            'gratuityLiability' => Hr::gratuityLiability()['total'],
        ]);
    }

    public function ageing(Request $request): Response
    {
        $direction = $request->string('direction', 'receivable') === 'payable' ? 'payable' : 'receivable';
        $asOf = $request->date('as_of') ?? date('Y-m-d');
        $report = Reports::ageing($direction, $asOf);

        if ($request->string('format') === 'csv') {
            $data = [];
            foreach ($report['contacts'] as $contact) {
                $data[] = [
                    $contact['name_en'],
                    $contact['phone'],
                    Money::toDecimalString($contact['current']),
                    Money::toDecimalString($contact['d30']),
                    Money::toDecimalString($contact['d60']),
                    Money::toDecimalString($contact['d90']),
                    Money::toDecimalString($contact['d90plus']),
                    Money::toDecimalString($contact['total']),
                ];
            }

            return $this->csv(
                "{$direction}-ageing-{$asOf}.csv",
                ['Name', 'Phone', 'Current', '1-30 days', '31-60 days', '61-90 days', 'Over 90 days', 'Total'],
                $data
            );
        }

        return $this->view('reports/ageing', [
            'title' => $direction === 'receivable' ? 'Receivables ageing' : 'Payables ageing',
            'report' => $report,
            'direction' => $direction,
            'asOf' => $asOf,
        ]);
    }

    public function sales(Request $request): Response
    {
        $from = $request->date('from') ?? date('Y-m-01');
        $to = $request->date('to') ?? date('Y-m-d');

        return $this->view('reports/sales', [
            'title' => 'Sales analysis',
            'byCustomer' => Reports::salesByCustomer($from, $to),
            'byItem' => Reports::salesByItem($from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function tax(Request $request): Response
    {
        $from = $request->date('from') ?? date('Y-m-01');
        $to = $request->date('to') ?? date('Y-m-d');

        return $this->view('reports/tax', [
            'title' => 'Tax summary',
            'report' => Reports::taxReturn($from, $to),
            'from' => $from,
            'to' => $to,
            'taxEnabled' => Settings::bool('tax_enabled'),
        ]);
    }

    /**
     * Documents expiring across the workforce.
     *
     * The single most useful compliance screen for a Qatari SME: a lapsed QID
     * or visa stops an employee working and exposes the company to a fine.
     */
    public function expiries(Request $request): Response
    {
        $withinDays = $request->int('within', Settings::int('expiry_alert_days', 60));
        $rows = Hr::upcomingExpiries($withinDays);

        if ($request->string('format') === 'csv') {
            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    $row['code'],
                    $row['name_en'],
                    $row['designation'],
                    $row['department'],
                    $row['name_en'] ?? '',
                    $row['expiry_date'],
                    $row['days'],
                    strtoupper($row['level']),
                ];
            }

            return $this->csv(
                'document-expiries-' . date('Y-m-d') . '.csv',
                ['Code', 'Employee', 'Designation', 'Department', 'Document', 'Expires', 'Days left', 'Level'],
                $data
            );
        }

        return $this->view('reports/expiries', [
            'title' => 'Document expiries',
            'rows' => $rows,
            'withinDays' => $withinDays,
        ]);
    }

    public function gratuityLiability(Request $request): Response
    {
        $liability = Hr::gratuityLiability();

        if ($request->string('format') === 'csv') {
            $data = [];
            foreach ($liability['rows'] as $row) {
                $data[] = [
                    $row['employee']['code'],
                    $row['employee']['name_en'],
                    $row['employee']['join_date'],
                    $row['service_years'],
                    $row['weeks_per_year'],
                    Money::toDecimalString((int) $row['employee']['basic_salary']),
                    Money::toDecimalString((int) $row['amount']),
                    $row['eligible'] ? 'Yes' : 'Not yet',
                ];
            }

            return $this->csv(
                'gratuity-liability-' . date('Y-m-d') . '.csv',
                ['Code', 'Employee', 'Joined', 'Service years', 'Weeks/year', 'Basic salary',
                 'Gratuity accrued', 'Eligible'],
                $data
            );
        }

        return $this->view('reports/gratuity_liability', [
            'title' => 'End of service liability',
            'liability' => $liability,
            'stockValue' => Inventory::totalStockValue(),
            'tiers' => Settings::gratuityTiers(),
        ]);
    }
}
