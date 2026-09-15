<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Services\ChartOfAccounts;
use App\Services\Hr;
use App\Services\Ledger;
use App\Services\Payroll;
use App\Services\Settings;
use Database\Seeder;

/** Payroll, the WPS submission and end-of-service settlement. */
final class PayrollTest extends TestCase
{
    private function setUpPayroll(): void
    {
        $this->freshDatabase();
        $this->seedBase();

        Settings::setMany([
            'establishment_id' => '31245',
            'wps_iban' => Seeder::iban('QNBA', '000000000012345678901'),
            'wps_bank_short_name' => 'QNB',
            'wps_payer_qid' => '27463400157',
            'payroll_working_days' => '30',
        ]);

        Hr::save([
            'name_en' => 'Ahmed Hassan', 'qid' => '28581800391',
            'join_date' => '2020-01-01', 'status' => Hr::STATUS_ACTIVE,
            'basic_salary' => 500000, 'housing_allowance' => 200000,
            'transport_allowance' => 100000, 'contract_hours_month' => 208,
            'iban' => Seeder::iban('DOHB', '000000000034567890123'),
        ]);
        Hr::save([
            'name_en' => 'Maria Cruz', 'qid' => '29027000163',
            'join_date' => '2023-06-01', 'status' => Hr::STATUS_ACTIVE,
            'basic_salary' => 400000, 'housing_allowance' => 150000,
            'contract_hours_month' => 208,
            'iban' => Seeder::iban('QNBA', '000000000067890123456'),
        ]);
    }

    public function testOpeningARunPrefillsFromContracts(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        $run = Payroll::find($runId);

        $this->assertSame(2, count($run['payslips']));
        $this->assertSame('draft', $run['status']);
        // 5,000 + 2,000 + 1,000 and 4,000 + 1,500 = 13,500
        $this->assertSame(1350000, (int) $run['total_net']);
    }

    public function testARunCannotBeOpenedTwiceForTheSameMonth(): void
    {
        $this->setUpPayroll();
        Payroll::openRun(2026, 3);
        $this->assertThrows(static fn () => Payroll::openRun(2026, 3), 'already been opened');
    }

    public function testMidMonthJoinerIsProRated(): void
    {
        $this->setUpPayroll();
        Hr::save([
            'name_en' => 'New Starter', 'qid' => '29158600247',
            'join_date' => '2026-03-16', 'status' => Hr::STATUS_ACTIVE,
            'basic_salary' => 300000, 'contract_hours_month' => 208,
            'iban' => Seeder::iban('QIBK', '000000000045678901234'),
        ]);

        $runId = Payroll::openRun(2026, 3);
        $run = Payroll::find($runId);

        $starter = null;
        foreach ($run['payslips'] as $payslip) {
            if ($payslip['name_en'] === 'New Starter') {
                $starter = $payslip;
            }
        }

        $this->assertTrue($starter !== null, 'the new starter is on the run');
        $this->assertSame(16, (int) $starter['working_days'], '16 March to 31 March inclusive');
        $this->assertSame(160000, (int) $starter['basic'], '16/30 of 3,000.00');
    }

    public function testOvertimeIsAddedAtTheStatutoryRate(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        $run = Payroll::find($runId);
        $payslip = $run['payslips'][0];

        Payroll::updatePayslip((int) $payslip['id'], [
            'working_days' => 30,
            'overtime_hours' => 10,
            'overtime_multiplier' => 1.25,
        ]);

        $updated = Payroll::payslip((int) $payslip['id']);
        // 5,000 basic / 208 hours = 24.038/hour; 10 hours at 125% = 300.48
        $this->assertSame(30048, (int) $updated['overtime_amount']);
        $this->assertSame((int) $payslip['gross'] + 30048, (int) $updated['gross']);
    }

    public function testDeductionsCannotExceedGrossPay(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        $run = Payroll::find($runId);

        // Art. 66 forbids a negative wage, and the bank would reject the line.
        $this->assertThrows(
            fn () => Payroll::updatePayslip((int) $run['payslips'][0]['id'], [
                'working_days' => 30,
                'loan_deduction' => 99999999,
            ]),
            'exceed the gross pay'
        );
    }

    public function testApprovingBooksTheCostAndTheLiability(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        Payroll::approve($runId);

        $this->assertSame(900000, Ledger::accountBalance(ChartOfAccounts::id('salaries_expense')), 'basic pay');
        $this->assertSame(350000, Ledger::accountBalance(ChartOfAccounts::id('housing_expense')));
        $this->assertSame(100000, Ledger::accountBalance(ChartOfAccounts::id('transport_expense')));
        $this->assertSame(1350000, Ledger::accountBalance(ChartOfAccounts::id('salaries_payable')), 'net owed to staff');
        $this->assertTrue(Ledger::integrityCheck()['balanced']);
    }

