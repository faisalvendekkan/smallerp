<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Support\LabourLaw;
use App\Support\Money;
use App\Support\Qatar;
use App\Support\ValidationException;
use App\Support\Wps;

/**
 * Monthly payroll and the WPS submission.
 *
 * The flow mirrors what actually happens in a Doha office each month:
 * open the run, let it pre-fill from the contracts, adjust overtime and
 * deductions, approve it (which books the cost), generate the SIF for the
 * bank, and finally mark it paid once the bank confirms.
 */
final class Payroll
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';

    /**
     * Open a payroll run for a month, pre-filled from employee contracts.
     */
    public static function openRun(int $year, int $month, ?string $payDate = null): int
    {
        if ($month < 1 || $month > 12) {
            throw new ValidationException('Choose a valid month', 'period_month');
        }
        if ($year < 2000 || $year > 2100) {
            throw new ValidationException('Choose a valid year', 'period_year');
        }

        $existing = Database::first(
            'SELECT id FROM payroll_runs WHERE period_year = ? AND period_month = ?',
            [$year, $month]
        );
        if ($existing) {
            throw new ValidationException(sprintf(
                'Payroll for %02d/%d has already been opened.',
                $month,
                $year
            ));
        }

        $employees = Hr::payableEmployees();
        if ($employees === []) {
            throw new ValidationException('There are no active employees to pay');
        }

        // Default to the WPS deadline so the run is dated within the law.
        $payDate ??= Wps::dueDate($year, $month);
        $workingDays = Settings::int('payroll_working_days', 30);
        $periodEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        $periodStart = sprintf('%04d-%02d-01', $year, $month);

        return Database::transaction(static function () use ($year, $month, $payDate, $workingDays, $employees, $periodStart, $periodEnd): int {
            $runId = Database::insert('payroll_runs', [
                'period_year' => $year,
                'period_month' => $month,
                'pay_date' => $payDate,
                'status' => self::STATUS_DRAFT,
                'working_days' => $workingDays,
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($employees as $employee) {
                // Someone who joined mid-month, or left mid-month, is paid for
                // the part of the month they actually worked.
                $joinDate = (string) $employee['join_date'];
                $endDate = $employee['end_date'] ?: null;
                if ($joinDate > $periodEnd) {
                    continue; // Not yet employed in this period.
                }
                if ($endDate !== null && $endDate < $periodStart) {
                    continue; // Already left before this period.
                }

                $daysPaid = $workingDays;
                if ($joinDate > $periodStart || ($endDate !== null && $endDate < $periodEnd)) {
                    $from = max($joinDate, $periodStart);
                    $to = min($endDate ?? $periodEnd, $periodEnd);
                    $worked = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
                    $daysPaid = min($workingDays, $worked);
                }

                $proRata = static fn (int $amount): int => $daysPaid >= $workingDays
                    ? $amount
                    : Money::roundHalfUp($amount * $daysPaid / $workingDays);

                $basic = $proRata((int) $employee['basic_salary']);
                $housing = $proRata((int) $employee['housing_allowance']);
                $transport = $proRata((int) $employee['transport_allowance']);
                $food = $proRata((int) $employee['food_allowance']);
                $other = $proRata((int) $employee['other_allowance']);
                $gross = $basic + $housing + $transport + $food + $other;

                Database::insert('payslips', [
                    'run_id' => $runId,
                    'employee_id' => (int) $employee['id'],
                    'working_days' => $daysPaid,
                    'basic' => $basic,
                    'housing' => $housing,
                    'transport' => $transport,
                    'food' => $food,
                    'other_allowance' => $other,
                    'gross' => $gross,
                    'net' => $gross,
                ]);
            }

            self::recalculate($runId);
            AuditLog::record('payroll.open', 'payroll_run', $runId, ['period' => sprintf('%04d-%02d', $year, $month)]);

            return $runId;
        });
    }

    /**
     * Update one payslip's variable elements (overtime, bonus, deductions).
     */
    public static function updatePayslip(int $payslipId, array $data): void
    {
        $payslip = Database::first(
            'SELECT ps.*, pr.status AS run_status, pr.id AS run_id, pr.working_days AS run_working_days
             FROM payslips ps JOIN payroll_runs pr ON pr.id = ps.run_id
             WHERE ps.id = ?',
            [$payslipId]
        );
        if (!$payslip) {
            throw new ValidationException('That payslip does not exist');
        }
        if ($payslip['run_status'] !== self::STATUS_DRAFT) {
            throw new ValidationException('This payroll run has been approved and can no longer be edited');
        }

        $employee = Database::first('SELECT * FROM employees WHERE id = ?', [(int) $payslip['employee_id']]);
        $workingDays = max(1, (int) $payslip['run_working_days']);

        $daysPaid = max(0, min($workingDays, (int) ($data['working_days'] ?? $payslip['working_days'])));
        $proRata = static fn (int $amount): int => $daysPaid >= $workingDays
            ? $amount
            : Money::roundHalfUp($amount * $daysPaid / $workingDays);

        $basic = $proRata((int) $employee['basic_salary']);
        $housing = $proRata((int) $employee['housing_allowance']);
        $transport = $proRata((int) $employee['transport_allowance']);
        $food = $proRata((int) $employee['food_allowance']);
        $other = $proRata((int) $employee['other_allowance']);

        $overtimeHours = max(0.0, (float) ($data['overtime_hours'] ?? 0));
        $overtimeMultiplier = (float) ($data['overtime_multiplier'] ?? 1.25);
        $overtimeAmount = $overtimeHours > 0
            ? LabourLaw::overtimeAmount(
                (int) $employee['basic_salary'],
                $overtimeHours,
                $overtimeMultiplier,
                max(1, (int) $employee['contract_hours_month'])
            )
            : 0;

        $bonus = max(0, (int) ($data['bonus'] ?? 0));
        $absenceDays = max(0.0, (float) ($data['absence_days'] ?? 0));
        $grossContract = (int) $employee['basic_salary'] + (int) $employee['housing_allowance']
            + (int) $employee['transport_allowance'] + (int) $employee['food_allowance']
            + (int) $employee['other_allowance'];
        $absenceDeduction = $absenceDays > 0
            ? LabourLaw::absenceDeduction($grossContract, $absenceDays)
            : 0;

        $loanDeduction = max(0, (int) ($data['loan_deduction'] ?? 0));
        $otherDeduction = max(0, (int) ($data['other_deduction'] ?? 0));

        $gross = $basic + $housing + $transport + $food + $other + $overtimeAmount + $bonus;
        $deductions = $absenceDeduction + $loanDeduction + $otherDeduction;
        $net = $gross - $deductions;

        // Art. 66 forbids paying a negative wage, and the bank would reject
        // the SIF line anyway.
        if ($net < 0) {
            throw new ValidationException(sprintf(
                'Deductions of %s exceed the gross pay of %s for %s',
                Money::format($deductions, true),
                Money::format($gross, true),
                $employee['name_en']
            ));
        }

        Database::update('payslips', [
            'working_days' => $daysPaid,
            'basic' => $basic,
            'housing' => $housing,
            'transport' => $transport,
            'food' => $food,
            'other_allowance' => $other,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
            'bonus' => $bonus,
            'absence_days' => $absenceDays,
            'absence_deduction' => $absenceDeduction,
            'loan_deduction' => $loanDeduction,
            'other_deduction' => $otherDeduction,
            'gross' => $gross,
            'total_deductions' => $deductions,
            'net' => $net,
            'notes' => mb_substr((string) ($data['notes'] ?? ''), 0, 255),
        ], ['id' => $payslipId]);

        self::recalculate((int) $payslip['run_id']);
    }

    /** Re-total a run from its payslips. */
    public static function recalculate(int $runId): void
    {
        $row = Database::first(
            'SELECT COUNT(*) AS n, COALESCE(SUM(gross), 0) AS g,
                    COALESCE(SUM(total_deductions), 0) AS d, COALESCE(SUM(net), 0) AS net
             FROM payslips WHERE run_id = ?',
            [$runId]
        ) ?? [];

        Database::update('payroll_runs', [
            'employee_count' => (int) ($row['n'] ?? 0),
            'total_gross' => (int) ($row['g'] ?? 0),
            'total_deductions' => (int) ($row['d'] ?? 0),
            'total_net' => (int) ($row['net'] ?? 0),
        ], ['id' => $runId]);
    }

    /** Remove an employee from a draft run, e.g. someone paid separately. */
    public static function removePayslip(int $payslipId): void
    {
        $payslip = Database::first(
            'SELECT ps.run_id, pr.status FROM payslips ps
             JOIN payroll_runs pr ON pr.id = ps.run_id WHERE ps.id = ?',
            [$payslipId]
        );
        if (!$payslip) {
            throw new ValidationException('That payslip does not exist');
        }
        if ($payslip['status'] !== self::STATUS_DRAFT) {
            throw new ValidationException('This payroll run has been approved and can no longer be edited');
        }

        Database::delete('payslips', ['id' => $payslipId]);
        self::recalculate((int) $payslip['run_id']);
    }

    /**
     * Approve a run and book the cost.
     *
     * Dr Salaries / Housing / Transport / Other / Overtime  (gross by component)
     *   Cr Employee Advances    loan repayments recovered
     *   Cr Salaries Payable     net
     *
     * The wage becomes a liability here; it leaves the bank later, when the
     * run is marked paid.
     */
    public static function approve(int $runId): void
    {
        $run = self::find($runId);
        if (!$run) {
            throw new ValidationException('That payroll run does not exist');
        }
        if ($run['status'] !== self::STATUS_DRAFT) {
            throw new ValidationException('Only a draft payroll run can be approved');
        }
        if ($run['payslips'] === []) {
            throw new ValidationException('There are no payslips in this run');
        }
        if ((int) $run['total_net'] <= 0) {
            throw new ValidationException('The net payroll total must be more than zero');
        }

        Database::transaction(static function () use ($run, $runId): void {
            $totals = [
                'salaries_expense' => 0,
                'housing_expense' => 0,
                'transport_expense' => 0,
                'other_allowance_expense' => 0,
                'overtime_expense' => 0,
            ];
            $loanRecovery = 0;
            $otherDeductions = 0;

            foreach ($run['payslips'] as $payslip) {
                $totals['salaries_expense'] += (int) $payslip['basic'] + (int) $payslip['bonus']
                    - (int) $payslip['absence_deduction'];
                $totals['housing_expense'] += (int) $payslip['housing'];
                $totals['transport_expense'] += (int) $payslip['transport'];
                $totals['other_allowance_expense'] += (int) $payslip['food'] + (int) $payslip['other_allowance'];
                $totals['overtime_expense'] += (int) $payslip['overtime_amount'];
                $loanRecovery += (int) $payslip['loan_deduction'];
                $otherDeductions += (int) $payslip['other_deduction'];
            }

            $lines = [];
            foreach ($totals as $systemKey => $amount) {
                if ($amount > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id($systemKey),
                        'debit' => $amount,
                        'credit' => 0,
                        'memo' => $run['period_label'],
                    ];
                }
            }

            if ($loanRecovery > 0) {
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('employee_advances'),
                    'debit' => 0,
                    'credit' => $loanRecovery,
                    'memo' => 'Loan recovery — ' . $run['period_label'],
                ];
            }
            if ($otherDeductions > 0) {
                // Other deductions reduce the cost rather than creating income.
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('salaries_expense'),
                    'debit' => 0,
                    'credit' => $otherDeductions,
                    'memo' => 'Other deductions — ' . $run['period_label'],
                ];
            }

            $lines[] = [
                'account_id' => ChartOfAccounts::id('salaries_payable'),
                'debit' => 0,
                'credit' => (int) $run['total_net'],
                'memo' => 'Net payroll — ' . $run['period_label'],
            ];

            $journalId = Ledger::post(
                (string) $run['pay_date'],
                $lines,
                'Payroll ' . $run['period_label'],
                'payroll_run',
                $runId
            );

            Database::update('payroll_runs', [
                'status' => self::STATUS_APPROVED,
                'journal_id' => $journalId,
                'approved_at' => date('Y-m-d H:i:s'),
            ], ['id' => $runId]);

            AuditLog::record('payroll.approve', 'payroll_run', $runId, [
                'period' => $run['period_label'],
                'net' => Money::toDecimalString((int) $run['total_net']),
            ]);
        });
    }

    /**
     * Mark an approved run paid, once the bank confirms the WPS transfer.
     *
     * Dr Salaries Payable
     *   Cr Bank (the WPS salary account)
     */
    public static function markPaid(int $runId, ?int $bankAccountId = null, ?string $paidDate = null): void
    {
        $run = self::find($runId);
        if (!$run) {
            throw new ValidationException('That payroll run does not exist');
        }
        if ($run['status'] !== self::STATUS_APPROVED) {
            throw new ValidationException('Only an approved payroll run can be marked as paid');
        }

        $bankAccountId ??= ChartOfAccounts::id('wps_bank');
        $paidDate ??= date('Y-m-d');

        Database::transaction(static function () use ($run, $runId, $bankAccountId, $paidDate): void {
            Ledger::post(
                $paidDate,
                [
                    [
                        'account_id' => ChartOfAccounts::id('salaries_payable'),
                        'debit' => (int) $run['total_net'],
                        'credit' => 0,
                        'memo' => 'Payroll paid — ' . $run['period_label'],
                    ],
                    [
                        'account_id' => $bankAccountId,
                        'debit' => 0,
                        'credit' => (int) $run['total_net'],
                        'memo' => 'WPS transfer — ' . $run['period_label'],
                    ],
                ],
                'Payroll payment ' . $run['period_label'],
                'payroll_payment',
                $runId
            );

            Database::update('payroll_runs', [
                'status' => self::STATUS_PAID,
                'paid_at' => date('Y-m-d H:i:s'),
            ], ['id' => $runId]);

            AuditLog::record('payroll.paid', 'payroll_run', $runId, ['period' => $run['period_label']]);
        });
    }

    /**
     * Build the WPS SIF file for a run.
     *
     * @return array{filename:string,content:string}
     */
    public static function generateSif(int $runId, string $paymentType = Wps::PAYMENT_TYPE_NORMAL): array
    {
        $run = self::find($runId);
        if (!$run) {
            throw new ValidationException('That payroll run does not exist');
        }
        if ($run['status'] === self::STATUS_DRAFT) {
            throw new ValidationException('Approve the payroll run before generating the WPS file');
        }

        $records = [];
        foreach ($run['payslips'] as $payslip) {
            $records[] = [
                'qid' => (string) $payslip['qid'],
                'visa_id' => (string) $payslip['visa_number'],
                'name' => (string) $payslip['name_en'],
                'bank_short_name' => (string) ($payslip['bank_short_name'] ?: Qatar::bankShortName((string) $payslip['iban'])),
                'iban' => (string) $payslip['iban'],
                'salary_frequency' => (string) ($payslip['salary_frequency'] ?: Wps::FREQUENCY_MONTHLY),
                'working_days' => (int) $payslip['working_days'],
                'net_salary_dirhams' => (int) $payslip['net'],
                'basic_salary_dirhams' => (int) $payslip['basic'],
                'extra_hours' => (float) $payslip['overtime_hours'],
                'extra_income_dirhams' => (int) $payslip['overtime_amount'] + (int) $payslip['bonus'],
                'deductions_dirhams' => (int) $payslip['total_deductions'],
                'payment_type' => $paymentType,
                'notes' => (string) $payslip['notes'],
            ];
        }

        $employer = Settings::wpsEmployer();
        $createdAt = new \DateTimeImmutable();

        $content = Wps::buildSif(
            $employer,
            $records,
            (int) $run['period_year'],
            (int) $run['period_month'],
            [
                'created_at' => $createdAt,
                'sif_version' => Settings::get('wps_sif_version', Wps::DEFAULT_SIF_VERSION),
                'payment_type' => $paymentType,
            ]
        );

        Database::update('payroll_runs', ['sif_generated_at' => date('Y-m-d H:i:s')], ['id' => $runId]);
        AuditLog::record('payroll.sif', 'payroll_run', $runId, [
            'period' => $run['period_label'],
            'records' => count($records),
        ]);

        return [
            'filename' => Wps::filename(
                (string) $employer['establishment_id'],
                (int) $run['period_year'],
                (int) $run['period_month'],
                $createdAt
            ),
            'content' => $content,
        ];
    }

    /**
     * Check a run would produce a valid SIF, without generating one.
     *
     * Shown on the run screen so problems surface while there is still time to
     * fix them, rather than on the day payroll is due.
     *
     * @return string[]
     */
    public static function wpsIssues(int $runId): array
    {
        $run = self::find($runId);
        if (!$run) {
            return ['That payroll run does not exist'];
        }

        $issues = [];
        if (Settings::get('establishment_id') === '') {
            $issues[] = 'The company Establishment ID (EID) is not set — fill it in under Settings';
        }
        if (!Qatar::isValidIban(Settings::get('wps_iban'))) {
            $issues[] = 'The company WPS bank account (IBAN) is missing or invalid — fill it in under Settings';
        }

        foreach ($run['payslips'] as $payslip) {
            $issues = array_merge($issues, Wps::validateRecord([
                'qid' => (string) $payslip['qid'],
                'name' => (string) $payslip['name_en'],
                'bank_short_name' => (string) ($payslip['bank_short_name'] ?: Qatar::bankShortName((string) $payslip['iban'])),
                'iban' => (string) $payslip['iban'],
                'working_days' => (int) $payslip['working_days'],
                'net_salary_dirhams' => (int) $payslip['net'],
                'basic_salary_dirhams' => (int) $payslip['basic'],
                'deductions_dirhams' => (int) $payslip['total_deductions'],
            ]));
        }

        return $issues;
    }

    public static function find(int $runId): ?array
    {
        $run = Database::first('SELECT * FROM payroll_runs WHERE id = ?', [$runId]);
        if (!$run) {
            return null;
        }

        $run['payslips'] = Database::all(
            'SELECT ps.*, e.code, e.name_en, e.name_ar, e.qid, e.visa_number, e.iban,
                    e.bank_name, e.bank_short_name, e.salary_frequency, e.designation, e.department
             FROM payslips ps
             JOIN employees e ON e.id = ps.employee_id
             WHERE ps.run_id = ?
             ORDER BY e.code',
            [$runId]
        );
        $run['period_label'] = sprintf('%02d/%04d', (int) $run['period_month'], (int) $run['period_year']);
        $run['wps_due_date'] = Wps::dueDate((int) $run['period_year'], (int) $run['period_month']);
        $run['is_late'] = $run['status'] !== self::STATUS_PAID && $run['wps_due_date'] < date('Y-m-d');

        return $run;
    }

    public static function listRuns(int $limit = 36): array
    {
        $rows = Database::all(
            'SELECT * FROM payroll_runs ORDER BY period_year DESC, period_month DESC LIMIT ?',
            [$limit]
        );

        foreach ($rows as &$row) {
            $row['period_label'] = sprintf('%02d/%04d', (int) $row['period_month'], (int) $row['period_year']);
            $row['wps_due_date'] = Wps::dueDate((int) $row['period_year'], (int) $row['period_month']);
            $row['is_late'] = $row['status'] !== self::STATUS_PAID && $row['wps_due_date'] < date('Y-m-d');
        }
        unset($row);

        return $rows;
    }

    /** One payslip with everything a printed slip needs. */
    public static function payslip(int $payslipId): ?array
    {
        $payslip = Database::first(
            'SELECT ps.*, e.code, e.name_en, e.name_ar, e.qid, e.designation, e.department,
                    e.iban, e.bank_name, e.join_date,
                    pr.period_year, pr.period_month, pr.pay_date, pr.status AS run_status
             FROM payslips ps
             JOIN employees e ON e.id = ps.employee_id
             JOIN payroll_runs pr ON pr.id = ps.run_id
             WHERE ps.id = ?',
            [$payslipId]
        );
        if (!$payslip) {
            return null;
        }
        $payslip['period_label'] = sprintf('%02d/%04d', (int) $payslip['period_month'], (int) $payslip['period_year']);

        return $payslip;
    }
}
