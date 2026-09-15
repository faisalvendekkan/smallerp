<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Services\ChartOfAccounts;
use App\Services\Ledger;

/** The double-entry posting engine — the rule everything else depends on. */
final class LedgerTest extends TestCase
{
    private function setUpLedger(): void
    {
        $this->freshDatabase();
        $this->seedBase();
    }

    public function testPostsABalancedEntry(): void
    {
        $this->setUpLedger();
        $bank = ChartOfAccounts::id('bank');
        $capital = ChartOfAccounts::id('share_capital');

        $journalId = Ledger::post('2026-01-01', [
            ['account_id' => $bank, 'debit' => 5000000, 'credit' => 0],
            ['account_id' => $capital, 'debit' => 0, 'credit' => 5000000],
        ], 'Opening capital');

        $this->assertTrue($journalId > 0);
        $this->assertSame(5000000, Ledger::accountBalance($bank));
        $this->assertSame(5000000, Ledger::accountBalance($capital), 'credit-normal accounts read positive');
    }

    public function testRefusesAnUnbalancedEntry(): void
    {
        $this->setUpLedger();
        $bank = ChartOfAccounts::id('bank');
        $capital = ChartOfAccounts::id('share_capital');

        $this->assertThrows(
            static fn () => Ledger::post('2026-01-01', [
                ['account_id' => $bank, 'debit' => 5000000, 'credit' => 0],
                ['account_id' => $capital, 'debit' => 0, 'credit' => 4000000],
            ], 'Wrong'),
            'does not balance'
        );

        // Nothing may be written when the entry is rejected.
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM journals', [], 0));
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM journal_lines', [], 0));
    }

    public function testRefusesDegenerateEntries(): void
    {
        $this->setUpLedger();
        $bank = ChartOfAccounts::id('bank');

        $this->assertThrows(
            static fn () => Ledger::post('2026-01-01', [], 'Empty'),
            'at least one line'
        );
        $this->assertThrows(
            static fn () => Ledger::post('2026-01-01', [
                ['account_id' => $bank, 'debit' => 100, 'credit' => 100],
            ], 'Both columns'),
            'debit or a credit, not both'
        );
        $this->assertThrows(
            static fn () => Ledger::post('2026-01-01', [
                ['account_id' => $bank, 'debit' => -100, 'credit' => 0],
                ['account_id' => $bank, 'debit' => 0, 'credit' => -100],
            ], 'Negative'),
            'cannot be negative'
        );
        $this->assertThrows(
            static fn () => Ledger::post('2026-01-01', [
                ['account_id' => 99999, 'debit' => 100, 'credit' => 0],
                ['account_id' => $bank, 'debit' => 0, 'credit' => 100],
            ], 'Ghost account'),
            'no longer exists'
        );
    }

    public function testReversalMirrorsTheOriginal(): void
    {
        $this->setUpLedger();
        $bank = ChartOfAccounts::id('bank');
        $capital = ChartOfAccounts::id('share_capital');

        $journalId = Ledger::post('2026-01-01', [
            ['account_id' => $bank, 'debit' => 5000000, 'credit' => 0],
            ['account_id' => $capital, 'debit' => 0, 'credit' => 5000000],
        ], 'Original');

        $reversalId = Ledger::reverse($journalId, '2026-02-01');

        $this->assertSame(0, Ledger::accountBalance($bank), 'the reversal cancels the original');
        // Both entries stay in the books — that is the point of a reversal.
        $this->assertSame(2, (int) Database::value('SELECT COUNT(*) FROM journals', [], 0));
        $this->assertTrue(Ledger::integrityCheck()['balanced']);

        $reversal = Ledger::journal($reversalId);
        $this->assertSame(1, (int) $reversal['is_reversal']);
        $this->assertSame($journalId, (int) $reversal['reverses_id']);
    }

    public function testAnEntryCannotBeReversedTwice(): void
    {
        $this->setUpLedger();
        $journalId = Ledger::post('2026-01-01', [
            ['account_id' => ChartOfAccounts::id('bank'), 'debit' => 100000, 'credit' => 0],
            ['account_id' => ChartOfAccounts::id('share_capital'), 'debit' => 0, 'credit' => 100000],
        ], 'Once');

        Ledger::reverse($journalId);
        $this->assertThrows(static fn () => Ledger::reverse($journalId), 'already been reversed');
    }

    public function testTrialBalanceRespectsThePeriod(): void
    {
        $this->setUpLedger();
        $bank = ChartOfAccounts::id('bank');
        $sales = ChartOfAccounts::id('sales_income');

        Ledger::post('2026-01-15', [
            ['account_id' => $bank, 'debit' => 100000, 'credit' => 0],
            ['account_id' => $sales, 'debit' => 0, 'credit' => 100000],
        ], 'January');
        Ledger::post('2026-02-15', [
            ['account_id' => $bank, 'debit' => 300000, 'credit' => 0],
            ['account_id' => $sales, 'debit' => 0, 'credit' => 300000],
        ], 'February');

        $january = Ledger::totalsByType('2026-01-01', '2026-01-31');
        $february = Ledger::totalsByType('2026-02-01', '2026-02-28');
        $both = Ledger::totalsByType('2026-01-01', '2026-02-28');

        $this->assertSame(100000, $january['income'], 'January alone');
        $this->assertSame(300000, $february['income'], 'February alone');
        $this->assertSame(400000, $both['income'], 'both months');
    }

    public function testAccountLedgerRunsABalanceForward(): void
    {
        $this->setUpLedger();
        $bank = ChartOfAccounts::id('bank');
        $sales = ChartOfAccounts::id('sales_income');

        foreach ([100000, 200000, 300000] as $index => $amount) {
            Ledger::post('2026-0' . ($index + 1) . '-15', [
                ['account_id' => $bank, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $sales, 'debit' => 0, 'credit' => $amount],
            ], 'Sale ' . $index);
        }

        $ledger = Ledger::accountLedger($bank, '2026-02-01', '2026-03-31');
        $this->assertSame(100000, $ledger['opening'], 'January is the opening balance');
        $this->assertSame(2, count($ledger['lines']));
        $this->assertSame(600000, $ledger['closing']);
        $this->assertSame(300000, (int) $ledger['lines'][0]['running_balance']);
        $this->assertSame(600000, (int) $ledger['lines'][1]['running_balance']);
    }

    public function testIntegrityCheckSpotsAnUnbalancedLedger(): void
    {
        $this->setUpLedger();
        Ledger::post('2026-01-01', [
            ['account_id' => ChartOfAccounts::id('bank'), 'debit' => 100000, 'credit' => 0],
            ['account_id' => ChartOfAccounts::id('share_capital'), 'debit' => 0, 'credit' => 100000],
        ], 'Good entry');

        $this->assertTrue(Ledger::integrityCheck()['balanced']);

        // Corrupt the ledger behind the engine's back, the way a bad import or
        // a hand-edited database would, and confirm the check notices.
        Database::query('UPDATE journal_lines SET debit = 999999 WHERE debit > 0');
        $check = Ledger::integrityCheck();
        $this->assertFalse($check['balanced'], 'the check must notice tampering');
        $this->assertSame(1, count($check['unbalanced_entries']));
    }
}
