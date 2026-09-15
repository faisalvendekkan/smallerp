<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wage Protection System (WPS) Salary Information File (SIF) builder.
 *
 * Since Law No. 1 of 2015 amended Art. 66 of the Labour Law, employers in
 * Qatar must pay wages through the WPS and submit a SIF to their bank each
 * month. A rejected file holds up an entire month's payroll and exposes the
 * employer to penalties, so this class validates every record up front and
 * reports all the problems at once.
 *
 * The layout below follows the Ministry of Labour / Qatar Central Bank SIF
 * specification: one EDR line per employee, then a single SCR control line
 * carrying the employer details and the totals the bank reconciles against.
 * Banks occasionally publish their own column notes — confirm the layout with
 * yours before the first live submission, and change the SIF version under
 * Settings if it asks you to.
 */
final class Wps
{
    /** Wages must be paid within 7 days of the due date (Art. 66 as amended). */
    public const PAYMENT_DEADLINE_DAYS = 7;

    public const DEFAULT_SIF_VERSION = '1';

    public const FREQUENCY_MONTHLY = 'M';
    public const FREQUENCY_WEEKLY = 'W';
    public const FREQUENCY_BIWEEKLY = 'B';

    public const PAYMENT_TYPE_NORMAL = 'Normal Payment';
    public const PAYMENT_TYPE_FINAL = 'Final Settlement';

    /**
     * Validate one employee record, returning human-readable problems.
     *
     * @param array<string,mixed> $record
     * @return string[]
     */
    public static function validateRecord(array $record): array
    {
        $problems = [];
        $name = trim((string) ($record['name'] ?? '')) ?: 'An employee record';

        try {
            Qatar::validateQid($record['qid'] ?? null);
        } catch (ValidationException $e) {
            $problems[] = "{$name}: {$e->getMessage()}";
        }

        try {
            Qatar::validateIban($record['iban'] ?? null);
        } catch (ValidationException $e) {
            $problems[] = "{$name}: {$e->getMessage()}";
        }

        if (trim((string) ($record['name'] ?? '')) === '') {
            $problems[] = 'An employee record has no name';
        }
        if (trim((string) ($record['bank_short_name'] ?? '')) === '') {
            $problems[] = "{$name}: bank short name is missing";
        }

        $net = (int) ($record['net_salary_dirhams'] ?? 0);
        $basic = (int) ($record['basic_salary_dirhams'] ?? 0);
        $deductions = (int) ($record['deductions_dirhams'] ?? 0);

        if ($net <= 0) {
            $problems[] = "{$name}: net salary must be greater than zero";
        }
        if ($basic <= 0) {
            $problems[] = "{$name}: basic salary must be greater than zero";
        }
        if ($deductions < 0) {
            $problems[] = "{$name}: deductions cannot be negative";
        }
        // A net below basic-less-deductions means allowances or deductions
        // were mis-keyed; the bank will reject the line, so catch it here.
        if ($net > 0 && $basic > 0 && $net < $basic - $deductions) {
            $problems[] = "{$name}: net salary is less than basic salary minus deductions";
        }

        $workingDays = (int) ($record['working_days'] ?? 0);
        if ($workingDays < 0 || $workingDays > 31) {
            $problems[] = "{$name}: working days must be between 0 and 31";
        }

        return $problems;
    }

