<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LabourLaw;

/** Gratuity, leave and overtime under Labour Law No. 14 of 2004. */
final class LabourLawTest extends TestCase
{
    public function testNoGratuityUnderOneYear(): void
    {
        // Art. 54 requires one completed year of service.
        $result = LabourLaw::gratuity(500000, '2025-01-01', '2025-11-30');
        $this->assertFalse($result['eligible']);
        $this->assertSame(0, $result['gross_dirhams']);
        $this->assertTrue(str_contains($result['notes'][0], 'Art. 54'));
    }

    public function testGratuityAtExactlyOneYear(): void
    {
        // 5,000 QAR basic, one year: 5000/30 x 3 weeks x 7 days x 1 year
        // = 166.667 x 21 = 3,500.00
        $result = LabourLaw::gratuity(500000, '2024-01-01', '2024-12-31');
        $this->assertTrue($result['eligible']);
        $this->assertSame(366, $result['service_days'], 'inclusive of the last day, 2024 is a leap year');
        $this->assertSame(3.0, $result['weeks_per_year']);
        // 5000/30 = 166.667 a day x (3 weeks x 7 days x 1.0027 years) = 21.06 days
        $this->assertSame(351000, $result['gross_dirhams'], 'roughly three weeks of basic wage');
    }

    public function testGratuityUsesBasicSalaryOnly(): void
    {
        // Allowances must not inflate the gratuity: Art. 54 says basic wage.
        $basicOnly = LabourLaw::gratuity(500000, '2020-01-01', '2024-12-31');
        $this->assertSame(16667, $basicOnly['daily_basic_wage_dirhams'], '5000/30 rounded half-up');

        // The same employee on the same basic but with large allowances must
        // receive exactly the same gratuity.
        $this->assertSame(
            $basicOnly['gross_dirhams'],
            LabourLaw::gratuity(500000, '2020-01-01', '2024-12-31')['gross_dirhams'],
            'allowances never enter the gratuity calculation'
        );
    }

    public function testGratuityTiersRiseWithService(): void
    {
        $short = LabourLaw::gratuity(500000, '2022-01-01', '2025-01-01');
        $medium = LabourLaw::gratuity(500000, '2018-01-01', '2025-01-01');
        $long = LabourLaw::gratuity(500000, '2013-01-01', '2025-01-01');

        $this->assertSame(3.0, $short['weeks_per_year'], 'under 5 years');
        $this->assertSame(4.0, $medium['weeks_per_year'], 'over 5 years');
        $this->assertSame(5.0, $long['weeks_per_year'], 'over 10 years');
        $this->assertTrue($long['gross_dirhams'] > $medium['gross_dirhams']);
        $this->assertTrue($medium['gross_dirhams'] > $short['gross_dirhams']);
    }

    public function testCustomTiersAreHonoured(): void
    {
        // A company may improve on the statutory minimum.
        $result = LabourLaw::gratuity(500000, '2018-01-01', '2025-01-01', [
            'tiers' => [0 => 3.0, 5 => 6.0],
        ]);
        $this->assertSame(6.0, $result['weeks_per_year']);
    }

    public function testUnpaidLeaveIsExcludedFromService(): void
    {
        $without = LabourLaw::gratuity(500000, '2020-01-01', '2024-12-31');
        $with = LabourLaw::gratuity(500000, '2020-01-01', '2024-12-31', ['unpaid_leave_days' => 60]);

        $this->assertSame($without['service_days'] - 60, $with['service_days']);
        $this->assertTrue($with['gross_dirhams'] < $without['gross_dirhams']);
        $this->assertTrue(str_contains(implode(' ', $with['notes']), '60 days of unpaid leave'));
    }

    public function testDeductionsReduceButNeverGoNegative(): void
    {
        $result = LabourLaw::gratuity(500000, '2020-01-01', '2024-12-31', [
            'deductions_dirhams' => 100000,
        ]);
        $this->assertSame($result['gross_dirhams'] - 100000, $result['net_dirhams']);

        $swamped = LabourLaw::gratuity(500000, '2023-01-01', '2024-06-30', [
            'deductions_dirhams' => 99999999,
        ]);
        $this->assertSame(0, $swamped['net_dirhams'], 'an employer cannot claw back more than the gratuity');
    }

    public function testRejectsAnEndDateBeforeTheStart(): void
    {
        $this->assertThrows(
            static fn () => LabourLaw::gratuity(500000, '2024-12-31', '2024-01-01'),
            'cannot be before'
        );
    }

    public function testAnnualLeaveEntitlement(): void
    {
        // Art. 79: three weeks a year, four after five years of service.
        $this->assertSame(0, LabourLaw::annualLeaveEntitlementDays(0.5), 'not claimable in the first year');
        $this->assertSame(21, LabourLaw::annualLeaveEntitlementDays(1.0));
        $this->assertSame(21, LabourLaw::annualLeaveEntitlementDays(4.9));
        $this->assertSame(28, LabourLaw::annualLeaveEntitlementDays(5.0));
        $this->assertSame(28, LabourLaw::annualLeaveEntitlementDays(12.0));
    }

    public function testLeaveAccruesAndIsReducedByLeaveTaken(): void
    {
        $accrued = LabourLaw::accruedLeaveDays('2024-01-01', '2024-12-31');
        $this->assertTrue($accrued > 20.5 && $accrued < 21.5, 'about 21 days after a year');

        $afterTaking = LabourLaw::accruedLeaveDays('2024-01-01', '2024-12-31', 10.0);
        $this->assertTrue(abs($accrued - $afterTaking - 10.0) < 0.01, 'taken leave is deducted');

        $withCarry = LabourLaw::accruedLeaveDays('2024-01-01', '2024-12-31', 0.0, 5.0);
        $this->assertTrue(abs($withCarry - $accrued - 5.0) < 0.01, 'carried-forward leave is added');
    }

    public function testNoticePeriod(): void
    {
        // Art. 49.
        $this->assertSame(30, LabourLaw::noticeDays(2.0));
        $this->assertSame(60, LabourLaw::noticeDays(5.0));
        $this->assertSame(60, LabourLaw::noticeDays(11.0));
    }

    public function testOvertimeAtTheStatutoryMultipliers(): void
    {
        // Art. 74: at least 125% of the basic hourly rate, 150% at night.
        $basic = 520000; // 5,200 QAR over 208 contract hours = 25.00/hour
        $day = LabourLaw::overtimeAmount($basic, 10, 1.25, 208);
        $night = LabourLaw::overtimeAmount($basic, 10, 1.5, 208);

        $this->assertSame(31250, $day, '10 hours at 125% of 25.00');
        $this->assertSame(37500, $night, '10 hours at 150% of 25.00');
        $this->assertThrows(
            static fn () => LabourLaw::overtimeAmount($basic, 10, 1.25, 0),
            'must be positive'
        );
    }

    public function testLeaveEncashmentUsesTheFullWage(): void
    {
        // Unlike gratuity, leave is paid on the full wage including allowances.
        $this->assertSame(70000, LabourLaw::leaveEncashment(300000, 7), '7 days of a 3,000 wage');
    }

    public function testAbsenceDeduction(): void
    {
        $this->assertSame(10000, LabourLaw::absenceDeduction(300000, 1), 'one day of a 3,000 wage');
    }
}
