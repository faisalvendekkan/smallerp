<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Qatar Labour Law calculations: end-of-service gratuity, leave and notice.
 *
 * Reference: Law No. 14 of 2004 as amended (notably by Law No. 17 of 2020).
 * These are pure functions over integer dirhams and dates so they can be unit
 * tested and reused from the payroll screens and the settlement report alike.
 */
final class LabourLaw
{
    /** The conventional divisor for a daily wage from a monthly salary. */
    public const DAYS_PER_MONTH = 30;
    public const DAYS_PER_YEAR = 365;
    public const DAYS_PER_WEEK = 7;

    /** Art. 54 requires one completed year of service before gratuity is due. */
    public const GRATUITY_MIN_SERVICE_YEARS = 1.0;

    /**
     * Art. 54 sets a floor of three weeks' basic wage per year of service.
     * Many Qatari SMEs improve on it for longer service, so the tiers are
     * defaults that Settings can override.
     *
     * Keyed by completed years of service, value is weeks per year.
     */
    public const DEFAULT_GRATUITY_TIERS = [
        0 => 3.0,
        5 => 4.0,
        10 => 5.0,
    ];

    /** Which weeks-per-year rate applies to a given length of service. */
    public static function weeksPerYearFor(float $serviceYears, ?array $tiers = null): float
    {
        $tiers = $tiers ?: self::DEFAULT_GRATUITY_TIERS;
        ksort($tiers);
        $weeks = 3.0;
        foreach ($tiers as $threshold => $tierWeeks) {
            if ($serviceYears >= (float) $threshold) {
                $weeks = (float) $tierWeeks;
            }
        }

        return $weeks;
    }

    /**
     * End-of-service gratuity under Art. 54.
     *
     * daily basic wage x weeks per year x 7 x years of service, where the
     * daily basic wage is the monthly **basic** salary (allowances excluded)
     * divided by 30, and part years are paid pro rata.
     *
     * Unpaid leave is excluded from service, and sums the worker owes the
     * employer are deducted, as Art. 54 permits.
     *
     * @return array{
     *     eligible:bool, service_days:int, service_years:float,
     *     daily_basic_wage_dirhams:int, weeks_per_year:float,
     *     entitlement_days:float, gross_dirhams:int, deductions_dirhams:int,
     *     net_dirhams:int, notes:string[]
     * }
     */
    public static function gratuity(
        int $basicSalaryDirhams,
        string $startDate,
        string $endDate,
        array $options = []
    ): array {
        $start = self::date($startDate, 'start date');
        $end = self::date($endDate, 'end date');
        if ($end < $start) {
            throw new ValidationException('End date cannot be before the start date', 'end_date');
        }
        if ($basicSalaryDirhams < 0) {
            throw new ValidationException('Basic salary cannot be negative', 'basic_salary');
        }

        $tiers = $options['tiers'] ?? null;
        $unpaidLeaveDays = max(0, (int) ($options['unpaid_leave_days'] ?? 0));
        $deductions = max(0, (int) ($options['deductions_dirhams'] ?? 0));
        $lang = $options['lang'] ?? 'en';

        // The last working day counts as served, hence the +1.
        $calendarDays = (int) $start->diff($end)->days + 1;
        $serviceDays = max(0, $calendarDays - $unpaidLeaveDays);
        $serviceYears = round($serviceDays / self::DAYS_PER_YEAR, 4);

        // Daily wage is kept in hundredths of a dirham so a salary that does
        // not divide by 30 does not lose fractions before it is multiplied up.
        $dailyWageMilli = $basicSalaryDirhams / self::DAYS_PER_MONTH;
        $weeks = self::weeksPerYearFor($serviceYears, $tiers);
        $notes = [];

        if ($serviceYears < self::GRATUITY_MIN_SERVICE_YEARS) {
            $notes[] = $lang === 'ar'
                ? 'أقل من سنة خدمة كاملة — لا تستحق مكافأة نهاية الخدمة (المادة 54).'
                : 'Less than one completed year of service — no gratuity is due (Art. 54).';

            return [
                'eligible' => false,
                'service_days' => $serviceDays,
                'service_years' => $serviceYears,
                'daily_basic_wage_dirhams' => Money::roundHalfUp($dailyWageMilli),
                'weeks_per_year' => $weeks,
                'entitlement_days' => 0.0,
                'gross_dirhams' => 0,
                'deductions_dirhams' => 0,
                'net_dirhams' => 0,
                'notes' => $notes,
            ];
        }

        $entitlementDays = round($weeks * self::DAYS_PER_WEEK * $serviceYears, 2);
        $gross = Money::roundHalfUp($dailyWageMilli * $entitlementDays);
        $net = max(0, $gross - $deductions);

        $notes[] = $lang === 'ar'
            ? sprintf('%s أسابيع من الأجر الأساسي عن كل سنة خدمة.', self::num($weeks))
            : sprintf('%s weeks of basic wage per year of service.', self::num($weeks));
        if ($unpaidLeaveDays > 0) {
            $notes[] = $lang === 'ar'
                ? sprintf('تم استبعاد %d يوم إجازة بدون راتب من مدة الخدمة.', $unpaidLeaveDays)
                : sprintf('%d days of unpaid leave excluded from service.', $unpaidLeaveDays);
        }
        if ($deductions > 0) {
            $notes[] = $lang === 'ar'
                ? 'تم خصم المبالغ المستحقة على العامل (المادة 54).'
                : 'Amounts owed by the worker have been deducted (Art. 54).';
        }

        return [
            'eligible' => true,
            'service_days' => $serviceDays,
            'service_years' => $serviceYears,
            'daily_basic_wage_dirhams' => Money::roundHalfUp($dailyWageMilli),
            'weeks_per_year' => $weeks,
            'entitlement_days' => $entitlementDays,
            'gross_dirhams' => $gross,
            'deductions_dirhams' => $deductions,
            'net_dirhams' => $net,
            'notes' => $notes,
        ];
    }

