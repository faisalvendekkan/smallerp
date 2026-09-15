<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Services\ChartOfAccounts;
use App\Services\DocumentTotals;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Payments;
use App\Services\Purchases;
use App\Services\Sales;

/** Invoicing end to end: totals, posting, stock, payment and voiding. */
final class SalesTest extends TestCase
{
    private int $customerId;
    private int $supplierId;
    private int $itemId;
    private int $serviceId;

    private function setUpTrading(): void
    {
        $this->freshDatabase();
        $this->seedBase();

        $now = date('Y-m-d H:i:s');
        $this->customerId = Database::insert('contacts', [
            'code' => 'C-0001', 'kind' => 'customer', 'name_en' => 'Test Customer',
            'payment_terms_days' => 30, 'is_active' => 1, 'created_at' => $now,
        ]);
        $this->supplierId = Database::insert('contacts', [
            'code' => 'S-0001', 'kind' => 'supplier', 'name_en' => 'Test Supplier',
            'payment_terms_days' => 30, 'is_active' => 1, 'created_at' => $now,
        ]);
        $this->itemId = Database::insert('items', [
            'sku' => 'WIDGET', 'name_en' => 'Widget', 'kind' => 'goods', 'uom' => 'PCS',
            'sale_price' => 10000, 'cost_price' => 6000, 'tax_rate' => 0,
            'track_stock' => 1, 'is_active' => 1, 'created_at' => $now,
        ]);
        $this->serviceId = Database::insert('items', [
            'sku' => 'LABOUR', 'name_en' => 'Labour', 'kind' => 'service', 'uom' => 'HOUR',
            'sale_price' => 9000, 'cost_price' => 0, 'tax_rate' => 0,
            'track_stock' => 0, 'is_active' => 1, 'created_at' => $now,
        ]);

        // Receive stock so there is something to sell.
        $billId = Purchases::saveBill(
            ['contact_id' => $this->supplierId, 'issue_date' => '2026-01-01', 'supplier_invoice_no' => 'SUP-1'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 100, 'unit_price' => '60.00']]
        );
        Purchases::post($billId);
    }

    public function testLineTotalsAreExact(): void
    {
        $line = DocumentTotals::line(3, 33333, 0, 0);
        $this->assertSame(99999, $line['line_total'], '3 x 333.33');

        $discounted = DocumentTotals::line(10, 10000, 10, 0);
        $this->assertSame(90000, $discounted['line_subtotal'], '10% off 1,000.00');
        $this->assertSame(10000, $discounted['line_discount']);

        $taxed = DocumentTotals::line(10, 10000, 0, 5);
        $this->assertSame(100000, $taxed['line_subtotal']);
        $this->assertSame(5000, $taxed['line_tax'], '5% tax');
        $this->assertSame(105000, $taxed['line_total']);
    }

    public function testTaxInclusivePricingExtractsRatherThanAdds(): void
    {
        $line = DocumentTotals::line(1, 10500, 0, 5, true);
        $this->assertSame(10000, $line['line_subtotal'], 'the net is backed out');
        $this->assertSame(500, $line['line_tax']);
        $this->assertSame(10500, $line['line_total'], 'the entered price is unchanged');
    }

    public function testRejectsImpossibleLines(): void
    {
        $this->assertThrows(static fn () => DocumentTotals::line(-1, 10000), 'Quantity cannot be negative');
        $this->assertThrows(static fn () => DocumentTotals::line(1, -10000), 'Unit price cannot be negative');
        $this->assertThrows(static fn () => DocumentTotals::line(1, 10000, 150), 'Discount must be between');
        $this->assertThrows(static fn () => DocumentTotals::line(1, 10000, 0, 150), 'Tax rate must be between');
    }

    public function testPostingBooksRevenueReceivableAndCostOfSales(): void
    {
        $this->setUpTrading();

        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => '100.00']]
        );
        Sales::post($invoiceId);

        $this->assertSame(100000, Ledger::accountBalance(ChartOfAccounts::id('accounts_receivable')));
        $this->assertSame(100000, Ledger::accountBalance(ChartOfAccounts::id('sales_income')));
        $this->assertSame(60000, Ledger::accountBalance(ChartOfAccounts::id('cost_of_goods_sold')), '10 x 60.00 cost');
        $this->assertSame(90.0, Inventory::quantityOnHand($this->itemId), 'stock came out');
        $this->assertTrue(Ledger::integrityCheck()['balanced']);
    }

    public function testServiceLinesGoToTheServiceRevenueAccount(): void
    {
        $this->setUpTrading();

        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->serviceId, 'description' => 'Labour', 'quantity' => 8, 'unit_price' => '90.00']]
        );
        Sales::post($invoiceId);

        $this->assertSame(72000, Ledger::accountBalance(ChartOfAccounts::id('service_income')));
        $this->assertSame(0, Ledger::accountBalance(ChartOfAccounts::id('sales_income')));
        $this->assertSame(0, Ledger::accountBalance(ChartOfAccounts::id('cost_of_goods_sold')), 'no stock cost');
    }

    public function testRefusesToSellStockThatIsNotThere(): void
    {
        $this->setUpTrading();

        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 500, 'unit_price' => '100.00']]
        );

        $this->assertThrows(static fn () => Sales::post($invoiceId), 'Not enough stock');
        // The failed post must leave nothing behind.
        $this->assertSame(100.0, Inventory::quantityOnHand($this->itemId), 'stock is untouched');
        $this->assertSame('draft', Database::value('SELECT status FROM sales_invoices WHERE id = ?', [$invoiceId]));
    }

    public function testAPostedInvoiceCannotBeEdited(): void
    {
        $this->setUpTrading();
        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => '100.00']]
        );
        Sales::post($invoiceId);

        $this->assertThrows(
            fn () => Sales::saveInvoice(
                ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
                [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => '100.00']],
                $invoiceId
            ),
            'can no longer be edited'
        );
    }

    public function testVoidingReversesTheJournalAndReturnsTheStock(): void
    {
        $this->setUpTrading();
        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => '100.00']]
        );
        Sales::post($invoiceId);
        Sales::void($invoiceId, 'Raised against the wrong customer');

        $this->assertSame(0, Ledger::accountBalance(ChartOfAccounts::id('accounts_receivable')));
        $this->assertSame(0, Ledger::accountBalance(ChartOfAccounts::id('sales_income')));
        $this->assertSame(100.0, Inventory::quantityOnHand($this->itemId), 'stock came back');
        $this->assertTrue(Ledger::integrityCheck()['balanced']);
    }

    public function testAPaidInvoiceCannotBeVoided(): void
    {
        $this->setUpTrading();
        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => '100.00']]
        );
        Sales::post($invoiceId);
        Payments::record([
            'direction' => Payments::IN,
            'contact_id' => $this->customerId,
            'payment_date' => '2026-02-10',
            'method' => 'bank',
            'account_id' => ChartOfAccounts::id('bank'),
            'amount' => 100000,
        ], [['doc_id' => $invoiceId, 'amount' => 100000]]);

        $this->assertThrows(static fn () => Sales::void($invoiceId, 'Changed my mind'), 'payments against it');
    }

    public function testPaymentUpdatesTheInvoiceStatus(): void
    {
        $this->setUpTrading();
        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => '100.00']]
        );
        Sales::post($invoiceId);

        Payments::record([
            'direction' => Payments::IN, 'contact_id' => $this->customerId,
            'payment_date' => '2026-02-10', 'method' => 'bank',
            'account_id' => ChartOfAccounts::id('bank'), 'amount' => 40000,
        ], [['doc_id' => $invoiceId, 'amount' => 40000]]);

        $invoice = Sales::find($invoiceId);
        $this->assertSame('partial', $invoice['status']);
        $this->assertSame(60000, (int) $invoice['balance_due']);

        Payments::record([
            'direction' => Payments::IN, 'contact_id' => $this->customerId,
            'payment_date' => '2026-02-20', 'method' => 'cash',
            'account_id' => ChartOfAccounts::id('cash'), 'amount' => 60000,
        ], [['doc_id' => $invoiceId, 'amount' => 60000]]);

        $invoice = Sales::find($invoiceId);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame(0, (int) $invoice['balance_due']);
        $this->assertSame(0, Ledger::accountBalance(ChartOfAccounts::id('accounts_receivable')));
    }

    public function testOverpayingAnInvoiceIsRefused(): void
    {
        $this->setUpTrading();
        $invoiceId = Sales::saveInvoice(
            ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => '100.00']]
        );
        Sales::post($invoiceId);

        $this->assertThrows(
            fn () => Payments::record([
                'direction' => Payments::IN, 'contact_id' => $this->customerId,
                'payment_date' => '2026-02-10', 'method' => 'bank',
                'account_id' => ChartOfAccounts::id('bank'), 'amount' => 50000,
            ], [['doc_id' => $invoiceId, 'amount' => 50000]]),
            'only'
        );
    }

    public function testUnallocatedReceiptBecomesACustomerAdvance(): void
    {
        $this->setUpTrading();
        // Money received with nothing to match it against is still the
        // customer's, and belongs on the balance sheet, not in revenue.
        Payments::record([
            'direction' => Payments::IN, 'contact_id' => $this->customerId,
            'payment_date' => '2026-02-10', 'method' => 'bank',
            'account_id' => ChartOfAccounts::id('bank'), 'amount' => 250000,
        ]);

        $this->assertSame(250000, Ledger::accountBalance(ChartOfAccounts::id('customer_advances')));
        $this->assertSame(250000, Ledger::accountBalance(ChartOfAccounts::id('bank')));
        $this->assertTrue(Ledger::integrityCheck()['balanced']);
    }

    public function testDuplicateSupplierInvoiceIsRefused(): void
    {
        $this->setUpTrading();
        // Paying the same supplier bill twice is a real and expensive mistake.
        $this->assertThrows(
            fn () => Purchases::saveBill(
                ['contact_id' => $this->supplierId, 'issue_date' => '2026-03-01', 'supplier_invoice_no' => 'SUP-1'],
                [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => '60.00']]
            ),
            'already recorded'
        );
    }

    public function testWeightedAverageCostFollowsPurchases(): void
    {
        $this->setUpTrading();
        // A second delivery at a different price moves the average.
        $billId = Purchases::saveBill(
            ['contact_id' => $this->supplierId, 'issue_date' => '2026-02-01', 'supplier_invoice_no' => 'SUP-2'],
            [['item_id' => $this->itemId, 'description' => 'Widget', 'quantity' => 100, 'unit_price' => '80.00']]
        );
        Purchases::post($billId);

        // 100 at 60.00 and 100 at 80.00 averages 70.00.
        $this->assertSame(7000, Inventory::averageCost($this->itemId));
        $this->assertSame(200.0, Inventory::quantityOnHand($this->itemId));
        $this->assertSame(1400000, Inventory::stockValue($this->itemId));
    }

    public function testDocumentNumbersAreSequentialAndUnique(): void
    {
        $this->setUpTrading();
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $invoiceId = Sales::saveInvoice(
                ['contact_id' => $this->customerId, 'issue_date' => '2026-02-01'],
                [['item_id' => $this->serviceId, 'description' => 'Labour', 'quantity' => 1, 'unit_price' => '90.00']]
            );
            $numbers[] = Database::value('SELECT number FROM sales_invoices WHERE id = ?', [$invoiceId]);
        }

        $this->assertSame(5, count(array_unique($numbers)), 'no number is issued twice');
        $this->assertSame('INV-2026-0001', $numbers[0]);
        $this->assertSame('INV-2026-0005', $numbers[4]);
    }
}
