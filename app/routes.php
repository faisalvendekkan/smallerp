<?php

declare(strict_types=1);

/**
 * The routing table.
 *
 * Each route carries the permission needed to reach it, so authorisation is
 * visible here rather than buried in controller methods. `public` means no
 * sign-in is required.
 */

use App\Controllers\AccountingController;
use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\DashboardController;
use App\Controllers\EmployeeController;
use App\Controllers\InstallController;
use App\Controllers\InventoryController;
use App\Controllers\PayrollController;
use App\Controllers\PaymentController;
use App\Controllers\PurchaseController;
use App\Controllers\ReportController;
use App\Controllers\SalesController;
use App\Controllers\SettingsController;
use App\Core\Router;

$router = new Router();

// ---------------------------------------------------------------- Public
$router->get('/login', [AuthController::class, 'showLogin'], 'public');
$router->post('/login', [AuthController::class, 'login'], 'public');
$router->post('/logout', [AuthController::class, 'logout'], 'public');
$router->post('/locale', [AuthController::class, 'switchLocale'], 'public');
$router->get('/install', [InstallController::class, 'show'], 'public');
$router->post('/install', [InstallController::class, 'run'], 'public');

// ------------------------------------------------------------- Dashboard
$router->get('/', [DashboardController::class, 'index'], 'dashboard.view');

// -------------------------------------------------------------- Contacts
$router->get('/customers', [ContactController::class, 'customers'], 'contacts.view');
$router->get('/suppliers', [ContactController::class, 'suppliers'], 'contacts.view');
$router->get('/contacts/new', [ContactController::class, 'create'], 'contacts.create');
$router->post('/contacts', [ContactController::class, 'store'], 'contacts.create');
$router->get('/contacts/{id}', [ContactController::class, 'show'], 'contacts.view');
$router->get('/contacts/{id}/edit', [ContactController::class, 'edit'], 'contacts.edit');
$router->post('/contacts/{id}', [ContactController::class, 'update'], 'contacts.edit');
$router->post('/contacts/{id}/archive', [ContactController::class, 'archive'], 'contacts.edit');
$router->get('/contacts/{id}/statement', [ContactController::class, 'statement'], 'reports.view');
$router->get('/contacts/export', [ContactController::class, 'export'], 'contacts.view');

// ----------------------------------------------------------------- Items
$router->get('/items', [InventoryController::class, 'items'], 'items.view');
$router->get('/items/new', [InventoryController::class, 'createItem'], 'items.create');
$router->post('/items', [InventoryController::class, 'storeItem'], 'items.create');
$router->get('/items/{id}', [InventoryController::class, 'showItem'], 'items.view');
$router->get('/items/{id}/edit', [InventoryController::class, 'editItem'], 'items.edit');
$router->post('/items/{id}', [InventoryController::class, 'updateItem'], 'items.edit');
$router->get('/stock', [InventoryController::class, 'stock'], 'inventory.view');
$router->get('/stock/adjust', [InventoryController::class, 'adjustForm'], 'inventory.adjust');
$router->post('/stock/adjust', [InventoryController::class, 'adjust'], 'inventory.adjust');
$router->get('/stock/export', [InventoryController::class, 'exportStock'], 'inventory.view');
$router->get('/warehouses', [InventoryController::class, 'warehouses'], 'inventory.view');
$router->post('/warehouses', [InventoryController::class, 'storeWarehouse'], 'inventory.edit');

// ----------------------------------------------------------------- Sales
$router->get('/invoices', [SalesController::class, 'index'], 'sales.view');
$router->get('/invoices/new', [SalesController::class, 'create'], 'sales.create');
$router->post('/invoices', [SalesController::class, 'store'], 'sales.create');
$router->get('/invoices/export', [SalesController::class, 'export'], 'sales.view');
$router->get('/invoices/{id}', [SalesController::class, 'show'], 'sales.view');
$router->get('/invoices/{id}/edit', [SalesController::class, 'edit'], 'sales.edit');
$router->post('/invoices/{id}', [SalesController::class, 'update'], 'sales.edit');
$router->post('/invoices/{id}/post', [SalesController::class, 'post'], 'sales.post');
$router->post('/invoices/{id}/void', [SalesController::class, 'void'], 'sales.void');
$router->get('/invoices/{id}/print', [SalesController::class, 'printInvoice'], 'sales.view');