    public function testMarkingPaidMovesTheMoneyOutOfTheBank(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        Payroll::approve($runId);
        Payroll::markPaid($runId, ChartOfAccounts::id('bank'), '2026-04-05');

        $this->assertSame(0, Ledger::accountBalance(ChartOfAccounts::id('salaries_payable')), 'liability cleared');
        $this->assertSame(-1350000, Ledger::accountBalance(ChartOfAccounts::id('bank')), 'cash went out');
        $this->assertSame('paid', Payroll::find($runId)['status']);
        $this->assertTrue(Ledger::integrityCheck()['balanced']);
    }

    public function testAnApprovedRunCannotBeEdited(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        $run = Payroll::find($runId);
        Payroll::approve($runId);

        $this->assertThrows(
            fn () => Payroll::updatePayslip((int) $run['payslips'][0]['id'], ['working_days' => 20]),
            'can no longer be edited'
        );
    }

    public function testSifIsGeneratedForAnApprovedRun(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        Payroll::approve($runId);

        $this->assertSame([], Payroll::wpsIssues($runId), 'no validation problems');

        $sif = Payroll::generateSif($runId);
        $lines = explode("\r\n", trim($sif['content']));

        $this->assertSame(3, count($lines), 'two employees plus the control line');
        $this->assertTrue(str_starts_with($sif['filename'], '31245202603'));
        $this->assertTrue(str_ends_with($sif['filename'], '.SIF'));

        $scr = explode(',', $lines[2]);
        $this->assertSame('202603', $scr[8]);
        $this->assertSame('13500.00', $scr[9], 'the total the bank reconciles against');
    }

    public function testSifIsRefusedForADraftRun(): void
    {
        $this->setUpPayroll();
        $runId = Payroll::openRun(2026, 3);
        $this->assertThrows(static fn () => Payroll::generateSif($runId), 'Approve the payroll run');
    }

    public function testWpsIssuesAreReportedBeforeSubmission(): void
    {
        $this->setUpPayroll();
        // An employee with no IBAN would be rejected by the bank.
        Database::query("UPDATE employees SET iban = '' WHERE name_en = 'Maria Cruz'");

        $runId = Payroll::openRun(2026, 3);
        Payroll::approve($runId);

        $issues = Payroll::wpsIssues($runId);
        $this->assertTrue($issues !== [], 'the problem is surfaced');
        $this->assertTrue(str_contains(implode(' ', $issues), 'Maria Cruz'), 'and names the employee');
    }

    public function testGratuityLiabilityAcrossTheWorkforce(): void
    {
        $this->setUpPayroll();
        $liability = Hr::gratuityLiability();

        $this->assertSame(2, count($liability['rows']));
        $this->assertTrue($liability['total'] > 0);
        foreach ($liability['rows'] as $row) {
            $this->assertTrue($row['eligible'], 'both have over a year of service');
        }
    }

    public function testSettlementPostsAndTerminatesTheEmployee(): void
    {
        $this->setUpPayroll();
        $employee = Database::first("SELECT * FROM employees WHERE name_en = 'Ahmed Hassan'");
        $employeeId = (int) $employee['id'];

        $preview = Hr::settlementPreview($employeeId, '2026-03-31', 'resignation');
        $this->assertTrue($preview['gratuity']['eligible']);
        $this->assertTrue($preview['net_payable'] > 0);
        // Gratuity plus leave encashment, both positive.
        $this->assertTrue($preview['leave_encashment'] > 0, 'accrued leave is paid out');

        Hr::saveSettlement($preview, true);

        $after = Database::first('SELECT * FROM employees WHERE id = ?', [$employeeId]);
        $this->assertSame('terminated', $after['status']);
        $this->assertSame('2026-03-31', $after['end_date']);
        $this->assertSame(
            (int) $preview['net_payable'],
            Ledger::accountBalance(ChartOfAccounts::id('salaries_payable')),
            'the settlement is owed to the leaver'
        );
        $this->assertTrue(Ledger::integrityCheck()['balanced']);
    }

    public function testDuplicateQidIsRefused(): void
    {
        $this->setUpPayroll();
        $this->assertThrows(
            static fn () => Hr::save([
                'name_en' => 'Impostor', 'qid' => '28581800391',
                'join_date' => '2026-01-01', 'basic_salary' => 100000,
            ]),
            'already belongs to'
        );
    }

    public function testEmployeeIbanIsValidatedOnSave(): void
    {
        $this->setUpPayroll();
        $this->assertThrows(
            static fn () => Hr::save([
                'name_en' => 'Bad Bank', 'join_date' => '2026-01-01',
                'basic_salary' => 100000, 'iban' => 'QA00NONSENSE',
            ]),
            'IBAN'
        );
    }
}
