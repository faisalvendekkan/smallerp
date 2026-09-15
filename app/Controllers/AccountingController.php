<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ChartOfAccounts;
use App\Services\Ledger;
use App\Support\Money;
use App\Support\ValidationException;

/** Chart of accounts and journal entries. */
final class AccountingController extends Controller
{
    public function accounts(Request $request): Response
    {
        $type = $request->string('type');
        $search = $request->string('search');

        $where = ['1 = 1'];
        $params = [];
        if ($type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        if ($search !== '') {
            $where[] = '(code LIKE ? OR name_en LIKE ? OR name_ar LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }

        $accounts = Database::all(
            'SELECT * FROM accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY code',
            $params
        );

        // Balances come from one trial-balance pass rather than a query per
        // account, which matters once a company has a few years of history.
        $balances = [];
        foreach (Ledger::trialBalance() as $row) {
            $balances[(int) $row['id']] = (int) $row['total_debit'] - (int) $row['total_credit'];
        }

        foreach ($accounts as &$account) {
            $net = $balances[(int) $account['id']] ?? 0;
            $account['balance'] = ChartOfAccounts::isDebitNormal($account['type']) ? $net : -$net;
        }
        unset($account);

        return $this->view('accounting/accounts', [
            'title' => __('nav.accounts'),
            'accounts' => $accounts,
            'type' => $type,
            'search' => $search,
            'types' => ChartOfAccounts::TYPES,
            'integrity' => Ledger::integrityCheck(),
        ]);
    }

    public function createAccount(Request $request): Response
    {
        return $this->view('accounting/account_form', [
            'title' => __('action.new') . ' account',
            'account' => null,
            'types' => ChartOfAccounts::TYPES,
        ]);
    }

    public function editAccount(Request $request): Response
    {
        $account = $this->findOr404(
            Database::first('SELECT * FROM accounts WHERE id = ?', [$request->routeInt('id')]),
            'account'
        );

        return $this->view('accounting/account_form', [
            'title' => __('action.edit') . ' ' . $account['code'],
            'account' => $account,
            'types' => ChartOfAccounts::TYPES,
        ]);
    }

    public function storeAccount(Request $request): Response
    {
        $id = $this->saveAccount($request, null);

        return $this->redirect('/accounts/' . $id, 'Account created.');
    }

    public function updateAccount(Request $request): Response
    {
        $id = $request->routeInt('id');
        $this->saveAccount($request, $id);

        return $this->redirect('/accounts/' . $id, 'Account updated.');
    }

    private function saveAccount(Request $request, ?int $accountId): int
    {
        $code = $request->required('code', 'Account code');
        $nameEn = $request->required('name_en', 'Account name');
        $type = $request->string('type');

        if (!isset(ChartOfAccounts::TYPES[$type])) {
            throw new ValidationException('Choose an account type', 'type');
        }

        $duplicate = Database::first(
            'SELECT id FROM accounts WHERE code = ?' . ($accountId ? ' AND id <> ?' : ''),
            $accountId ? [$code, $accountId] : [$code]
        );
        if ($duplicate) {
            throw new ValidationException('An account with code ' . $code . ' already exists', 'code');
        }

        $existing = $accountId
            ? Database::first('SELECT * FROM accounts WHERE id = ?', [$accountId])
            : null;

        $data = [
            'code' => mb_substr($code, 0, 20),
            'name_en' => mb_substr($nameEn, 0, 160),
            'name_ar' => mb_substr($request->string('name_ar'), 0, 160),
            'subtype' => mb_substr($request->string('subtype'), 0, 40),
            'description' => mb_substr($request->string('description'), 0, 255),
            'is_active' => $request->bool('is_active', true) ? 1 : 0,
        ];

        if ($existing !== null) {
            // Changing the type of an account that already has postings would
            // silently move money between the P&L and the balance sheet.
            $hasPostings = (int) Database::value(
                'SELECT COUNT(*) FROM journal_lines WHERE account_id = ?',
                [$accountId],
                0
            ) > 0;

            if ($hasPostings && $existing['type'] !== $type) {
                throw new ValidationException(
                    'This account already has entries posted to it, so its type cannot be changed. '
                    . 'Create a new account instead.',
                    'type'
                );
            }
            if ($existing['system_key'] && $data['is_active'] === 0) {
                throw new ValidationException(
                    'This is a system account used by the posting engine and cannot be archived.'
                );
            }

            $data['type'] = $type;
            Database::update('accounts', $data, ['id' => $accountId]);
            AuditLog::record('account.update', 'account', $accountId, ['code' => $code]);
            ChartOfAccounts::clearCache();

            return $accountId;
        }

        $data['type'] = $type;
        $data['created_at'] = date('Y-m-d H:i:s');
        $accountId = Database::insert('accounts', $data);
        AuditLog::record('account.create', 'account', $accountId, ['code' => $code]);

        return $accountId;
    }

    /** One account's ledger, with a running balance. */
    public function ledger(Request $request): Response
    {
        $id = $request->routeInt('id');
        $account = $this->findOr404(
            Database::first('SELECT * FROM accounts WHERE id = ?', [$id]),
            'account'
        );
        $range = $this->dateRange($request);
        // An account ledger is most useful over a year, not a month.
        $from = $request->date('from') ?? date('Y-01-01');

        return $this->view('accounting/ledger', [
            'title' => $account['code'] . ' — ' . $this->name($account),
            'account' => $account,
            'ledger' => Ledger::accountLedger($id, $from, $range['to']),
            'from' => $from,
            'to' => $range['to'],
        ]);
    }

    public function journals(Request $request): Response
    {
        $page = $this->page($request);
        $perPage = 30;
        $range = $this->dateRange($request);
        $from = $request->date('from') ?? date('Y-m-01');
        $search = $request->string('search');
        $source = $request->string('source');

        $where = ['j.entry_date BETWEEN ? AND ?'];
        $params = [$from, $range['to']];

        if ($search !== '') {
            $where[] = '(j.number LIKE ? OR j.memo LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term);
        }
        if ($source !== '') {
            $where[] = 'j.source_type = ?';
            $params[] = $source;
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM journals j WHERE {$clause}", $params, 0);
        $pagination = $this->paginate($total, $page, $perPage);
        $offset = ($pagination['page'] - 1) * $perPage;

        $rows = Database::all(
            "SELECT j.*, u.name AS created_by_name
             FROM journals j LEFT JOIN users u ON u.id = j.created_by
             WHERE {$clause} ORDER BY j.entry_date DESC, j.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return $this->view('accounting/journals', [
            'title' => __('nav.journals'),
            'rows' => $rows,
            'pagination' => $pagination,
            'from' => $from,
            'to' => $range['to'],
            'search' => $search,
            'source' => $source,
            'sources' => array_column(
                Database::all('SELECT DISTINCT source_type FROM journals ORDER BY source_type'),
                'source_type'
            ),
        ]);
    }

    public function createJournal(Request $request): Response
    {
        return $this->view('accounting/journal_form', [
            'title' => __('action.new') . ' ' . __('nav.journals'),
            'accounts' => Database::all('SELECT * FROM accounts WHERE is_active = 1 ORDER BY code'),
        ]);
    }

    public function storeJournal(Request $request): Response
    {
        $date = $request->date('entry_date') ?? date('Y-m-d');
        $memo = $request->required('memo', 'Description');

        $lines = [];
        foreach ($request->rows('lines') as $row) {
            $accountId = (int) ($row['account_id'] ?? 0);
            $debit = trim((string) ($row['debit'] ?? ''));
            $credit = trim((string) ($row['credit'] ?? ''));

            if ($accountId === 0 && $debit === '' && $credit === '') {
                continue;
            }

            $lines[] = [
                'account_id' => $accountId,
                'debit' => $debit === '' ? 0 : Money::toDirhams($debit, 'debit'),
                'credit' => $credit === '' ? 0 : Money::toDirhams($credit, 'credit'),
                'memo' => (string) ($row['memo'] ?? ''),
            ];
        }

        $journalId = Ledger::post($date, $lines, $memo, 'manual');

        return $this->redirect('/journals/' . $journalId, 'Journal entry posted.');
    }

    public function showJournal(Request $request): Response
    {
        $journal = $this->findOr404(Ledger::journal($request->routeInt('id')), 'journal entry');

        return $this->view('accounting/journal_show', [
            'title' => $journal['number'],
            'journal' => $journal,
            'reversal' => Database::first(
                'SELECT id, number FROM journals WHERE reverses_id = ?',
                [(int) $journal['id']]
            ),
            'reverses' => $journal['reverses_id']
                ? Database::first('SELECT id, number FROM journals WHERE id = ?', [(int) $journal['reverses_id']])
                : null,
        ]);
    }

    public function reverseJournal(Request $request): Response
    {
        $id = $request->routeInt('id');
        $journal = $this->findOr404(Ledger::journal($id), 'journal entry');

        // Reversing a document's journal directly would leave the document
        // saying "posted" while its accounting had been undone.
        if ($journal['source_type'] !== 'manual') {
            throw new ValidationException(
                'This entry was created by a ' . str_replace('_', ' ', (string) $journal['source_type'])
                . '. Void that document instead, which reverses the entry for you.'
            );
        }

        $newId = Ledger::reverse($id, $request->date('entry_date') ?? date('Y-m-d'), $request->string('memo'));

        return $this->redirect('/journals/' . $newId, 'Entry reversed.');
    }
}