    /**
     * Build the SIF for one payroll period.
     *
     * @param array<string,mixed>            $employer Establishment and payer bank details.
     * @param array<int,array<string,mixed>> $records  One row per employee.
     *
     * @throws ValidationException listing every invalid record.
     */
    public static function buildSif(
        array $employer,
        array $records,
        int $periodYear,
        int $periodMonth,
        array $options = []
    ): string {
        if ($records === []) {
            throw new ValidationException('There are no employee records to include in the SIF file');
        }

        $problems = [];
        foreach ($records as $record) {
            $problems = array_merge($problems, self::validateRecord($record));
        }

        if (trim((string) ($employer['establishment_id'] ?? '')) === '') {
            $problems[] = 'The company Establishment ID (EID) is not set — fill it in under Settings';
        }
        try {
            Qatar::validateIban($employer['iban'] ?? null);
        } catch (ValidationException $e) {
            $problems[] = 'Company WPS bank account: ' . $e->getMessage();
        }
        if (trim((string) ($employer['bank_short_name'] ?? '')) === '') {
            $problems[] = 'The company bank short name is not set — fill it in under Settings';
        }

        if ($problems !== []) {
            throw ValidationException::withErrors(
                $problems,
                'The WPS file cannot be generated until these are fixed'
            );
        }

        $createdAt = $options['created_at'] ?? new \DateTimeImmutable();
        $version = (string) ($options['sif_version'] ?? self::DEFAULT_SIF_VERSION);
        $paymentType = (string) ($options['payment_type'] ?? self::PAYMENT_TYPE_NORMAL);

        $lines = [];
        $total = 0;
        foreach ($records as $record) {
            $total += (int) $record['net_salary_dirhams'];
            $lines[] = implode(',', [
                'EDR',
                Text::digits((string) $record['qid']),
                self::field($record['visa_id'] ?? ''),
                self::field($record['name']),
                self::field($record['bank_short_name']),
                strtoupper(preg_replace('/\s+/', '', (string) $record['iban']) ?? ''),
                self::field($record['salary_frequency'] ?? self::FREQUENCY_MONTHLY),
                (string) (int) ($record['working_days'] ?? 0),
                Money::toDecimalString((int) $record['net_salary_dirhams']),
                Money::toDecimalString((int) $record['basic_salary_dirhams']),
                number_format((float) ($record['extra_hours'] ?? 0), 2, '.', ''),
                Money::toDecimalString((int) ($record['extra_income_dirhams'] ?? 0)),
                Money::toDecimalString((int) ($record['deductions_dirhams'] ?? 0)),
                self::field($record['payment_type'] ?? $paymentType),
                self::field($record['notes'] ?? ''),
            ]);
        }

        $lines[] = implode(',', [
            'SCR',
            Text::digits((string) $employer['establishment_id']),
            $createdAt->format('Ymd'),
            $createdAt->format('Hi'),
            Text::digits((string) ($employer['payer_eid'] ?? $employer['establishment_id'])),
            Text::digits((string) ($employer['payer_qid'] ?? '')),
            self::field($employer['bank_short_name']),
            strtoupper(preg_replace('/\s+/', '', (string) $employer['iban']) ?? ''),
            sprintf('%04d%02d', $periodYear, $periodMonth),
            Money::toDecimalString($total),
            (string) count($records),
            $version,
            self::field($paymentType),
        ]);

        // Bank systems consuming the SIF expect DOS line endings.
        return implode("\r\n", $lines) . "\r\n";
    }

    /** Conventional SIF file name: <EID><YYYYMM><DDHHMM>.SIF */
    public static function filename(
        string $establishmentId,
        int $year,
        int $month,
        ?\DateTimeImmutable $createdAt = null
    ): string {
        $createdAt ??= new \DateTimeImmutable();

        return sprintf(
            '%s%04d%02d%s.SIF',
            Text::digits($establishmentId),
            $year,
            $month,
            $createdAt->format('dHi')
        );
    }

    /** Last date wages for a month may be paid without breaching Art. 66. */
    public static function dueDate(int $year, int $month): string
    {
        $firstOfNext = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('+1 month');

        return $firstOfNext->modify('+' . (self::PAYMENT_DEADLINE_DAYS - 1) . ' days')->format('Y-m-d');
    }

    /**
     * Strip characters that would break a CSV field in a SIF file.
     *
     * Runs of whitespace are collapsed too, so "Nair, Rajesh" becomes
     * "Nair Rajesh" rather than leaving a double space in a bank file.
     */
    private static function field(mixed $value): string
    {
        $clean = preg_replace('/[,\r\n]+/', ' ', (string) $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }
}
