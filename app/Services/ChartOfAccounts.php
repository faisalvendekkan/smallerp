<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The default chart of accounts for a Qatari SME.
 *
 * Laid out on the conventional 1-5 numbering (assets, liabilities, equity,
 * income, expenses) that an accountant in Doha will recognise immediately.
 * Accounts carrying a `system_key` are wired into the posting engine and
 * cannot be deleted -- they can be renamed, and further accounts added
 * alongside them.
 */
final class ChartOfAccounts
{
    /**
     * @return array<int,array{code:string,name_en:string,name_ar:string,type:string,subtype:string,system_key:?string}>
     */
    public static function defaults(): array
    {
        return [
            // 1xxx Assets
            self::a('1000', 'Cash on Hand', 'النقد بالصندوق', 'asset', 'cash', 'cash'),
            self::a('1010', 'Bank — Current Account', 'البنك — الحساب الجاري', 'asset', 'bank', 'bank'),
            self::a('1020', 'Bank — WPS Salary Account', 'البنك — حساب الرواتب', 'asset', 'bank', 'wps_bank'),
            self::a('1100', 'Accounts Receivable', 'الذمم المدينة', 'asset', 'receivable', 'accounts_receivable'),
            self::a('1150', 'Employee Advances & Loans', 'سلف وقروض الموظفين', 'asset', 'receivable', 'employee_advances'),
            self::a('1200', 'Inventory', 'المخزون', 'asset', 'inventory', 'inventory'),
            self::a('1250', 'Prepaid Expenses', 'مصروفات مدفوعة مقدماً', 'asset', 'current_asset', null),
            self::a('1300', 'Refundable Deposits', 'تأمينات مستردة', 'asset', 'current_asset', null),
            self::a('1400', 'Property, Plant & Equipment', 'الممتلكات والمعدات', 'asset', 'fixed_asset', null),
            self::a('1450', 'Motor Vehicles', 'السيارات', 'asset', 'fixed_asset', null),
            self::a('1490', 'Accumulated Depreciation', 'مجمع الإهلاك', 'asset', 'fixed_asset', 'accumulated_depreciation'),
            // Recoverable input tax; dormant until VAT commences in Qatar.
            self::a('1500', 'Input Tax Recoverable', 'ضريبة المدخلات القابلة للاسترداد', 'asset', 'tax', 'input_tax'),

            // 2xxx Liabilities
            self::a('2000', 'Accounts Payable', 'الذمم الدائنة', 'liability', 'payable', 'accounts_payable'),
            self::a('2100', 'Accrued Expenses', 'مصروفات مستحقة', 'liability', 'current_liability', null),
            self::a('2150', 'Salaries Payable', 'الرواتب المستحقة', 'liability', 'payroll', 'salaries_payable'),
            // Art. 54 gratuity accrues from day one even though it is only
            // payable after a year, so it belongs on the balance sheet.
            self::a('2160', 'End of Service Gratuity Provision', 'مخصص مكافأة نهاية الخدمة', 'liability', 'payroll', 'gratuity_provision'),
            self::a('2170', 'Annual Leave Provision', 'مخصص الإجازات السنوية', 'liability', 'payroll', 'leave_provision'),
            self::a('2200', 'Output Tax Payable', 'ضريبة المخرجات المستحقة', 'liability', 'tax', 'output_tax'),
            self::a('2250', 'Withholding Tax Payable', 'ضريبة الاستقطاع المستحقة', 'liability', 'tax', 'withholding_tax'),
            self::a('2300', 'Corporate Income Tax Payable', 'ضريبة الدخل المستحقة', 'liability', 'tax', 'income_tax_payable'),
            self::a('2400', 'Customer Advances', 'دفعات مقدمة من العملاء', 'liability', 'current_liability', 'customer_advances'),
            self::a('2500', 'Bank Loans', 'قروض بنكية', 'liability', 'long_term_liability', null),

            // 3xxx Equity
            self::a('3000', 'Share Capital', 'رأس المال', 'equity', 'capital', 'share_capital'),
            self::a('3100', 'Partner Current Account', 'الحساب الجاري للشركاء', 'equity', 'capital', null),
            self::a('3200', 'Retained Earnings', 'الأرباح المرحلة', 'equity', 'retained', 'retained_earnings'),
            self::a('3300', 'Legal Reserve', 'الاحتياطي القانوني', 'equity', 'reserve', null),
            // The balancing side of opening balances entered at go-live.
            self::a('3900', 'Opening Balance Equity', 'حقوق الملكية الافتتاحية', 'equity', 'opening', 'opening_balance_equity'),

            // 4xxx Income
            self::a('4000', 'Sales — Goods', 'المبيعات — بضائع', 'income', 'revenue', 'sales_income'),
            self::a('4010', 'Sales — Services', 'المبيعات — خدمات', 'income', 'revenue', 'service_income'),
            self::a('4100', 'Sales Discounts', 'خصومات المبيعات', 'income', 'contra_revenue', 'sales_discount'),
            self::a('4200', 'Other Income', 'إيرادات أخرى', 'income', 'other_income', 'other_income'),

            // 5xxx Cost of sales and expenses
            self::a('5000', 'Cost of Goods Sold', 'تكلفة البضاعة المباعة', 'expense', 'cogs', 'cost_of_goods_sold'),
            self::a('5010', 'Purchases', 'المشتريات', 'expense', 'cogs', 'purchases'),
            self::a('5020', 'Freight & Customs Clearance', 'الشحن والتخليص الجمركي', 'expense', 'cogs', null),
            self::a('5030', 'Inventory Adjustments', 'تسويات المخزون', 'expense', 'cogs', 'inventory_adjustment'),

            self::a('5100', 'Salaries & Wages', 'الرواتب والأجور', 'expense', 'payroll', 'salaries_expense'),
            self::a('5110', 'Housing Allowance', 'بدل السكن', 'expense', 'payroll', 'housing_expense'),
            self::a('5120', 'Transport Allowance', 'بدل المواصلات', 'expense', 'payroll', 'transport_expense'),
            self::a('5130', 'Other Allowances', 'بدلات أخرى', 'expense', 'payroll', 'other_allowance_expense'),
            self::a('5140', 'Overtime', 'العمل الإضافي', 'expense', 'payroll', 'overtime_expense'),
            self::a('5150', 'End of Service Gratuity', 'مكافأة نهاية الخدمة', 'expense', 'payroll', 'gratuity_expense'),
            self::a('5160', 'Annual Leave & Air Tickets', 'الإجازات وتذاكر السفر', 'expense', 'payroll', 'leave_expense'),
            // Visas, QID renewals, health cards and MoL fees are a real and
            // recurring line for every SME employing expatriate staff.
            self::a('5170', 'Visa, QID & Immigration Fees', 'رسوم التأشيرات والإقامات', 'expense', 'payroll', 'visa_expense'),
            self::a('5180', 'Medical Insurance', 'التأمين الصحي', 'expense', 'payroll', null),

            self::a('5200', 'Rent', 'الإيجار', 'expense', 'operating', 'rent_expense'),
            self::a('5210', 'Kahramaa — Electricity & Water', 'كهرماء — الكهرباء والماء', 'expense', 'operating', 'utilities_expense'),
            self::a('5220', 'Telephone & Internet', 'الهاتف والإنترنت', 'expense', 'operating', null),
            self::a('5230', 'Vehicle Running & Fuel', 'تشغيل المركبات والوقود', 'expense', 'operating', null),
            self::a('5240', 'Repairs & Maintenance', 'الإصلاح والصيانة', 'expense', 'operating', null),
            self::a('5250', 'Office Supplies', 'القرطاسية واللوازم المكتبية', 'expense', 'operating', null),
            self::a('5260', 'Marketing & Advertising', 'التسويق والإعلان', 'expense', 'operating', null),
            self::a('5270', 'Professional & Legal Fees', 'أتعاب مهنية وقانونية', 'expense', 'operating', null),
            // CR renewal, Chamber of Commerce, municipality and MoCI charges.
            self::a('5280', 'Government & Municipality Fees', 'الرسوم الحكومية والبلدية', 'expense', 'operating', 'government_fees'),
            self::a('5290', 'Bank Charges', 'المصاريف البنكية', 'expense', 'operating', 'bank_charges'),
            self::a('5300', 'Insurance', 'التأمين', 'expense', 'operating', null),
            self::a('5310', 'Depreciation', 'الإهلاك', 'expense', 'operating', 'depreciation_expense'),
            self::a('5320', 'Bad Debts Written Off', 'الديون المعدومة', 'expense', 'operating', 'bad_debts'),
            self::a('5900', 'Miscellaneous Expenses', 'مصروفات متنوعة', 'expense', 'operating', 'misc_expense'),
            self::a('5950', 'Corporate Income Tax', 'ضريبة الدخل', 'expense', 'tax', 'income_tax_expense'),
        ];
    }

