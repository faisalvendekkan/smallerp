<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Database;
use App\Support\LabourLaw;
use App\Support\Money;
use App\Support\Qatar;
use App\Support\ValidationException;

/**
 * Employee records, document expiry and leave.
 *
 * The expiry tracking is the part an SME in Qatar will use every week: a QID
 * or a visa that lapses stops the employee working and exposes the company to
 * a fine, and there is no automatic reminder from anyone else.
 */
final class Hr
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_TERMINATED = 'terminated';

    public const LEAVE_TYPES = [
        'annual' => ['name_en' => 'Annual leave', 'name_ar' => 'إجازة سنوية', 'paid' => true],
        'sick' => ['name_en' => 'Sick leave', 'name_ar' => 'إجازة مرضية', 'paid' => true],
        'unpaid' => ['name_en' => 'Unpaid leave', 'name_ar' => 'إجازة بدون راتب', 'paid' => false],
        'maternity' => ['name_en' => 'Maternity leave', 'name_ar' => 'إجازة وضع', 'paid' => true],
        'paternity' => ['name_en' => 'Paternity leave', 'name_ar' => 'إجازة أبوة', 'paid' => true],
        'hajj' => ['name_en' => 'Hajj leave', 'name_ar' => 'إجازة حج', 'paid' => true],
        'compassionate' => ['name_en' => 'Compassionate leave', 'name_ar' => 'إجازة وفاة', 'paid' => true],
    ];

    /**
     * The document fields tracked for expiry, in the order they are shown.
     *
     * The labels are keyed `document_*` rather than `name_*` so they never
     * collide with the employee's own name columns when the two are merged.
     */
    public const EXPIRY_FIELDS = [
        'qid_expiry' => ['document_en' => 'Qatar ID', 'document_ar' => 'البطاقة الشخصية'],
        'visa_expiry' => ['document_en' => 'Residence visa', 'document_ar' => 'تأشيرة الإقامة'],
        'passport_expiry' => ['document_en' => 'Passport', 'document_ar' => 'جواز السفر'],
        'health_card_expiry' => ['document_en' => 'Health card', 'document_ar' => 'البطاقة الصحية'],
        'contract_expiry' => ['document_en' => 'Contract', 'document_ar' => 'العقد'],
    ];

    public static function save(array $data, ?int $employeeId = null): int
    {
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        if ($nameEn === '') {
            throw new ValidationException('The employee name is required', 'name_en');
        }
        $joinDate = (string) ($data['join_date'] ?? '');
        if ($joinDate === '') {
            throw new ValidationException('The joining date is required', 'join_date');
        }

        // A QID is optional at data-entry time -- a new hire may still be in
        // process -- but must be valid if given, because payroll needs it.
        $qid = trim((string) ($data['qid'] ?? ''));
        if ($qid !== '') {
            $qid = Qatar::validateQid($qid);
            $duplicate = Database::first(
                'SELECT id, code, name_en FROM employees WHERE qid = ?' . ($employeeId ? ' AND id <> ?' : ''),
                $employeeId ? [$qid, $employeeId] : [$qid]
            );
            if ($duplicate) {
                throw new ValidationException(
                    'That QID already belongs to ' . $duplicate['name_en'] . ' (' . $duplicate['code'] . ')',
                    'qid'
                );
            }
        }

        $iban = trim((string) ($data['iban'] ?? ''));
        if ($iban !== '') {
            $iban = Qatar::validateIban($iban);
        }

        $basic = (int) ($data['basic_salary'] ?? 0);
        if ($basic < 0) {
            throw new ValidationException('Basic salary cannot be negative', 'basic_salary');
        }

        $endDate = ($data['end_date'] ?? '') ?: null;
        if ($endDate && $endDate < $joinDate) {
            throw new ValidationException('The end date cannot be before the joining date', 'end_date');
        }

        $record = [
            'name_en' => mb_substr($nameEn, 0, 160),
            'name_ar' => mb_substr(trim((string) ($data['name_ar'] ?? '')), 0, 160),
            'qid' => $qid,
            'qid_expiry' => ($data['qid_expiry'] ?? '') ?: null,
            'passport_number' => mb_substr(trim((string) ($data['passport_number'] ?? '')), 0, 30),
            'passport_expiry' => ($data['passport_expiry'] ?? '') ?: null,
            'visa_number' => mb_substr(trim((string) ($data['visa_number'] ?? '')), 0, 30),
            'visa_expiry' => ($data['visa_expiry'] ?? '') ?: null,
            'health_card_expiry' => ($data['health_card_expiry'] ?? '') ?: null,
            'contract_expiry' => ($data['contract_expiry'] ?? '') ?: null,
            'nationality' => mb_substr(trim((string) ($data['nationality'] ?? '')), 0, 60),
            'date_of_birth' => ($data['date_of_birth'] ?? '') ?: null,
            'gender' => mb_substr((string) ($data['gender'] ?? ''), 0, 10),
            'designation' => mb_substr(trim((string) ($data['designation'] ?? '')), 0, 120),
            'department' => mb_substr(trim((string) ($data['department'] ?? '')), 0, 120),
            'sponsor' => mb_substr(trim((string) ($data['sponsor'] ?? '')), 0, 160),
            'join_date' => $joinDate,
            'end_date' => $endDate ?: null,
            'status' => in_array($data['status'] ?? '', [self::STATUS_ACTIVE, self::STATUS_ON_LEAVE, self::STATUS_TERMINATED], true)
                ? ($data['status'] ?? self::STATUS_ACTIVE)
                : self::STATUS_ACTIVE,
            'basic_salary' => $basic,
            'housing_allowance' => (int) ($data['housing_allowance'] ?? 0),
            'transport_allowance' => (int) ($data['transport_allowance'] ?? 0),
            'food_allowance' => (int) ($data['food_allowance'] ?? 0),
            'other_allowance' => (int) ($data['other_allowance'] ?? 0),
            'bank_name' => mb_substr(trim((string) ($data['bank_name'] ?? '')), 0, 120),
            // Derived from the IBAN when the user has not overridden it, so the
            // WPS file gets the short name the bank expects.
            'bank_short_name' => mb_substr(
                trim((string) ($data['bank_short_name'] ?? '')) ?: ($iban !== '' ? Qatar::bankShortName($iban) : ''),
                0,
                20
            ),
            'iban' => $iban,
            'salary_frequency' => in_array($data['salary_frequency'] ?? 'M', ['M', 'W', 'B'], true)
                ? ($data['salary_frequency'] ?? 'M')
                : 'M',
            'contract_hours_month' => max(1, (int) ($data['contract_hours_month'] ?? 208)),
            'leave_carried_forward' => (float) ($data['leave_carried_forward'] ?? 0),
            'email' => mb_substr(trim((string) ($data['email'] ?? '')), 0, 160),
            'phone' => mb_substr(trim((string) ($data['phone'] ?? '')), 0, 30),
            'address' => mb_substr(trim((string) ($data['address'] ?? '')), 0, 255),
            'notes' => $data['notes'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($employeeId === null) {
            $record['code'] = Numbering::next('employee');
            $record['created_at'] = date('Y-m-d H:i:s');
            $employeeId = Database::insert('employees', $record);
            AuditLog::record('employee.create', 'employee', $employeeId, ['code' => $record['code']]);
        } else {
            Database::update('employees', $record, ['id' => $employeeId]);
            AuditLog::record('employee.update', 'employee', $employeeId);
        }

        return $employeeId;
    }

    public static function find(int $employeeId): ?array
    {
        $employee = Database::first('SELECT * FROM employees WHERE id = ?', [$employeeId]);
        if (!$employee) {
            return null;
        }

        return self::decorate($employee);
    }

    /** Add the derived figures every employee screen needs. */
    public static function decorate(array $employee): array
    {
        $employee['gross_salary'] = self::grossSalary($employee);
        $asOf = $employee['end_date'] ?: date('Y-m-d');
        $serviceDays = (int) ((new \DateTimeImmutable((string) $employee['join_date']))
            ->diff(new \DateTimeImmutable($asOf))->days) + 1;
        $employee['service_days'] = max(0, $serviceDays);
        $employee['service_years'] = round($employee['service_days'] / LabourLaw::DAYS_PER_YEAR, 2);
        $employee['leave_entitlement'] = LabourLaw::annualLeaveEntitlementDays((float) $employee['service_years']);
        $employee['leave_taken'] = self::leaveTaken($employee['id']);
        $employee['leave_balance'] = LabourLaw::accruedLeaveDays(
            (string) $employee['join_date'],
            $asOf,
            $employee['leave_taken'],
            (float) $employee['leave_carried_forward']
        );
        $employee['expiries'] = self::expiriesFor($employee);

        return $employee;
    }

    /** Monthly wage: basic plus every allowance. */
    public static function grossSalary(array $employee): int
    {
        return (int) $employee['basic_salary']
            + (int) $employee['housing_allowance']
            + (int) $employee['transport_allowance']
            + (int) $employee['food_allowance']
            + (int) $employee['other_allowance'];
    }

    /** Approved annual leave days taken. Unpaid leave is counted separately. */
    public static function leaveTaken(int $employeeId, string $leaveType = 'annual'): float
    {
        return (float) Database::value(
            "SELECT COALESCE(SUM(days), 0) FROM leave_requests
             WHERE employee_id = ? AND leave_type = ? AND status = 'approved'",
            [$employeeId, $leaveType],
            0
        );
    }

    /** Unpaid leave days, which are excluded from service for gratuity. */
    public static function unpaidLeaveDays(int $employeeId): int
    {
        return (int) round((float) Database::value(
            "SELECT COALESCE(SUM(days), 0) FROM leave_requests
             WHERE employee_id = ? AND leave_type = 'unpaid' AND status = 'approved'",
            [$employeeId],
            0
        ));
    }

    /**
     * Document expiries for one employee, with days remaining.
     *
     * @return array<int,array{field:string,document_en:string,document_ar:string,date:string,days:int,level:string}>
     */
    public static function expiriesFor(array $employee): array
    {
        $alertDays = Settings::int('expiry_alert_days', 60);
        $today = new \DateTimeImmutable(date('Y-m-d'));
        $out = [];

        foreach (self::EXPIRY_FIELDS as $field => $labels) {
            $date = $employee[$field] ?? null;
            if (!$date) {
                continue;
            }
            $days = (int) $today->diff(new \DateTimeImmutable((string) $date))->format('%r%a');
            $level = match (true) {
                $days < 0 => 'expired',
                $days <= 30 => 'critical',
                $days <= $alertDays => 'warning',
                default => 'ok',
            };
            $out[] = $labels + ['field' => $field, 'date' => (string) $date, 'days' => $days, 'level' => $level];
        }

        usort($out, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);

        return $out;
    }

    /**
     * Every document expiring within the alert window, across all employees.
     *
     * This drives the dashboard warning and the compliance report.
     */
    public static function upcomingExpiries(?int $withinDays = null): array
    {
        $withinDays ??= Settings::int('expiry_alert_days', 60);
        $limit = date('Y-m-d', strtotime("+{$withinDays} days"));
        $rows = [];

        foreach (self::EXPIRY_FIELDS as $field => $labels) {
            $employees = Database::all(
                "SELECT id, code, name_en, name_ar, designation, department, {$field} AS expiry_date
                 FROM employees
                 WHERE status <> 'terminated' AND {$field} IS NOT NULL AND {$field} <> '' AND {$field} <= ?
                 ORDER BY {$field}",
                [$limit]
            );

            $today = new \DateTimeImmutable(date('Y-m-d'));
            foreach ($employees as $employee) {
                $days = (int) $today->diff(new \DateTimeImmutable((string) $employee['expiry_date']))->format('%r%a');
                $rows[] = $employee + $labels + [
                    'field' => $field,
                    'days' => $days,
                    'level' => $days < 0 ? 'expired' : ($days <= 30 ? 'critical' : 'warning'),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);

        return $rows;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public static function listEmployees(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['department'])) {
            $where[] = 'department = ?';
            $params[] = $filters['department'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(name_en LIKE ? OR name_ar LIKE ? OR code LIKE ? OR qid LIKE ? OR designation LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term, $term);
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM employees WHERE {$clause}", $params, 0);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = Database::all(
            "SELECT * FROM employees WHERE {$clause} ORDER BY code LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $row['gross_salary'] = self::grossSalary($row);
            $row['expiries'] = self::expiriesFor($row);
            $row['worst_expiry'] = $row['expiries'][0] ?? null;
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /** Active employees who can actually be paid through the WPS. */
    public static function payableEmployees(): array
    {
        return Database::all(
            "SELECT * FROM employees WHERE status IN ('active', 'on_leave') ORDER BY code"
        );
    }

    public static function departments(): array
    {
        return array_column(
            Database::all("SELECT DISTINCT department FROM employees WHERE department <> '' ORDER BY department"),
            'department'
        );
    }

    // ------------------------------------------------------------------
    // Leave
    // ------------------------------------------------------------------

    public static function requestLeave(array $data): int
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $employee = Database::first('SELECT * FROM employees WHERE id = ?', [$employeeId]);
        if (!$employee) {
            throw new ValidationException('Choose an employee', 'employee_id');
        }

        $start = (string) ($data['start_date'] ?? '');
        $end = (string) ($data['end_date'] ?? '');
        if ($start === '' || $end === '') {
            throw new ValidationException('Enter the leave start and end dates', 'start_date');
        }
        if ($end < $start) {
            throw new ValidationException('The end date cannot be before the start date', 'end_date');
        }

        $type = (string) ($data['leave_type'] ?? 'annual');
        if (!isset(self::LEAVE_TYPES[$type])) {
            throw new ValidationException('Choose a valid leave type', 'leave_type');
        }

        // Leave in Qatar is counted in calendar days, weekends included.
        $days = (float) ($data['days'] ?? 0);
        if ($days <= 0) {
            $days = (float) ((new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1);
        }

        $overlap = Database::first(
            "SELECT id FROM leave_requests
             WHERE employee_id = ? AND status IN ('pending', 'approved')
               AND start_date <= ? AND end_date >= ?",
            [$employeeId, $end, $start]
        );
        if ($overlap) {
            throw new ValidationException('This employee already has leave booked over those dates');
        }

        $id = Database::insert('leave_requests', [
            'employee_id' => $employeeId,
            'leave_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'status' => 'pending',
            'reason' => mb_substr((string) ($data['reason'] ?? ''), 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLog::record('leave.request', 'leave_request', $id, [
            'employee_id' => $employeeId,
            'type' => $type,
            'days' => $days,
        ]);

        return $id;
    }

    public static function decideLeave(int $leaveId, string $decision, ?int $userId = null): void
    {
        if (!in_array($decision, ['approved', 'rejected', 'cancelled'], true)) {
            throw new ValidationException('Invalid leave decision');
        }
        $leave = Database::first('SELECT * FROM leave_requests WHERE id = ?', [$leaveId]);
        if (!$leave) {
            throw new ValidationException('That leave request does not exist');
        }

        Database::update('leave_requests', [
            'status' => $decision,
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], ['id' => $leaveId]);

        AuditLog::record('leave.' . $decision, 'leave_request', $leaveId);
    }

    public static function listLeave(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'lr.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = 'lr.employee_id = ?';
            $params[] = (int) $filters['employee_id'];
        }

        return Database::all(
            'SELECT lr.*, e.code, e.name_en, e.name_ar, e.department
             FROM leave_requests lr
             JOIN employees e ON e.id = lr.employee_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY lr.start_date DESC LIMIT 200',
            $params
        );
    }

    // ------------------------------------------------------------------
    // End of service
    // ------------------------------------------------------------------

    /**
     * Full end-of-service settlement: gratuity, leave encashment and notice.
     *
     * Produces the figures rather than saving them, so the HR officer can see
     * the calculation before committing to it.
     */
    public static function settlementPreview(
        int $employeeId,
        string $lastWorkingDay,
        string $reason = 'resignation',
        array $options = []
    ): array {
        $employee = self::find($employeeId);
        if (!$employee) {
            throw new ValidationException('That employee does not exist');
        }

        $gratuity = LabourLaw::gratuity(
            (int) $employee['basic_salary'],
            (string) $employee['join_date'],
            $lastWorkingDay,
            [
                'tiers' => Settings::gratuityTiers(),
                'unpaid_leave_days' => self::unpaidLeaveDays($employeeId),
                'deductions_dirhams' => (int) ($options['deductions'] ?? 0),
            ]
        );

        $gross = self::grossSalary($employee);
        $leaveDays = max(0.0, LabourLaw::accruedLeaveDays(
            (string) $employee['join_date'],
            $lastWorkingDay,
            self::leaveTaken($employeeId),
            (float) $employee['leave_carried_forward']
        ));
        $leaveEncashment = LabourLaw::leaveEncashment($gross, $leaveDays);

        // Notice pay is only owed where the notice period was not worked.
        $noticeDays = LabourLaw::noticeDays((float) $gratuity['service_years']);
        $noticePay = (bool) ($options['pay_in_lieu_of_notice'] ?? false)
            ? LabourLaw::leaveEncashment($gross, (float) $noticeDays)
            : 0;

        $otherDues = (int) ($options['other_dues'] ?? 0);
        $deductions = (int) ($options['deductions'] ?? 0);
        $net = max(0, $gratuity['gross_dirhams'] + $leaveEncashment + $noticePay + $otherDues - $deductions);

        return [
            'employee' => $employee,
            'reason' => $reason,
            'last_working_day' => $lastWorkingDay,
            'gratuity' => $gratuity,
            'leave_days' => $leaveDays,
            'leave_encashment' => $leaveEncashment,
            'notice_days' => $noticeDays,
            'notice_pay' => $noticePay,
            'other_dues' => $otherDues,
            'deductions' => $deductions,
            'net_payable' => $net,
        ];
    }

    /**
     * Save a settlement and post it.
     *
     * Dr End of Service Gratuity / Leave / Salaries expense
     *   Cr Salaries Payable
     */
    public static function saveSettlement(array $preview, bool $post = true): int
    {
        $employee = $preview['employee'];
        $gratuity = $preview['gratuity'];

        return Database::transaction(static function () use ($preview, $employee, $gratuity, $post): int {
            $settlementId = Database::insert('gratuity_settlements', [
                'employee_id' => (int) $employee['id'],
                'calculated_on' => date('Y-m-d'),
                'last_working_day' => $preview['last_working_day'],
                'reason' => mb_substr((string) $preview['reason'], 0, 40),
                'service_days' => $gratuity['service_days'],
                'service_years' => $gratuity['service_years'],
                'weeks_per_year' => $gratuity['weeks_per_year'],
                'basic_salary' => (int) $employee['basic_salary'],
                'gratuity_amount' => $gratuity['gross_dirhams'],
                'leave_days' => $preview['leave_days'],
                'leave_encashment' => $preview['leave_encashment'],
                'notice_pay' => $preview['notice_pay'],
                'other_dues' => $preview['other_dues'],
                'deductions' => $preview['deductions'],
                'net_payable' => $preview['net_payable'],
                'status' => $post ? 'approved' : 'draft',
                'created_by' => \App\Core\Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($post && $preview['net_payable'] > 0) {
                $lines = [];
                if ($gratuity['gross_dirhams'] > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('gratuity_expense'),
                        'debit' => $gratuity['gross_dirhams'],
                        'credit' => 0,
                        'memo' => 'Gratuity — ' . $employee['name_en'],
                    ];
                }
                if ($preview['leave_encashment'] > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('leave_expense'),
                        'debit' => $preview['leave_encashment'],
                        'credit' => 0,
                        'memo' => 'Leave encashment — ' . $employee['name_en'],
                    ];
                }
                if ($preview['notice_pay'] > 0 || $preview['other_dues'] > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('salaries_expense'),
                        'debit' => $preview['notice_pay'] + $preview['other_dues'],
                        'credit' => 0,
                        'memo' => 'Notice and other dues — ' . $employee['name_en'],
                    ];
                }
                if ($preview['deductions'] > 0) {
                    // Deductions recover an advance already sitting as an asset.
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('employee_advances'),
                        'debit' => 0,
                        'credit' => $preview['deductions'],
                        'memo' => 'Recovery of advances — ' . $employee['name_en'],
                    ];
                }
                $lines[] = [
                    'account_id' => ChartOfAccounts::id('salaries_payable'),
                    'debit' => 0,
                    'credit' => (int) $preview['net_payable'],
                    'memo' => 'Final settlement — ' . $employee['name_en'],
                ];

                $journalId = Ledger::post(
                    (string) $preview['last_working_day'],
                    $lines,
                    'End of service settlement — ' . $employee['name_en'],
                    'gratuity_settlement',
                    $settlementId
                );
                Database::update('gratuity_settlements', ['journal_id' => $journalId], ['id' => $settlementId]);
            }

            Database::update('employees', [
                'status' => self::STATUS_TERMINATED,
                'end_date' => $preview['last_working_day'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $employee['id']]);

            AuditLog::record('settlement.create', 'gratuity_settlement', $settlementId, [
                'employee' => $employee['code'],
                'net' => Money::toDecimalString((int) $preview['net_payable']),
            ]);

            return $settlementId;
        });
    }

    /**
     * Total gratuity the company would owe if everyone left today.
     *
     * Worth knowing: for a long-established SME it is often the largest
     * liability on the balance sheet and the one most often left off it.
     */
    public static function gratuityLiability(): array
    {
        $today = date('Y-m-d');
        $rows = [];
        $total = 0;

        foreach (Database::all("SELECT * FROM employees WHERE status <> 'terminated' ORDER BY code") as $employee) {
            $gratuity = LabourLaw::gratuity(
                (int) $employee['basic_salary'],
                (string) $employee['join_date'],
                $today,
                [
                    'tiers' => Settings::gratuityTiers(),
                    'unpaid_leave_days' => self::unpaidLeaveDays((int) $employee['id']),
                ]
            );
            $total += $gratuity['gross_dirhams'];
            $rows[] = [
                'employee' => $employee,
                'service_years' => $gratuity['service_years'],
                'weeks_per_year' => $gratuity['weeks_per_year'],
                'eligible' => $gratuity['eligible'],
                'amount' => $gratuity['gross_dirhams'],
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
