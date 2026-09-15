<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ChartOfAccounts;
use App\Services\Payments;
use App\Services\Payroll;
use App\Services\Settings;
use App\Support\Money;
use App\Support\Wps;

/** Monthly payroll and the WPS submission. */
final class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = Payroll::listRuns();

        // Offer the most recent month that has not been run yet.
        $suggested = new \DateTimeImmutable('first day of last month');
        foreach ($runs as $run) {
            $existing = sprintf('%04d-%02d', (int) $run['period_year'], (int) $run['period_month']);
            if ($existing === $suggested->format('Y-m')) {
                $suggested = new \DateTimeImmutable('first day of this month');
                break;
            }
        }

        return $this->view('payroll/index', [
            'title' => __('nav.payroll'),
            'runs' => $runs,
            'suggestedYear' => (int) $suggested->format('Y'),
            'suggestedMonth' => (int) $suggested->format('n'),
            'wpsConfigured' => Settings::isWpsConfigured(),
            'employer' => Settings::wpsEmployer(),
        ]);
    }

    public function open(Request $request): Response
    {
        $year = $request->int('period_year', (int) date('Y'));
        $month = $request->int('period_month', (int) date('n'));
        $payDate = $request->date('pay_date');

        $runId = Payroll::openRun($year, $month, $payDate);

        return $this->redirect(
            '/payroll/' . $runId,
            'Payroll opened and pre-filled from the employee contracts. Adjust overtime and deductions, then approve it.'
        );
    }

    public function show(Request $request): Response
    {
        $run = $this->findOr404(Payroll::find($request->routeInt('id')), 'payroll run');

        return $this->view('payroll/show', [
            'title' => 'Payroll ' . $run['period_label'],
            'run' => $run,
            'wpsIssues' => Payroll::wpsIssues((int) $run['id']),
            'bankAccounts' => Payments::cashAccounts(),
            'defaultBankAccount' => $this->wpsAccountId(),
            'dueDate' => $run['wps_due_date'],
        ]);
    }

    private function wpsAccountId(): int
    {
        try {
            return ChartOfAccounts::id('wps_bank');
        } catch (\RuntimeException) {
            return ChartOfAccounts::id('bank');
        }
    }

    public function updatePayslip(Request $request): Response
    {
        $runId = $request->routeInt('id');
        Payroll::updatePayslip((int) $request->route('payslipId'), [
            'working_days' => $request->int('working_days'),
            'overtime_hours' => $request->float('overtime_hours'),
            'overtime_multiplier' => $request->float('overtime_multiplier', 1.25),
            'bonus' => $request->money('bonus'),
            'absence_days' => $request->float('absence_days'),
            'loan_deduction' => $request->money('loan_deduction'),
            'other_deduction' => $request->money('other_deduction'),
            'notes' => $request->string('notes'),
        ]);

        return $this->redirect('/payroll/' . $runId, 'Payslip updated.');
    }

    public function removePayslip(Request $request): Response
    {
        $runId = $request->routeInt('id');
        Payroll::removePayslip((int) $request->route('payslipId'));

        return $this->redirect('/payroll/' . $runId, 'Employee removed from this payroll run.');
    }

    public function approve(Request $request): Response
    {
        $runId = $request->routeInt('id');
        Payroll::approve($runId);

        return $this->redirect(
            '/payroll/' . $runId,
            'Payroll approved and the cost booked. Download the WPS file and send it to your bank.'
        );
    }

    public function markPaid(Request $request): Response
    {
        $runId = $request->routeInt('id');
        Payroll::markPaid(
            $runId,
            $request->int('account_id') ?: null,
            $request->date('paid_date')
        );

        return $this->redirect('/payroll/' . $runId, 'Payroll marked as paid.');
    }

    /** Download the SIF file to upload to the bank's WPS portal. */
    public function downloadSif(Request $request): Response
    {
        $runId = $request->routeInt('id');
        $paymentType = $request->string('payment_type', Wps::PAYMENT_TYPE_NORMAL);
        $sif = Payroll::generateSif($runId, $paymentType);

        return Response::download($sif['content'], $sif['filename'], 'text/plain; charset=UTF-8');
    }

    public function export(Request $request): Response
    {
        $run = $this->findOr404(Payroll::find($request->routeInt('id')), 'payroll run');

        $data = [];
        foreach ($run['payslips'] as $payslip) {
            $data[] = [
                $payslip['code'],
                $payslip['name_en'],
                $payslip['qid'],
                $payslip['designation'],
                $payslip['working_days'],
                Money::toDecimalString((int) $payslip['basic']),
                Money::toDecimalString((int) $payslip['housing']),
                Money::toDecimalString((int) $payslip['transport']),
                Money::toDecimalString((int) $payslip['food'] + (int) $payslip['other_allowance']),
                $payslip['overtime_hours'],
                Money::toDecimalString((int) $payslip['overtime_amount']),
                Money::toDecimalString((int) $payslip['bonus']),
                Money::toDecimalString((int) $payslip['gross']),
                Money::toDecimalString((int) $payslip['total_deductions']),
                Money::toDecimalString((int) $payslip['net']),
                $payslip['iban'],
            ];
        }

        return $this->csv(
            'payroll-' . $run['period_year'] . '-' . sprintf('%02d', (int) $run['period_month']) . '.csv',
            ['Code', 'Name', 'QID', 'Designation', 'Days', 'Basic', 'Housing', 'Transport',
             'Other allowances', 'OT hours', 'Overtime', 'Bonus', 'Gross', 'Deductions', 'Net', 'IBAN'],
            $data
        );
    }

    public function printPayslip(Request $request): Response
    {
        $payslip = $this->findOr404(
            Payroll::payslip((int) $request->route('payslipId')),
            'payslip'
        );

        return $this->printView('print/payslip', [
            'title' => 'Payslip ' . $payslip['period_label'] . ' — ' . $payslip['name_en'],
            'payslip' => $payslip,
            'settings' => Settings::all(),
            'backUrl' => url('/payroll/' . $payslip['run_id']),
        ]);
    }
}