    /**
     * Paid annual leave under Art. 79: three weeks a year, four after five
     * years of service. Returns 0 in the first year, when leave accrues but
     * is not yet claimable as of right.
     */
    public static function annualLeaveEntitlementDays(float $serviceYears): int
    {
        if ($serviceYears < 1.0) {
            return 0;
        }

        return $serviceYears >= 5.0 ? 28 : 21;
    }

    /**
     * Leave accrued to a date, less what has been taken.
     *
     * Accrual is straight-line over the service period at the Art. 79 rate for
     * the employee's current length of service. During the first year it
     * accrues at the 21-day rate so the provision on the balance sheet is
     * meaningful even though the leave cannot yet be demanded.
     */
    public static function accruedLeaveDays(
        string $startDate,
        string $asOf,
        float $takenDays = 0.0,
        float $carriedForward = 0.0
    ): float {
        $start = self::date($startDate, 'start date');
        $end = self::date($asOf, 'as-of date');
        if ($end < $start) {
            return 0.0;
        }

        $daysServed = (int) $start->diff($end)->days + 1;
        $serviceYears = $daysServed / self::DAYS_PER_YEAR;
        $rate = self::annualLeaveEntitlementDays($serviceYears) ?: 21;
        $accrued = $serviceYears * $rate;

        return round($accrued + $carriedForward - $takenDays, 2);
    }

    /**
     * Cash value of an accrued leave balance.
     *
     * Leave is paid on the full monthly wage (basic plus the allowances that
     * form part of the wage), unlike gratuity which is basic-only.
     */
    public static function leaveEncashment(int $monthlyWageDirhams, float $days): int
    {
        return Money::roundHalfUp(($monthlyWageDirhams / self::DAYS_PER_MONTH) * $days);
    }

    /** Notice period under Art. 49: one month up to five years, two beyond. */
    public static function noticeDays(float $serviceYears): int
    {
        return $serviceYears >= 5.0 ? 60 : 30;
    }

    /**
     * Value of overtime hours.
     *
     * Art. 74: overtime is paid at not less than 125% of the basic hourly
     * rate, rising to 150% for work between 9pm and 6am. Work on the weekly
     * rest day carries 150% plus a compensating day off.
     */
    public static function overtimeAmount(
        int $basicSalaryDirhams,
        float $hours,
        float $multiplier = 1.25,
        int $contractHoursPerMonth = 208
    ): int {
        if ($contractHoursPerMonth <= 0) {
            throw new ValidationException('Contract hours per month must be positive', 'contract_hours');
        }
        $hourlyRate = $basicSalaryDirhams / $contractHoursPerMonth;

        return Money::roundHalfUp($hourlyRate * $hours * $multiplier);
    }

    /** Deduction for unauthorised absence, at the daily rate of the full wage. */
    public static function absenceDeduction(int $monthlyWageDirhams, float $days): int
    {
        return Money::roundHalfUp(($monthlyWageDirhams / self::DAYS_PER_MONTH) * $days);
    }

    private static function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false) {
            throw new ValidationException("Invalid {$label}: expected YYYY-MM-DD");
        }

        return $date;
    }

    /** Format a float without a trailing ".0" for use in sentences. */
    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
