<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Hr;
use App\Services\Settings;
use App\Support\LabourLaw;
use App\Support\Money;
use App\Support\Qatar;
use App\Support\ValidationException;

/** Employees, leave and end-of-service settlements. */
final class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $filters = [
            'status' => $request->string('status', Hr::STATUS_ACTIVE),
            'department' => $request->string('department'),
            'search' => $request->string('search'),
        ];
        if ($request->has('status') && $request->string('status') === '') {
            $filters['status'] = '';
        }

        $result = Hr::listEmployees($filters, $page);
        $payroll = 0;
        foreach ($result['rows'] as $row) {
            $payroll += (int) $row['gross_salary'];
        }

        return $this->view('hr/employees', [
            'title' => __('nav.employees'),
            'rows' => $result['rows'],
            'pagination' => $this->paginate($result['total'], $page),
            'filters' => $filters,
            'departments' => Hr::departments(),
            'monthlyPayroll' => $payroll,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('hr/employee_form', [
            'title' => __('action.new') . ' ' . __('nav.employees'),
            'employee' => null,
            'banks' => Qatar::BANKS,
        ]);
    }

    public function edit(Request $request): Response
    {
        $employee = $this->findOr404(Hr::find($request->routeInt('id')), 'employee');

        return $this->view('hr/employee_form', [
            'title' => __('action.edit') . ' — ' . $this->name($employee),
            'employee' => $employee,
            'banks' => Qatar::BANKS,
        ]);
    }

    public function store(Request $request): Response
    {
        $id = Hr::save($this->employeeData($request));

        return $this->redirect('/employees/' . $id, 'Employee added.');
    }

    public function update(Request $request): Response
    {
        $id = $request->routeInt('id');
        Hr::save($this->employeeData($request), $id);

        return $this->redirect('/employees/' . $id, 'Employee updated.');
    }

    private function employeeData(Request $request): array
    {
        return [
            'name_en' => $request->string('name_en'),
            'name_ar' => $request->string('name_ar'),
            'qid' => $request->string('qid'),
            'qid_expiry' => $request->date('qid_expiry'),
            'passport_number' => $request->string('passport_number'),
            'passport_expiry' => $request->date('passport_expiry'),
            'visa_number' => $request->string('visa_number'),
            'visa_expiry' => $request->date('visa_expiry'),
            'health_card_expiry' => $request->date('health_card_expiry'),
            'contract_expiry' => $request->date('contract_expiry'),
            'nationality' => $request->string('nationality'),
            'date_of_birth' => $request->date('date_of_birth'),
            'gender' => $request->string('gender'),
            'designation' => $request->string('designation'),
            'department' => $request->string('department'),
            'sponsor' => $request->string('sponsor'),
            'join_date' => $request->date('join_date') ?? '',
            'end_date' => $request->date('end_date'),
            'status' => $request->string('status', Hr::STATUS_ACTIVE),
            'basic_salary' => $request->money('basic_salary'),
            'housing_allowance' => $request->money('housing_allowance'),
            'transport_allowance' => $request->money('transport_allowance'),
            'food_allowance' => $request->money('food_allowance'),
            'other_allowance' => $request->money('other_allowance'),
            'bank_name' => $request->string('bank_name'),
            'bank_short_name' => $request->string('bank_short_name'),
            'iban' => $request->string('iban'),
            'salary_frequency' => $request->string('salary_frequency', 'M'),
            'contract_hours_month' => $request->int('contract_hours_month', 208),
            'leave_carried_forward' => $request->float('leave_carried_forward'),
            'email' => $request->string('email'),
            'phone' => $request->string('phone'),
            'address' => $request->string('address'),
            'notes' => $request->string('notes') ?: null,
        ];
    }

    public function show(Request $request): Response
    {
        $id = $request->routeInt('id');
        $employee = $this->findOr404(Hr::find($id), 'employee');

        // What the employee would be owed if they left today -- the figure an
        // owner most often wants and most rarely has to hand.
        $gratuity = LabourLaw::gratuity(
            (int) $employee['basic_salary'],
            (string) $employee['join_date'],
            $employee['end_date'] ?: date('Y-m-d'),
            [
                'tiers' => Settings::gratuityTiers(),
                'unpaid_leave_days' => Hr::unpaidLeaveDays($id),
            ]
        );

        return $this->view('hr/employee_show', [
            'title' => $employee['code'] . ' — ' . $this->name($employee),
            'employee' => $employee,
            'gratuity' => $gratuity,
            'leave' => Hr::listLeave(['employee_id' => $id]),
            'payslips' => Database::all(
                'SELECT ps.*, pr.period_year, pr.period_month, pr.status AS run_status
                 FROM payslips ps JOIN payroll_runs pr ON pr.id = ps.run_id
                 WHERE ps.employee_id = ?
                 ORDER BY pr.period_year DESC, pr.period_month DESC LIMIT 12',
                [$id]
            ),
            'settlements' => Database::all(
                'SELECT * FROM gratuity_settlements WHERE employee_id = ? ORDER BY id DESC',
                [$id]
            ),
            'leaveTypes' => Hr::LEAVE_TYPES,
        ]);
    }

    public function export(Request $request): Response
    {
        $rows = Hr::listEmployees(['status' => $request->string('status')], 1, 100000)['rows'];

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['code'],
                $row['name_en'],
                $row['name_ar'],
                $row['qid'],
                $row['nationality'],
                $row['designation'],
                $row['department'],
                $row['join_date'],
                Money::toDecimalString((int) $row['basic_salary']),
                Money::toDecimalString((int) $row['gross_salary']),
                $row['iban'],
                $row['qid_expiry'],
                $row['visa_expiry'],
                $row['status'],
            ];
        }

        return $this->csv(
            'employees-' . date('Y-m-d') . '.csv',
            ['Code', 'Name (EN)', 'Name (AR)', 'QID', 'Nationality', 'Designation', 'Department',
             'Joined', 'Basic salary', 'Gross salary', 'IBAN', 'QID expiry', 'Visa expiry', 'Status'],
            $data
        );
    }

    // --------------------------------------------------------------- Leave

    public function leave(Request $request): Response
    {
        $status = $request->string('status');

        return $this->view('hr/leave', [
            'title' => __('nav.leave'),
            'rows' => Hr::listLeave(['status' => $status]),
            'status' => $status,
            'employees' => Database::all(
                "SELECT id, code, name_en, name_ar FROM employees WHERE status <> 'terminated' ORDER BY code"
            ),
            'leaveTypes' => Hr::LEAVE_TYPES,
        ]);
    }

    public function storeLeave(Request $request): Response
    {
        Hr::requestLeave([
            'employee_id' => $request->int('employee_id'),
            'leave_type' => $request->string('leave_type', 'annual'),
            'start_date' => $request->date('start_date') ?? '',
            'end_date' => $request->date('end_date') ?? '',
            'days' => $request->float('days'),
            'reason' => $request->string('reason'),
        ]);

        return $this->redirect('/leave', 'Leave request recorded.');
    }

    public function decideLeave(Request $request): Response
    {
        Hr::decideLeave($request->routeInt('id'), $request->string('decision'), Auth::id());

        return $this->redirect('/leave', 'Leave request updated.');
    }

    // ------------------------------------------------------- End of service

    public function gratuity(Request $request): Response
    {
        $liability = Hr::gratuityLiability();

        return $this->view('hr/gratuity', [
            'title' => __('nav.gratuity'),
            'liability' => $liability,
            'settlements' => Database::all(
                'SELECT gs.*, e.code, e.name_en, e.name_ar
                 FROM gratuity_settlements gs JOIN employees e ON e.id = gs.employee_id
                 ORDER BY gs.id DESC LIMIT 50'
            ),
            'tiers' => Settings::gratuityTiers(),
        ]);
    }

    public function settlementForm(Request $request): Response
    {
        return $this->view('hr/settlement_form', [
            'title' => 'End of service settlement',
            'employees' => Database::all(
                "SELECT id, code, name_en, name_ar, join_date, basic_salary
                 FROM employees WHERE status <> 'terminated' ORDER BY code"
            ),
            'preview' => null,
            'input' => [],
        ]);
    }

    /** Show the calculation before it is committed. */
    public function settlementPreview(Request $request): Response
    {
        $employeeId = $request->int('employee_id');
        if ($employeeId <= 0) {
            throw new ValidationException('Choose an employee', 'employee_id');
        }

        $input = [
            'employee_id' => $employeeId,
            'last_working_day' => $request->date('last_working_day') ?? date('Y-m-d'),
            'reason' => $request->string('reason', 'resignation'),
            'other_dues' => $request->money('other_dues'),
            'deductions' => $request->money('deductions'),
            'pay_in_lieu_of_notice' => $request->bool('pay_in_lieu_of_notice'),
        ];

        $preview = Hr::settlementPreview(
            $employeeId,
            $input['last_working_day'],
            $input['reason'],
            $input
        );

        return $this->view('hr/settlement_form', [
            'title' => 'End of service settlement — ' . $this->name($preview['employee']),
            'employees' => Database::all(
                "SELECT id, code, name_en, name_ar, join_date, basic_salary
                 FROM employees WHERE status <> 'terminated' ORDER BY code"
            ),
            'preview' => $preview,
            'input' => $input,
        ]);
    }

    public function saveSettlement(Request $request): Response
    {
        $employeeId = $request->int('employee_id');
        $preview = Hr::settlementPreview(
            $employeeId,
            $request->date('last_working_day') ?? date('Y-m-d'),
            $request->string('reason', 'resignation'),
            [
                'other_dues' => $request->money('other_dues'),
                'deductions' => $request->money('deductions'),
                'pay_in_lieu_of_notice' => $request->bool('pay_in_lieu_of_notice'),
            ]
        );

        Hr::saveSettlement($preview, true);

        return $this->redirect(
            '/employees/' . $employeeId,
            'Settlement recorded and posted. The employee has been marked as terminated.'
        );
    }
}