    private static function a(
        string $code,
        string $nameEn,
        string $nameAr,
        string $type,
        string $subtype,
        ?string $systemKey
    ): array {
        return [
            'code' => $code,
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'type' => $type,
            'subtype' => $subtype,
            'system_key' => $systemKey,
        ];
    }

    /** Insert the default chart into an empty accounts table. */
    public static function install(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (self::defaults() as $account) {
            Database::insert('accounts', $account + ['is_active' => 1, 'created_at' => $now]);
        }
    }

    /** @var array<string,int> Per-request cache of resolved system accounts. */
    private static array $idCache = [];

    /**
     * Resolve a system account id, e.g. `accounts_receivable`.
     *
     * Cached per request: the posting engine asks for the same half-dozen
     * accounts on every document.
     */
    public static function id(string $systemKey): int
    {
        if (isset(self::$idCache[$systemKey])) {
            return self::$idCache[$systemKey];
        }

        $id = Database::value('SELECT id FROM accounts WHERE system_key = ?', [$systemKey]);
        if ($id === null) {
            throw new \RuntimeException(
                "The system account '{$systemKey}' is missing from the chart of accounts. "
                . 'Restore it under Accounting → Chart of Accounts.'
            );
        }

        return self::$idCache[$systemKey] = (int) $id;
    }

    /** Drop the cache -- used by the test suite, which rebuilds the database. */
    public static function clearCache(): void
    {
        self::$idCache = [];
    }

    public const TYPES = [
        'asset' => ['name_en' => 'Asset', 'name_ar' => 'أصول', 'normal' => 'debit'],
        'liability' => ['name_en' => 'Liability', 'name_ar' => 'التزامات', 'normal' => 'credit'],
        'equity' => ['name_en' => 'Equity', 'name_ar' => 'حقوق ملكية', 'normal' => 'credit'],
        'income' => ['name_en' => 'Income', 'name_ar' => 'إيرادات', 'normal' => 'credit'],
        'expense' => ['name_en' => 'Expense', 'name_ar' => 'مصروفات', 'normal' => 'debit'],
    ];

    /** Does this account type increase with a debit? */
    public static function isDebitNormal(string $type): bool
    {
        return (self::TYPES[$type]['normal'] ?? 'debit') === 'debit';
    }
}