$router->get('/quotations', [SalesController::class, 'quotations'], 'sales.view');
$router->get('/quotations/new', [SalesController::class, 'createQuotation'], 'sales.create');
$router->post('/quotations', [SalesController::class, 'storeQuotation'], 'sales.create');
$router->get('/quotations/{id}', [SalesController::class, 'showQuotation'], 'sales.view');
$router->get('/quotations/{id}/edit', [SalesController::class, 'editQuotation'], 'sales.edit');
$router->post('/quotations/{id}', [SalesController::class, 'updateQuotation'], 'sales.edit');
$router->post('/quotations/{id}/status', [SalesController::class, 'quotationStatus'], 'sales.edit');
$router->post('/quotations/{id}/convert', [SalesController::class, 'convertQuotation'], 'sales.create');
$router->get('/quotations/{id}/print', [SalesController::class, 'printQuotation'], 'sales.view');

// ------------------------------------------------------------- Purchases
$router->get('/bills', [PurchaseController::class, 'index'], 'purchases.view');
$router->get('/bills/new', [PurchaseController::class, 'create'], 'purchases.create');
$router->post('/bills', [PurchaseController::class, 'store'], 'purchases.create');
$router->get('/bills/export', [PurchaseController::class, 'export'], 'purchases.view');
$router->get('/bills/{id}', [PurchaseController::class, 'show'], 'purchases.view');
$router->get('/bills/{id}/edit', [PurchaseController::class, 'edit'], 'purchases.edit');
$router->post('/bills/{id}', [PurchaseController::class, 'update'], 'purchases.edit');
$router->post('/bills/{id}/post', [PurchaseController::class, 'post'], 'purchases.post');
$router->post('/bills/{id}/void', [PurchaseController::class, 'void'], 'purchases.void');

// -------------------------------------------------------------- Payments
$router->get('/receipts', [PaymentController::class, 'receipts'], 'payments.view');
$router->get('/payments', [PaymentController::class, 'payments'], 'payments.view');
$router->get('/receipts/new', [PaymentController::class, 'createReceipt'], 'payments.create');
$router->get('/payments/new', [PaymentController::class, 'createPayment'], 'payments.create');
$router->post('/payments', [PaymentController::class, 'store'], 'payments.create');
$router->get('/payments/open-documents', [PaymentController::class, 'openDocuments'], 'payments.view');
$router->get('/payments/{id}', [PaymentController::class, 'show'], 'payments.view');
$router->post('/payments/{id}/void', [PaymentController::class, 'void'], 'payments.void');
$router->get('/payments/{id}/print', [PaymentController::class, 'printReceipt'], 'payments.view');

// ------------------------------------------------------------ Accounting
$router->get('/accounts', [AccountingController::class, 'accounts'], 'accounting.view');
$router->get('/accounts/new', [AccountingController::class, 'createAccount'], 'accounting.edit');
$router->post('/accounts', [AccountingController::class, 'storeAccount'], 'accounting.edit');
$router->get('/accounts/{id}', [AccountingController::class, 'ledger'], 'accounting.view');
$router->get('/accounts/{id}/edit', [AccountingController::class, 'editAccount'], 'accounting.edit');
$router->post('/accounts/{id}', [AccountingController::class, 'updateAccount'], 'accounting.edit');
$router->get('/journals', [AccountingController::class, 'journals'], 'accounting.view');
$router->get('/journals/new', [AccountingController::class, 'createJournal'], 'accounting.post');
$router->post('/journals', [AccountingController::class, 'storeJournal'], 'accounting.post');
$router->get('/journals/{id}', [AccountingController::class, 'showJournal'], 'accounting.view');
$router->post('/journals/{id}/reverse', [AccountingController::class, 'reverseJournal'], 'accounting.post');

