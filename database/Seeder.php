<?php

declare(strict_types=1);

namespace Database;

use App\Core\Auth;
use App\Core\Database;
use App\Services\ChartOfAccounts;
use App\Services\Hr;
use App\Services\Numbering;
use App\Services\Payments;
use App\Services\Payroll;
use App\Services\Purchases;
use App\Services\Sales;
use App\Services\Settings;
use App\Support\Qatar;

/**
 * Sets up a new database, and optionally fills it with a worked example.
 *
 * The demo company is a small Doha trading and maintenance business -- the
 * kind of SME this system is for -- with real-looking CR numbers, QIDs, IBANs
 * and a month of trading, so the reports have something in them on first login.
 */
final class Seeder
{
    /** The minimum a usable installation needs: accounts, a warehouse, an admin. */
    public static function installBase(string $adminName, string $username, string $password, string $email = ''): int
    {
        return Database::transaction(static function () use ($adminName, $username, $password, $email): int {
            ChartOfAccounts::install();

            Database::insert('warehouses', [
                'code' => 'MAIN',
                'name_en' => 'Main Store',
                'name_ar' => 'المخزن الرئيسي',
                'address' => 'Doha, Qatar',
                'is_default' => 1,
                'is_active' => 1,
            ]);

            $userId = Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'name' => $adminName,
                'password_hash' => Auth::hashPassword($password),
                'role' => Auth::ROLE_ADMIN,
                'locale' => 'en',
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Seed the public holidays that have a fixed Gregorian date. Eid
            // moves with the Hijri calendar and is added by the user.
            $year = (int) date('Y');
            foreach ([$year, $year + 1] as $y) {
                foreach (Qatar::fixedPublicHolidays($y) as $holiday) {
                    Database::insert('holidays', [
                        'holiday_date' => $holiday['date'],
                        'name_en' => $holiday['name_en'],
                        'name_ar' => $holiday['name_ar'],
                        'is_paid' => 1,
                    ]);
                }
            }

            return $userId;
        });
    }

    /** Load the worked example. Safe to skip on a real installation. */
    public static function installDemoData(): void
    {
        Database::transaction(static function (): void {
            Settings::setMany([
                'company_name_en' => 'Al Khaleej Trading & Maintenance W.L.L.',
                'company_name_ar' => 'الخليج للتجارة والصيانة ذ.م.م',
                'cr_number' => '84213',
                'establishment_id' => '31245',
                'tax_card_number' => '5001234567',
                'municipality_licence' => 'MUN-2021-4417',
                'address' => 'Building 24, Street 850, Salwa Road, Zone 55',
                'po_box' => '31450',
                'city' => 'Doha',
                'phone' => '+97444318820',
                'mobile' => '+97455112233',
                'email' => 'accounts@alkhaleej.example.qa',
                'wps_bank_short_name' => 'QNB',
                // Valid QA IBANs -- the check digits below pass mod-97.
                'wps_iban' => self::iban('QNBA', '000000000012345678901'),
                'wps_payer_qid' => '27463400157',
                'bank_details' => "Qatar National Bank\nAccount: Al Khaleej Trading & Maintenance W.L.L.",
                'expiry_alert_days' => '60',
            ]);

            $contacts = self::seedContacts();
            $items = self::seedItems();
            self::seedEmployees();
            self::seedTrading($contacts, $items);
            self::seedQuotations($contacts, $items);
            self::seedPayroll();
        });
    }

