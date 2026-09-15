<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Support\Money;
use App\Support\ValidationException;

/**
 * The double-entry posting engine.
 *
 * Every financial document in SmallERP -- an invoice, a bill, a payment, a
 * payroll run -- ends up here as a journal. Nothing writes to journal_lines
 * directly, and nothing unbalanced is ever accepted: that single rule is what
 * makes the trial balance trustworthy.
 *
 * Journals are immutable once posted. A mistake is corrected by reversing the
 * entry, which leaves both the error and the correction visible to an auditor,
 * rather than by editing history.
 */
final class Ledger
{
    /**
     * Post a balanced journal entry.
     *
     * @param array<int,array{account_id:int,debit?:int,credit?:int,memo?:string,contact_id?:int|null}> $lines
     *
     * @throws ValidationException if the entry does not balance or is empty.
     */
    public static function post(
        string $date,
        array $lines,
        string $memo = '',
        string $sourceType = 'manual',
        ?int $sourceId = null
    ): int {
        $clean = self::normaliseLines($lines);

        $totalDebit = array_sum(array_column($clean, 'debit'));
        $totalCredit = array_sum(array_column($clean, 'credit'));

        if ($clean === []) {
            throw new ValidationException('A journal entry must have at least one line with an amount');
        }
        if (count($clean) < 2) {
            throw new ValidationException('A journal entry needs at least two lines — one debit and one credit');
        }
        if ($totalDebit !== $totalCredit) {
            throw new ValidationException(sprintf(
                'The entry does not balance: debits %s, credits %s, difference %s',
                Money::format($totalDebit),
                Money::format($totalCredit),
                Money::format(abs($totalDebit - $totalCredit))
            ));
        }
        if ($totalDebit === 0) {
            throw new ValidationException('A journal entry cannot be for zero');
        }

        return Database::transaction(static function () use ($date, $clean, $memo, $sourceType, $sourceId, $totalDebit): int {
            $journalId = Database::insert('journals', [
                'number' => Numbering::next('journal', $date),
                'entry_date' => $date,
                'memo' => mb_substr($memo, 0, 255),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'is_posted' => 1,
                'total_debit' => $totalDebit,
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($clean as $index => $line) {
                Database::insert('journal_lines', [
                    'journal_id' => $journalId,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'memo' => mb_substr((string) ($line['memo'] ?? ''), 0, 255),
                    'contact_id' => $line['contact_id'] ?? null,
                    'line_no' => $index + 1,
                ]);
            }

            AuditLog::record('ledger.post', 'journal', $journalId, [
                'source' => $sourceType,
                'source_id' => $sourceId,
                'total' => Money::toDecimalString($totalDebit),
            ]);

            return $journalId;
        });
    }

    /**
     * Drop zero lines, reject lines that are both a debit and a credit, and
     * make sure every account actually exists.
     */
    private static function normaliseLines(array $lines): array
    {
        $accountIds = [];
        $clean = [];

        foreach ($lines as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);
            $accountId = (int) ($line['account_id'] ?? 0);

            if ($debit === 0 && $credit === 0) {
                continue; // A blank line on a form is not an error.
            }
            if ($debit < 0 || $credit < 0) {
                // A negative debit is a credit; allowing both spellings makes
                // it far too easy to post an entry that looks balanced and is not.
                throw new ValidationException(
                    'Amounts cannot be negative — put the amount in the other column instead'
                );
            }
            if ($debit !== 0 && $credit !== 0) {
                throw new ValidationException('A line can be a debit or a credit, not both');
            }
            if ($accountId <= 0) {
                throw new ValidationException('Every journal line must name an account');
            }

            $accountIds[$accountId] = true;
            $clean[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? '',
                'contact_id' => $line['contact_id'] ?? null,
            ];
        }

        if ($accountIds !== []) {
            $ids = array_keys($accountIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $found = Database::all(
                "SELECT id, is_active FROM accounts WHERE id IN ({$placeholders})",
                $ids
            );
            $foundIds = array_column($found, 'id');
            $missing = array_diff($ids, array_map('intval', $foundIds));
            if ($missing !== []) {
                throw new ValidationException('A journal line refers to an account that no longer exists');
            }
            foreach ($found as $account) {
                if ((int) $account['is_active'] !== 1) {
                    throw new ValidationException(
                        'Account ' . $account['id'] . ' is archived and cannot be posted to'
                    );
                }
            }
        }

        return $clean;
    }

    /**
     * Reverse a posted journal, creating a mirror-image entry.
     *
     * This is how every void and correction is handled. The original stays in
     * the books, which is what an auditor expects to find.
     */
    public static function reverse(int $journalId, ?string $date = null, string $memo = ''): int
    {
        $journal = Database::first('SELECT * FROM journals WHERE id = ?', [$journalId]);
        if (!$journal) {
            throw new ValidationException('That journal entry does not exist');
        }

        $existing = Database::value('SELECT id FROM journals WHERE reverses_id = ?', [$journalId]);
        if ($existing !== null) {
            throw new ValidationException('That entry has already been reversed');
        }

        $lines = Database::all('SELECT * FROM journal_lines WHERE journal_id = ? ORDER BY line_no', [$journalId]);
        $date ??= date('Y-m-d');
        $memo = $memo !== '' ? $memo : 'Reversal of ' . $journal['number'];

        return Database::transaction(static function () use ($journal, $lines, $date, $memo, $journalId): int {
            $totalDebit = array_sum(array_map(static fn ($l) => (int) $l['credit'], $lines));

            $newId = Database::insert('journals', [
                'number' => Numbering::next('journal', $date),
                'entry_date' => $date,
                'memo' => mb_substr($memo, 0, 255),
                'source_type' => $journal['source_type'],
                'source_id' => $journal['source_id'],
                'is_posted' => 1,
                'is_reversal' => 1,
                'reverses_id' => $journalId,
                'total_debit' => $totalDebit,
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($lines as $index => $line) {
                Database::insert('journal_lines', [
                    'journal_id' => $newId,
                    'account_id' => (int) $line['account_id'],
                    // Swap the columns: that is the whole of a reversal.
                    'debit' => (int) $line['credit'],
                    'credit' => (int) $line['debit'],
                    'memo' => $line['memo'],
                    'contact_id' => $line['contact_id'],
                    'line_no' => $index + 1,
                ]);
            }

            AuditLog::record('ledger.reverse', 'journal', $newId, ['reverses' => $journalId]);

            return $newId;
        });
    }

    /** Net movement on an account over a period, signed by its normal balance. */
    public static function accountBalance(int $accountId, ?string $from = null, ?string $to = null): int
    {
        $where = ['jl.account_id = ?', 'j.is_posted = 1'];
        $params = [$accountId];
        if ($from !== null) {
            $where[] = 'j.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'j.entry_date <= ?';
            $params[] = $to;
        }

        $row = Database::first(
            'SELECT COALESCE(SUM(jl.debit), 0) AS d, COALESCE(SUM(jl.credit), 0) AS c
             FROM journal_lines jl
             JOIN journals j ON j.id = jl.journal_id
             WHERE ' . implode(' AND ', $where),
            $params
        ) ?? ['d' => 0, 'c' => 0];

        $type = (string) Database::value('SELECT type FROM accounts WHERE id = ?', [$accountId], 'asset');
        $debitMinusCredit = (int) $row['d'] - (int) $row['c'];

        return ChartOfAccounts::isDebitNormal($type) ? $debitMinusCredit : -$debitMinusCredit;
    }

    /**
     * Trial balance for a period.
     *
     * @return array<int,array<string,mixed>> One row per account with a movement.
     */
    public static function trialBalance(?string $from = null, ?string $to = null): array
    {
        $where = ['j.is_posted = 1'];
        $params = [];
        if ($from !== null) {
            $where[] = 'j.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'j.entry_date <= ?';
            $params[] = $to;
        }

        // The period filter has to be applied *before* the outer join, not in
        // its ON clause: a filter there leaves the non-matching journal_lines
        // rows in place and they get summed anyway, which silently reports
        // every period as if it were all of time.
        $rows = Database::all(
            'SELECT a.id, a.code, a.name_en, a.name_ar, a.type, a.subtype,
                    COALESCE(SUM(jl.debit), 0)  AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM accounts a
             LEFT JOIN (
                 SELECT jl.account_id, jl.debit, jl.credit
                 FROM journal_lines jl
                 JOIN journals j ON j.id = jl.journal_id
                 WHERE ' . implode(' AND ', $where) . '
             ) jl ON jl.account_id = a.id
             GROUP BY a.id, a.code, a.name_en, a.name_ar, a.type, a.subtype
             ORDER BY a.code',
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $debit = (int) $row['total_debit'];
            $credit = (int) $row['total_credit'];
            if ($debit === 0 && $credit === 0) {
                continue; // Dormant accounts only clutter the report.
            }
            $net = $debit - $credit;
            $row['total_debit'] = $debit;
            $row['total_credit'] = $credit;
            // A trial balance shows each account's net in a single column.
            $row['balance_debit'] = $net > 0 ? $net : 0;
            $row['balance_credit'] = $net < 0 ? -$net : 0;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Totals by account type, used for the P&L and balance sheet.
     *
     * @return array<string,int> Keyed by account type, signed by normal balance.
     */
    public static function totalsByType(?string $from = null, ?string $to = null): array
    {
        $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
        foreach (self::trialBalance($from, $to) as $row) {
            $net = (int) $row['total_debit'] - (int) $row['total_credit'];
            $totals[$row['type']] += ChartOfAccounts::isDebitNormal($row['type']) ? $net : -$net;
        }

        return $totals;
    }

    /** Ledger detail for one account: every line with a running balance. */
    public static function accountLedger(int $accountId, ?string $from = null, ?string $to = null): array
    {
        $opening = $from !== null
            ? self::accountBalance($accountId, null, date('Y-m-d', strtotime($from . ' -1 day')))
            : 0;

        $where = ['jl.account_id = ?', 'j.is_posted = 1'];
        $params = [$accountId];
        if ($from !== null) {
            $where[] = 'j.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'j.entry_date <= ?';
            $params[] = $to;
        }

        $lines = Database::all(
            'SELECT jl.*, j.number, j.entry_date, j.memo AS journal_memo, j.source_type, j.source_id
             FROM journal_lines jl
             JOIN journals j ON j.id = jl.journal_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY j.entry_date, j.id, jl.line_no',
            $params
        );

        $type = (string) Database::value('SELECT type FROM accounts WHERE id = ?', [$accountId], 'asset');
        $debitNormal = ChartOfAccounts::isDebitNormal($type);
        $running = $opening;

        foreach ($lines as &$line) {
            $movement = (int) $line['debit'] - (int) $line['credit'];
            $running += $debitNormal ? $movement : -$movement;
            $line['running_balance'] = $running;
        }
        unset($line);

        return ['opening' => $opening, 'lines' => $lines, 'closing' => $running];
    }

    /** Full detail of one journal, for the drill-down view. */
    public static function journal(int $id): ?array
    {
        $journal = Database::first('SELECT * FROM journals WHERE id = ?', [$id]);
        if (!$journal) {
            return null;
        }
        $journal['lines'] = Database::all(
            'SELECT jl.*, a.code, a.name_en, a.name_ar
             FROM journal_lines jl
             JOIN accounts a ON a.id = jl.account_id
             WHERE jl.journal_id = ? ORDER BY jl.line_no',
            [$id]
        );

        return $journal;
    }

    /**
     * Confirm the whole ledger balances.
     *
     * Exposed on the Accounting screen so an owner can check the books are
     * internally consistent without waiting for the year-end audit.
     */
    public static function integrityCheck(): array
    {
        $row = Database::first(
            'SELECT COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c FROM journal_lines'
        ) ?? ['d' => 0, 'c' => 0];

        $unbalanced = Database::all(
            'SELECT j.id, j.number, j.entry_date,
                    COALESCE(SUM(jl.debit), 0) AS d, COALESCE(SUM(jl.credit), 0) AS c
             FROM journals j
             LEFT JOIN journal_lines jl ON jl.journal_id = j.id
             GROUP BY j.id, j.number, j.entry_date
             HAVING COALESCE(SUM(jl.debit), 0) <> COALESCE(SUM(jl.credit), 0)'
        );

        return [
            'total_debit' => (int) $row['d'],
            'total_credit' => (int) $row['c'],
            'balanced' => (int) $row['d'] === (int) $row['c'] && $unbalanced === [],
            'unbalanced_entries' => $unbalanced,
        ];
    }
}