// ---------------------------------------------------------- HR & payroll
$router->get('/employees', [EmployeeController::class, 'index'], 'hr.view');
$router->get('/employees/new', [EmployeeController::class, 'create'], 'hr.create');
$router->post('/employees', [EmployeeController::class, 'store'], 'hr.create');
$router->get('/employees/export', [EmployeeController::class, 'export'], 'hr.view');
$router->get('/employees/{id}', [EmployeeController::class, 'show'], 'hr.view');
$router->get('/employees/{id}/edit', [EmployeeController::class, 'edit'], 'hr.edit');
$router->post('/employees/{id}', [EmployeeController::class, 'update'], 'hr.edit');

$router->get('/leave', [EmployeeController::class, 'leave'], 'hr.view');
$router->post('/leave', [EmployeeController::class, 'storeLeave'], 'hr.edit');
$router->post('/leave/{id}/decide', [EmployeeController::class, 'decideLeave'], 'hr.edit');

$router->get('/gratuity', [EmployeeController::class, 'gratuity'], 'hr.view');
$router->get('/gratuity/calculate', [EmployeeController::class, 'settlementForm'], 'hr.edit');
$router->post('/gratuity/calculate', [EmployeeController::class, 'settlementPreview'], 'hr.edit');
$router->post('/gratuity/save', [EmployeeController::class, 'saveSettlement'], 'hr.edit');

$router->get('/payroll', [PayrollController::class, 'index'], 'payroll.view');
$router->post('/payroll/open', [PayrollController::class, 'open'], 'payroll.create');
$router->get('/payroll/{id}', [PayrollController::class, 'show'], 'payroll.view');
$router->post('/payroll/{id}/payslip/{payslipId}', [PayrollController::class, 'updatePayslip'], 'payroll.edit');
$router->post('/payroll/{id}/payslip/{payslipId}/remove', [PayrollController::class, 'removePayslip'], 'payroll.edit');
$router->post('/payroll/{id}/approve', [PayrollController::class, 'approve'], 'payroll.post');
$router->post('/payroll/{id}/paid', [PayrollController::class, 'markPaid'], 'payroll.post');
$router->get('/payroll/{id}/sif', [PayrollController::class, 'downloadSif'], 'payroll.view');
$router->get('/payroll/{id}/export', [PayrollController::class, 'export'], 'payroll.view');
$router->get('/payroll/payslip/{payslipId}', [PayrollController::class, 'printPayslip'], 'payroll.view');

// --------------------------------------------------------------- Reports
$router->get('/reports', [ReportController::class, 'index'], 'reports.view');
$router->get('/reports/trial-balance', [ReportController::class, 'trialBalance'], 'reports.view');
$router->get('/reports/profit-loss', [ReportController::class, 'profitAndLoss'], 'reports.view');
$router->get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'], 'reports.view');
$router->get('/reports/ageing', [ReportController::class, 'ageing'], 'reports.view');
$router->get('/reports/sales', [ReportController::class, 'sales'], 'reports.view');
$router->get('/reports/tax', [ReportController::class, 'tax'], 'reports.view');
$router->get('/reports/expiries', [ReportController::class, 'expiries'], 'reports.view');
$router->get('/reports/gratuity-liability', [ReportController::class, 'gratuityLiability'], 'reports.view');

// ------------------------------------------------------- Administration
$router->get('/settings', [SettingsController::class, 'index'], 'settings.view');
$router->post('/settings', [SettingsController::class, 'update'], 'settings.edit');
$router->get('/users', [SettingsController::class, 'users'], 'users.view');
$router->get('/users/new', [SettingsController::class, 'createUser'], 'users.create');
$router->post('/users', [SettingsController::class, 'storeUser'], 'users.create');
$router->get('/users/{id}/edit', [SettingsController::class, 'editUser'], 'users.edit');
$router->post('/users/{id}', [SettingsController::class, 'updateUser'], 'users.edit');
$router->get('/audit', [SettingsController::class, 'audit'], 'audit.view');
$router->get('/holidays', [SettingsController::class, 'holidays'], 'settings.view');
$router->post('/holidays', [SettingsController::class, 'storeHoliday'], 'settings.edit');
$router->post('/holidays/{id}/delete', [SettingsController::class, 'deleteHoliday'], 'settings.edit');

return $router;