    /**
     * Build a syntactically valid Qatari IBAN with correct mod-97 check digits.
     *
     * Used so the demo data exercises the real IBAN validation rather than
     * bypassing it.
     */
    public static function iban(string $bankCode, string $account): string
    {
        $body = $bankCode . $account;
        $rearranged = $body . 'QA00';
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder . $chunk) % 97);
        }

        return sprintf('QA%02d%s', 98 - $remainder, $body);
    }

    private static function seedContacts(): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = [
            ['customer', 'Qatar Foundation Facilities Management', 'مؤسسة قطر لإدارة المرافق', '41002', 60, 20000000, '+97444541000'],
            ['customer', 'Barwa Real Estate Company', 'بروة العقارية', '32118', 45, 15000000, '+97444087777'],
            ['customer', 'Al Meera Consumer Goods', 'الميرة للسلع الاستهلاكية', '25874', 30, 8000000, '+97444628888'],
            ['customer', 'Doha Marine Services', 'خدمات الدوحة البحرية', '55219', 30, 5000000, '+97444652211'],
            ['customer', 'Lusail Hospitality Group', 'مجموعة لوسيل للضيافة', '67341', 30, 3000000, '+97444990011'],
            ['supplier', 'Gulf Electrical Supplies W.L.L.', 'الخليج للتوريدات الكهربائية', '19023', 30, 0, '+97444442200'],
            ['supplier', 'Qatar Industrial Tools Est.', 'قطر للعدد الصناعية', '73112', 45, 0, '+97444771100'],
            ['supplier', 'Kahramaa', 'كهرماء', '10001', 15, 0, '+974991'],
            ['supplier', 'Salwa Property Management', 'سلوى لإدارة العقارات', '48820', 7, 0, '+97444336655'],
        ];

        $ids = [];
        foreach ($rows as [$kind, $nameEn, $nameAr, $cr, $terms, $creditLimit, $phone]) {
            $ids[$nameEn] = Database::insert('contacts', [
                'code' => Numbering::next($kind === 'customer' ? 'contact' : 'supplier'),
                'kind' => $kind,
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'cr_number' => $cr,
                'contact_person' => '',
                'phone' => $phone,
                'city' => 'Doha',
                'country' => 'Qatar',
                'payment_terms_days' => $terms,
                'credit_limit' => $creditLimit,
                'is_active' => 1,
                'created_at' => $now,
            ]);
        }

        return $ids;
    }

    private static function seedItems(): array
    {
        $now = date('Y-m-d H:i:s');
        $inventoryAccount = ChartOfAccounts::id('inventory');
        $salesAccount = ChartOfAccounts::id('sales_income');
        $serviceAccount = ChartOfAccounts::id('service_income');
        $purchasesAccount = ChartOfAccounts::id('purchases');

        $rows = [
            ['SPLIT-18', 'Split Air Conditioner 1.5 Ton', 'مكيف سبليت 1.5 طن', 'goods', 'PCS', 185000, 132000, 5],
            ['CABLE-25', 'Copper Cable 2.5mm (100m Roll)', 'كابل نحاسي 2.5 مم', 'goods', 'ROLL', 42000, 28500, 20],
            ['MCB-32', 'Circuit Breaker 32A', 'قاطع كهربائي 32 أمبير', 'goods', 'PCS', 8500, 4800, 50],
            ['LED-PANEL', 'LED Panel Light 36W', 'لوحة إضاءة ليد 36 واط', 'goods', 'PCS', 12000, 7200, 40],
            ['PUMP-1HP', 'Water Pump 1 HP', 'مضخة مياه 1 حصان', 'goods', 'PCS', 95000, 68000, 6],
            ['AMC-ANNUAL', 'Annual Maintenance Contract', 'عقد صيانة سنوي', 'service', 'CONTRACT', 1200000, 0, 0],
            ['LABOUR-HR', 'Technician Labour (per hour)', 'أجرة فني بالساعة', 'service', 'HOUR', 9000, 0, 0],
            ['INSTALL-AC', 'AC Installation', 'تركيب مكيف', 'service', 'JOB', 35000, 0, 0],
        ];

        $ids = [];
        foreach ($rows as [$sku, $nameEn, $nameAr, $kind, $uom, $salePrice, $costPrice, $reorder]) {
            $isGoods = $kind === 'goods';
            $ids[$sku] = Database::insert('items', [
                'sku' => $sku,
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'kind' => $kind,
                'uom' => $uom,
                'sale_price' => $salePrice,
                'cost_price' => $costPrice,
                // Zero until VAT commences in Qatar -- see Qatar::taxNotice().
                'tax_rate' => 0,
                'track_stock' => $isGoods ? 1 : 0,
                'reorder_level' => $reorder,
                'income_account_id' => $isGoods ? $salesAccount : $serviceAccount,
                'expense_account_id' => $isGoods ? null : $purchasesAccount,
                'inventory_account_id' => $isGoods ? $inventoryAccount : null,
                'is_active' => 1,
                'created_at' => $now,
            ]);
        }

        return $ids;
    }

    private static function seedEmployees(): void
    {
        // Join dates are set relative to today so the gratuity and leave
        // figures are interesting whenever the demo is loaded.
        $rows = [
            ['Mohammed Abdullah Al Kuwari', 'محمد عبدالله الكواري', '27463400157', 'Qatari', 'General Manager', 'Management', '-8 years', 2500000, 800000, 300000, 'QNBA', '000000000012345678901'],
            ['Rajesh Kumar Nair', 'راجيش كومار نائير', '28241600842', 'Indian', 'Accountant', 'Finance', '-6 years', 900000, 300000, 150000, 'CBQA', '000000000023456789012'],
            ['Ahmed Hassan Ibrahim', 'أحمد حسن إبراهيم', '28581800391', 'Egyptian', 'Senior Technician', 'Operations', '-4 years', 550000, 200000, 100000, 'DOHB', '000000000034567890123'],
            ['Imran Ali Khan', 'عمران علي خان', '29158600247', 'Pakistani', 'Technician', 'Operations', '-2 years', 380000, 150000, 80000, 'QIBK', '000000000045678901234'],
            ['Sunil Perera', 'سونيل بيريرا', '29314400558', 'Sri Lankan', 'Storekeeper', 'Operations', '-18 months', 320000, 120000, 70000, 'MAFR', '000000000056789012345'],
            ['Maria Santos Cruz', 'ماريا سانتوس كروز', '29027000163', 'Filipino', 'Administrator', 'Finance', '-10 months', 400000, 150000, 80000, 'QNBA', '000000000067890123456'],
        ];

        foreach ($rows as [$nameEn, $nameAr, $qid, $nationality, $designation, $department, $joinOffset, $basic, $housing, $transport, $bankCode, $account]) {
            $joinDate = date('Y-m-d', strtotime($joinOffset));
            Hr::save([
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'qid' => $qid,
                // Staggered expiries so the dashboard alerts have something
                // to show without everything being in crisis at once.
                'qid_expiry' => date('Y-m-d', strtotime('+' . random_int(20, 400) . ' days')),
                'passport_number' => 'P' . random_int(1000000, 9999999),
                'passport_expiry' => date('Y-m-d', strtotime('+' . random_int(200, 1500) . ' days')),
                'visa_number' => (string) random_int(100000000, 999999999),
                'visa_expiry' => date('Y-m-d', strtotime('+' . random_int(30, 500) . ' days')),
                'health_card_expiry' => date('Y-m-d', strtotime('+' . random_int(10, 350) . ' days')),
                'contract_expiry' => date('Y-m-d', strtotime('+' . random_int(100, 900) . ' days')),
                'nationality' => $nationality,
                'designation' => $designation,
                'department' => $department,
                'sponsor' => 'Al Khaleej Trading & Maintenance W.L.L.',
                'join_date' => $joinDate,
                'status' => Hr::STATUS_ACTIVE,
                'basic_salary' => $basic,
                'housing_allowance' => $housing,
                'transport_allowance' => $transport,
                'food_allowance' => 0,
                'other_allowance' => 0,
                'bank_name' => Qatar::bankName(self::iban($bankCode, $account)),
                'iban' => self::iban($bankCode, $account),
                'salary_frequency' => 'M',
                'contract_hours_month' => 208,
            ]);
        }
    }

    private static function seedTrading(array $contacts, array $items): void
    {
        $bankAccount = ChartOfAccounts::id('bank');
        $rentAccount = ChartOfAccounts::id('rent_expense');
        $utilitiesAccount = ChartOfAccounts::id('utilities_expense');

        // Opening capital, so the balance sheet is not empty.
        \App\Services\Ledger::post(
            date('Y-01-01'),
            [
                ['account_id' => $bankAccount, 'debit' => 50000000, 'credit' => 0, 'memo' => 'Opening bank balance'],
                ['account_id' => ChartOfAccounts::id('share_capital'), 'debit' => 0, 'credit' => 50000000, 'memo' => 'Share capital'],
            ],
            'Opening balances',
            'opening'
        );

        // Stock purchases, three months back so there is history.
        $billDate = date('Y-m-d', strtotime('-75 days'));
        $billId = Purchases::saveBill(
            [
                'contact_id' => $contacts['Gulf Electrical Supplies W.L.L.'],
                'supplier_invoice_no' => 'GES-2291',
                'issue_date' => $billDate,
            ],
            [
                ['item_id' => $items['SPLIT-18'], 'description' => 'Split AC 1.5 Ton', 'quantity' => 40, 'unit_price' => '1320.00', 'uom' => 'PCS'],
                ['item_id' => $items['CABLE-25'], 'description' => 'Copper cable 2.5mm', 'quantity' => 90, 'unit_price' => '285.00', 'uom' => 'ROLL'],
                ['item_id' => $items['MCB-32'], 'description' => 'Circuit breaker 32A', 'quantity' => 400, 'unit_price' => '48.00', 'uom' => 'PCS'],
            ]
        );
        Purchases::post($billId);

        $billId2 = Purchases::saveBill(
            [
                'contact_id' => $contacts['Qatar Industrial Tools Est.'],
                'supplier_invoice_no' => 'QIT-8841',
                'issue_date' => date('Y-m-d', strtotime('-50 days')),
            ],
            [
                ['item_id' => $items['LED-PANEL'], 'description' => 'LED panel 36W', 'quantity' => 280, 'unit_price' => '72.00', 'uom' => 'PCS'],
                ['item_id' => $items['PUMP-1HP'], 'description' => 'Water pump 1HP', 'quantity' => 20, 'unit_price' => '680.00', 'uom' => 'PCS'],
            ]
        );
        Purchases::post($billId2);

        // Rent and Kahramaa, posted straight to the expense accounts.
        $rentBill = Purchases::saveBill(
            [
                'contact_id' => $contacts['Salwa Property Management'],
                'supplier_invoice_no' => 'RENT-' . date('Ym'),
                'issue_date' => date('Y-m-01'),
            ],
            [['account_id' => $rentAccount, 'description' => 'Warehouse and office rent', 'quantity' => 1, 'unit_price' => '18000.00', 'uom' => 'MONTH']]
        );
        Purchases::post($rentBill);

        $kahramaa = Purchases::saveBill(
            [
                'contact_id' => $contacts['Kahramaa'],
                'supplier_invoice_no' => 'KM-' . date('Ym'),
                'issue_date' => date('Y-m-05'),
            ],
            [['account_id' => $utilitiesAccount, 'description' => 'Electricity and water', 'quantity' => 1, 'unit_price' => '3850.00', 'uom' => 'MONTH']]
        );
        Purchases::post($kahramaa);

        // Sales spread over the last two months.
        $salesPlan = [
            ['Qatar Foundation Facilities Management', '-68 days', 'LPO-QF-76802', [
                ['SPLIT-18', 8, '1850.00'], ['INSTALL-AC', 8, '350.00'], ['LABOUR-HR', 40, '90.00'],
            ]],
            ['Al Meera Consumer Goods', '-60 days', 'AM-PO-9714', [
                ['LED-PANEL', 80, '120.00'], ['MCB-32', 100, '85.00'],
            ]],
            ['Doha Marine Services', '-54 days', 'DMS-3320', [
                ['PUMP-1HP', 4, '950.00'], ['CABLE-25', 15, '420.00'], ['LABOUR-HR', 36, '90.00'],
            ]],
            ['Lusail Hospitality Group', '-47 days', 'LHG-2088', [
                ['AMC-ANNUAL', 2, '12000.00'], ['SPLIT-18', 6, '1850.00'], ['INSTALL-AC', 6, '350.00'],
            ]],
            ['Qatar Foundation Facilities Management', '-42 days', 'LPO-QF-77120', [
                ['SPLIT-18', 6, '1850.00'], ['INSTALL-AC', 6, '350.00'], ['AMC-ANNUAL', 1, '12000.00'],
            ]],
            ['Barwa Real Estate Company', '-35 days', 'PO-BRW-4410', [
                ['LED-PANEL', 60, '120.00'], ['CABLE-25', 12, '420.00'], ['LABOUR-HR', 24, '90.00'],
            ]],
            ['Al Meera Consumer Goods', '-21 days', 'AM-PO-9931', [
                ['MCB-32', 80, '85.00'], ['LED-PANEL', 30, '120.00'], ['LABOUR-HR', 40, '90.00'],
            ]],
            ['Doha Marine Services', '-12 days', '', [
                ['PUMP-1HP', 3, '950.00'], ['LABOUR-HR', 8, '90.00'], ['INSTALL-AC', 3, '350.00'],
            ]],
            ['Lusail Hospitality Group', '-5 days', 'LHG-2201', [
                ['AMC-ANNUAL', 2, '12000.00'], ['SPLIT-18', 5, '1850.00'], ['INSTALL-AC', 5, '350.00'],
            ]],
            ['Qatar Foundation Facilities Management', '-2 days', 'LPO-QF-77455', [
                ['SPLIT-18', 4, '1850.00'], ['CABLE-25', 10, '420.00'], ['LABOUR-HR', 32, '90.00'],
            ]],
            ['Barwa Real Estate Company', '-1 days', 'PO-BRW-4688', [
                ['MCB-32', 60, '85.00'], ['LED-PANEL', 40, '120.00'], ['AMC-ANNUAL', 1, '12000.00'],
            ]],
        ];

        // Keyed by customer so the payments below stay correct however many
        // invoices are added to the plan above.
        $itemNames = array_column(
            \App\Core\Database::all('SELECT sku, name_en FROM items'),
            'name_en',
            'sku'
        );
        $invoicesByCustomer = [];
        foreach ($salesPlan as [$customer, $offset, $lpo, $lines]) {
            $rows = [];
            foreach ($lines as [$sku, $qty, $price]) {
                $rows[] = [
                    'item_id' => $items[$sku],
                    'description' => $itemNames[$sku] ?? $sku,
                    'quantity' => $qty,
                    'unit_price' => $price,
                ];
            }
            $invoiceId = Sales::saveInvoice(
                [
                    'contact_id' => $contacts[$customer],
                    'issue_date' => date('Y-m-d', strtotime($offset)),
                    'lpo_number' => $lpo,
                ],
                $rows
            );
            Sales::post($invoiceId);
            $invoicesByCustomer[$customer][] = $invoiceId;
        }

        // The older invoices are settled in full and one recent one is part
        // paid, so the ageing report and the statement both have something in
        // them.
        $settleInFull = [
            'Qatar Foundation Facilities Management' => ['-38 days', 'cheque', '000451'],
            'Doha Marine Services' => ['-30 days', 'bank', ''],
            'Lusail Hospitality Group' => ['-22 days', 'cheque', '000478'],
        ];

        foreach ($settleInFull as $customer => [$offset, $method, $chequeNo]) {
            $invoiceId = $invoicesByCustomer[$customer][0];
            $invoice = Sales::find($invoiceId);
            Payments::record(
                [
                    'direction' => Payments::IN,
                    'contact_id' => $contacts[$customer],
                    'payment_date' => date('Y-m-d', strtotime($offset)),
                    'method' => $method,
                    'cheque_number' => $chequeNo,
                    'bank_name' => $method === 'cheque' ? 'Qatar National Bank' : '',
                    'account_id' => $bankAccount,
                    'amount' => (int) $invoice['total'],
                    'reference' => 'Settlement of ' . $invoice['number'],
                ],
                [['doc_id' => $invoiceId, 'amount' => (int) $invoice['total']]]
            );
        }

        $partialInvoiceId = $invoicesByCustomer['Al Meera Consumer Goods'][0];
        $partialInvoice = Sales::find($partialInvoiceId);
        $partial = intdiv((int) $partialInvoice['total'], 2);
        Payments::record(
            [
                'direction' => Payments::IN,
                'contact_id' => $contacts['Al Meera Consumer Goods'],
                'payment_date' => date('Y-m-d', strtotime('-4 days')),
                'method' => 'bank',
                'account_id' => $bankAccount,
                'amount' => $partial,
                'reference' => 'Part payment',
            ],
            [['doc_id' => $partialInvoiceId, 'amount' => $partial]]
        );

        // A post-dated cheque, which every SME in Qatar is juggling.
        Payments::record(
            [
                'direction' => Payments::IN,
                'contact_id' => $contacts['Barwa Real Estate Company'],
                'payment_date' => date('Y-m-d'),
                'method' => 'cheque',
                'cheque_number' => '100392',
                'cheque_date' => date('Y-m-d', strtotime('+21 days')),
                'bank_name' => 'Commercial Bank of Qatar',
                'account_id' => $bankAccount,
                'amount' => 2500000,
                'reference' => 'Post-dated cheque on account',
            ]
        );

        // Pay the first supplier bill.
        $bill = Purchases::find($billId);
        Payments::record(
            [
                'direction' => Payments::OUT,
                'contact_id' => $contacts['Gulf Electrical Supplies W.L.L.'],
                'payment_date' => date('Y-m-d', strtotime('-40 days')),
                'method' => 'bank',
                'account_id' => $bankAccount,
                'amount' => (int) $bill['total'],
                'reference' => 'Settlement of ' . $bill['number'],
            ],
            [['doc_id' => $billId, 'amount' => (int) $bill['total']]]
        );
    }

    /**
     * A couple of live quotations, so the quote-to-invoice pipeline has
     * something in it on first login.
     */
    private static function seedQuotations(array $contacts, array $items): void
    {
        $plan = [
            [
                'Lusail Hospitality Group',
                '-9 days',
                'accepted',
                'Supply and installation of split air conditioning — Tower B',
                [['SPLIT-18', 12, '1850.00'], ['INSTALL-AC', 12, '350.00'], ['LABOUR-HR', 60, '90.00']],
            ],
            [
                'Barwa Real Estate Company',
                '-3 days',
                'sent',
                'Annual maintenance contract renewal and LED upgrade — Zone 55',
                [['AMC-ANNUAL', 3, '12000.00'], ['LED-PANEL', 220, '120.00'], ['MCB-32', 140, '85.00']],
            ],
        ];

        $itemNames = array_column(
            \App\Core\Database::all('SELECT sku, name_en FROM items'),
            'name_en',
            'sku'
        );

        foreach ($plan as [$customer, $offset, $status, $subject, $lines]) {
            $issueDate = date('Y-m-d', strtotime($offset));
            $rows = [];
            foreach ($lines as [$sku, $qty, $price]) {
                $rows[] = [
                    'item_id' => $items[$sku],
                    'description' => $itemNames[$sku] ?? $sku,
                    'quantity' => $qty,
                    'unit_price' => $price,
                ];
            }

            $prepared = \App\Services\DocumentTotals::prepareLines($rows);
            $totals = \App\Services\DocumentTotals::document($prepared);

            $quotationId = Database::insert('quotations', [
                'number' => Numbering::next('quotation', $issueDate),
                'contact_id' => $contacts[$customer],
                'issue_date' => $issueDate,
                'valid_until' => date('Y-m-d', strtotime($issueDate . ' +30 days')),
                'status' => $status,
                'subject' => $subject,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'terms' => Settings::get('invoice_terms_en'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($prepared as $line) {
                Database::insert('quotation_lines', [
                    'quotation_id' => $quotationId,
                    'item_id' => $line['item_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'uom' => $line['uom'],
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'tax_rate' => $line['tax_rate'],
                    'line_subtotal' => $line['line_subtotal'],
                    'line_tax' => $line['line_tax'],
                    'line_total' => $line['line_total'],
                    'line_no' => $line['line_no'],
                ]);
            }
        }
    }

    private static function seedPayroll(): void
    {
        // Last month's payroll, approved and paid, so the WPS screen has a
        // completed run to look at.
        $lastMonth = new \DateTimeImmutable('first day of last month');
        $runId = Payroll::openRun((int) $lastMonth->format('Y'), (int) $lastMonth->format('n'));

        $run = Payroll::find($runId);
        // Give one technician overtime, which is the usual case.
        foreach ($run['payslips'] as $payslip) {
            if (str_contains((string) $payslip['designation'], 'Technician')) {
                Payroll::updatePayslip((int) $payslip['id'], [
                    'working_days' => (int) $payslip['working_days'],
                    'overtime_hours' => 18,
                    'overtime_multiplier' => 1.25,
                ]);
                break;
            }
        }

        Payroll::approve($runId);
        Payroll::markPaid($runId, ChartOfAccounts::id('bank'));
    }
}
