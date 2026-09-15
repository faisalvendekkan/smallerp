<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ValidationException;
use App\Support\Wps;
use Database\Seeder;

/** The WPS SIF file a Qatari employer must submit to its bank each month. */
final class WpsTest extends TestCase
{
    private function employer(): array
    {
        return [
            'establishment_id' => '31245',
            'payer_eid' => '31245',
            'payer_qid' => '27463400157',
            'bank_short_name' => 'QNB',
            'iban' => Seeder::iban('QNBA', '000000000012345678901'),
        ];
    }

    private function record(array $overrides = []): array
    {
        return $overrides + [
            'qid' => '28241600842',
            'visa_id' => '123456789',
            'name' => 'Rajesh Kumar Nair',
            'bank_short_name' => 'CBQ',
            'iban' => Seeder::iban('CBQA', '000000000023456789012'),
            'salary_frequency' => Wps::FREQUENCY_MONTHLY,
            'working_days' => 30,
            'net_salary_dirhams' => 1350000,
            'basic_salary_dirhams' => 900000,
            'extra_hours' => 0,
            'extra_income_dirhams' => 0,
            'deductions_dirhams' => 0,
            'payment_type' => Wps::PAYMENT_TYPE_NORMAL,
            'notes' => '',
        ];
    }

    public function testBuildsAFileWithOneEdrPerEmployeeAndOneScr(): void
    {
        $sif = Wps::buildSif($this->employer(), [$this->record(), $this->record()], 2026, 8);
        $lines = explode("\r\n", trim($sif));

        $this->assertSame(3, count($lines), 'two EDR lines and one SCR line');
        $this->assertTrue(str_starts_with($lines[0], 'EDR,'));
        $this->assertTrue(str_starts_with($lines[1], 'EDR,'));
        $this->assertTrue(str_starts_with($lines[2], 'SCR,'), 'the control line comes last');
    }

    public function testUsesDosLineEndings(): void
    {
        // Bank systems consuming the SIF expect CRLF.
        $sif = Wps::buildSif($this->employer(), [$this->record()], 2026, 8);
        $this->assertTrue(str_contains($sif, "\r\n"), 'CRLF line endings');
        $this->assertTrue(str_ends_with($sif, "\r\n"), 'trailing newline');
    }

    public function testScrCarriesTheTotalsTheBankReconciles(): void
    {
        $records = [
            $this->record(['net_salary_dirhams' => 1350000]),
            $this->record(['net_salary_dirhams' => 650000, 'basic_salary_dirhams' => 450000]),
        ];
        $sif = Wps::buildSif($this->employer(), $records, 2026, 8);
        $scr = explode(',', trim(explode("\r\n", trim($sif))[2]));

        $this->assertSame('SCR', $scr[0]);
        $this->assertSame('31245', $scr[1], 'establishment ID');
        $this->assertSame('202608', $scr[8], 'salary year and month');
        $this->assertSame('20000.00', $scr[9], 'total net salaries');
        $this->assertSame('2', $scr[10], 'record count');
    }

    public function testEdrFieldsAreInTheSpecifiedOrder(): void
    {
        $sif = Wps::buildSif($this->employer(), [$this->record()], 2026, 8);
        $edr = explode(',', explode("\r\n", $sif)[0]);

        $this->assertSame('EDR', $edr[0]);
        $this->assertSame('28241600842', $edr[1], 'employee QID');
        $this->assertSame('123456789', $edr[2], 'visa ID');
        $this->assertSame('Rajesh Kumar Nair', $edr[3], 'name');
        $this->assertSame('CBQ', $edr[4], 'bank short name');
        $this->assertSame('M', $edr[6], 'salary frequency');
        $this->assertSame('30', $edr[7], 'working days');
        $this->assertSame('13500.00', $edr[8], 'net salary');
        $this->assertSame('9000.00', $edr[9], 'basic salary');
    }

    public function testRejectsEveryBadRecordAtOnce(): void
    {
        // Payroll staff must be able to fix all the problems in one pass
        // rather than discovering them one bank rejection at a time.
        $records = [
            $this->record(['qid' => '123', 'name' => 'Bad QID']),
            $this->record(['iban' => 'QA00NOPE', 'name' => 'Bad IBAN']),
            $this->record(['net_salary_dirhams' => 0, 'name' => 'Zero Net']),
        ];

        try {
            Wps::buildSif($this->employer(), $records, 2026, 8);
            $this->assertTrue(false, 'expected the build to fail');
        } catch (ValidationException $e) {
            $messages = implode(' | ', $e->allMessages());
            $this->assertTrue(str_contains($messages, 'Bad QID'), 'reports the bad QID');
            $this->assertTrue(str_contains($messages, 'Bad IBAN'), 'reports the bad IBAN');
            $this->assertTrue(str_contains($messages, 'Zero Net'), 'reports the zero salary');
            $this->assertTrue(count($e->allMessages()) >= 3, 'reports all three at once');
        }
    }

    public function testRejectsAnEmptyRun(): void
    {
        $this->assertThrows(
            fn () => Wps::buildSif($this->employer(), [], 2026, 8),
            'no employee records'
        );
    }

    public function testRequiresCompanyWpsSettings(): void
    {
        $employer = $this->employer();
        $employer['establishment_id'] = '';
        $this->assertThrows(
            fn () => Wps::buildSif($employer, [$this->record()], 2026, 8),
            'Establishment ID'
        );

        $employer = $this->employer();
        $employer['iban'] = 'nonsense';
        $this->assertThrows(
            fn () => Wps::buildSif($employer, [$this->record()], 2026, 8),
            'Company WPS bank account'
        );
    }

    public function testStripsCommasThatWouldBreakTheCsv(): void
    {
        $sif = Wps::buildSif(
            $this->employer(),
            [$this->record(['name' => 'Nair, Rajesh Kumar', 'notes' => "line one\nline two"])],
            2026,
            8
        );
        $edr = explode(',', explode("\r\n", $sif)[0]);

        $this->assertSame(15, count($edr), 'an embedded comma must not add a field');
        $this->assertSame('Nair Rajesh Kumar', $edr[3], 'the comma is replaced, not escaped');
    }

    public function testFilenameFollowsTheConvention(): void
    {
        $at = new \DateTimeImmutable('2026-09-15 14:30:00');
        $this->assertSame('31245202608151430.SIF', Wps::filename('31245', 2026, 8, $at));
    }

    public function testWageDeadlineIsSevenDaysAfterMonthEnd(): void
    {
        // Art. 66 as amended by Law No. 1 of 2015.
        $this->assertSame('2026-09-07', Wps::dueDate(2026, 8));
        $this->assertSame('2027-01-07', Wps::dueDate(2026, 12), 'rolls over the year end');
    }

    public function testCatchesNetBelowBasicLessDeductions(): void
    {
        // A net wage lower than basic minus deductions means something was
        // mis-keyed; the bank would reject the line.
        $problems = Wps::validateRecord($this->record([
            'basic_salary_dirhams' => 900000,
            'net_salary_dirhams' => 500000,
            'deductions_dirhams' => 0,
        ]));
        $this->assertTrue(
            str_contains(implode(' ', $problems), 'less than basic'),
            'flags an impossible net wage'
        );
    }
}
