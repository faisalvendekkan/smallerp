<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuditLog;
use App\Core\Auth;
use App\Core\Database;
use App\Support\Money;
use App\Support\ValidationException;

/**
 * Receipts from customers and payments to suppliers.
 *
 * A payment can settle several documents at once, which is how Qatari
 * customers commonly pay: one cheque covering four invoices. Anything not
 * allocated sits as an advance on the customer's account rather than
 * disappearing.
 */
final class Payments
{
    public const IN = 'in';
    public const OUT = 'out';

    public const METHODS = [
        'cash' => ['name_en' => 'Cash', 'name_ar' => 'نقداً'],
        'bank' => ['name_en' => 'Bank transfer', 'name_ar' => 'تحويل بنكي'],
        'cheque' => ['name_en' => 'Cheque', 'name_ar' => 'شيك'],
        'card' => ['name_en' => 'Card', 'name_ar' => 'بطاقة'],
        'online' => ['name_en' => 'Online', 'name_ar' => 'إلكتروني'],
    ];

    /**
     * Record a payment and post it.
     *
     * Money in:  Dr Cash/Bank, Cr Accounts Receivable (or Customer Advances).
     * Money out: Dr Accounts Payable, Cr Cash/Bank.
     *
     * @param array<int,array{doc_id:int,amount:mixed}> $allocations
     */
    public static function record(array $data, array $allocations = []): int
    {
        $direction = ($data['direction'] ?? self::IN) === self::OUT ? self::OUT : self::IN;
        $amount = (int) ($data['amount'] ?? 0);
        $contactId = (int) ($data['contact_id'] ?? 0) ?: null;
        $accountId = (int) ($data['account_id'] ?? 0);
        $date = (string) ($data['payment_date'] ?? date('Y-m-d'));
        $method = (string) ($data['method'] ?? 'bank');

        if ($amount <= 0) {
            throw new ValidationException('The payment amount must be more than zero', 'amount');
        }
        if ($accountId <= 0) {
            throw new ValidationException('Choose the cash or bank account the money moved through', 'account_id');
        }
        if (!isset(self::METHODS[$method])) {
            throw new ValidationException('Choose a valid payment method', 'method');
        }
        if ($method === 'cheque' && trim((string) ($data['cheque_number'] ?? '')) === '') {
            throw new ValidationException('Enter the cheque number', 'cheque_number');
        }

        $account = Database::first('SELECT * FROM accounts WHERE id = ?', [$accountId]);
        if (!$account || !in_array($account['subtype'], ['cash', 'bank'], true)) {
            throw new ValidationException('That is not a cash or bank account', 'account_id');
        }

        $docType = $direction === self::IN ? 'sales_invoice' : 'purchase_bill';
        $clean = self::validateAllocations($allocations, $docType, $contactId, $amount);
        $allocatedTotal = array_sum(array_column($clean, 'amount'));

        return Database::transaction(static function () use ($data, $direction, $amount, $contactId, $accountId, $date, $method, $clean, $allocatedTotal, $docType): int {
            $docLabel = $direction === self::IN ? 'receipt' : 'payment';
            $number = Numbering::next($direction === self::IN ? 'receipt' : 'payment', $date);

            $paymentId = Database::insert('payments', [
                'number' => $number,
                'direction' => $direction,
                'contact_id' => $contactId,
                'payment_date' => $date,
                'method' => $method,
                'account_id' => $accountId,
                'amount' => $amount,
                'allocated' => $allocatedTotal,
                'reference' => mb_substr((string) ($data['reference'] ?? ''), 0, 80),
                'cheque_number' => mb_substr((string) ($data['cheque_number'] ?? ''), 0, 40),
                'cheque_date' => $data['cheque_date'] ?? null,
                'bank_name' => mb_substr((string) ($data['bank_name'] ?? ''), 0, 120),
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($clean as $allocation) {
                Database::insert('payment_allocations', [
                    'payment_id' => $paymentId,
                    'doc_type' => $docType,
                    'doc_id' => $allocation['doc_id'],
                    'amount' => $allocation['amount'],
                ]);
            }

            $unallocated = $amount - $allocatedTotal;
            $lines = [];

            if ($direction === self::IN) {
                $lines[] = [
                    'account_id' => $accountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Receipt ' . $number,
                    'contact_id' => $contactId,
                ];
                if ($allocatedTotal > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('accounts_receivable'),
                        'debit' => 0,
                        'credit' => $allocatedTotal,
                        'memo' => 'Receipt ' . $number,
                        'contact_id' => $contactId,
                    ];
                }
                if ($unallocated > 0) {
                    // Money received with nothing to match it against is the
                    // customer's, not ours, until it is applied.
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('customer_advances'),
                        'debit' => 0,
                        'credit' => $unallocated,
                        'memo' => 'Advance on ' . $number,
                        'contact_id' => $contactId,
                    ];
                }
            } else {
                if ($allocatedTotal > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('accounts_payable'),
                        'debit' => $allocatedTotal,
                        'credit' => 0,
                        'memo' => 'Payment ' . $number,
                        'contact_id' => $contactId,
                    ];
                }
                if ($unallocated > 0) {
                    $lines[] = [
                        'account_id' => ChartOfAccounts::id('accounts_payable'),
                        'debit' => $unallocated,
                        'credit' => 0,
                        'memo' => 'Payment on account — ' . $number,
                        'contact_id' => $contactId,
                    ];
                }
                $lines[] = [
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Payment ' . $number,
                    'contact_id' => $contactId,
                ];
            }

            $journalId = Ledger::post(
                $date,
                $lines,
                ucfirst($docLabel) . ' ' . $number,
                $direction === self::IN ? 'receipt' : 'payment',
                $paymentId
            );

            Database::update('payments', ['journal_id' => $journalId], ['id' => $paymentId]);

            foreach ($clean as $allocation) {
                if ($docType === 'sales_invoice') {
                    Sales::refreshPaymentStatus($allocation['doc_id']);
                } else {
                    Purchases::refreshPaymentStatus($allocation['doc_id']);
                }
            }

            AuditLog::record('payment.record', 'payment', $paymentId, [
                'number' => $number,
                'direction' => $direction,
                'amount' => Money::toDecimalString($amount),
            ]);

            return $paymentId;
        });
    }

    /**
     * Check every allocation refers to a real open document belonging to the
     * same contact, and that the sum does not exceed the payment.
     *
     * @return array<int,array{doc_id:int,amount:int}>
     */
    private static function validateAllocations(
        array $allocations,
        string $docType,
        ?int $contactId,
        int $paymentAmount
    ): array {
        $clean = [];
        $seen = [];
        $table = $docType === 'sales_invoice' ? 'sales_invoices' : 'purchase_bills';

        foreach ($allocations as $allocation) {
            $docId = (int) ($allocation['doc_id'] ?? 0);
            $amount = is_int($allocation['amount'] ?? null)
                ? (int) $allocation['amount']
                : Money::toDirhams($allocation['amount'] ?? 0, 'allocation');

            if ($docId <= 0 || $amount === 0) {
                continue;
            }
            if ($amount < 0) {
                throw new ValidationException('An allocation cannot be negative');
            }
            if (isset($seen[$docId])) {
                throw new ValidationException('The same document is allocated twice on this payment');
            }
            $seen[$docId] = true;

            $doc = Database::first("SELECT * FROM {$table} WHERE id = ?", [$docId]);
            if (!$doc) {
                throw new ValidationException('A document on this payment no longer exists');
            }
            if ($doc['status'] === 'void') {
                throw new ValidationException($doc['number'] . ' has been voided and cannot be paid');
            }
            if ($doc['status'] === 'draft') {
                throw new ValidationException($doc['number'] . ' is still a draft — post it before paying it');
            }
            if ($contactId !== null && (int) $doc['contact_id'] !== $contactId) {
                throw new ValidationException($doc['number'] . ' belongs to a different contact');
            }

            $outstanding = (int) $doc['total'] - (int) $doc['amount_paid'];
            if ($amount > $outstanding) {
                throw new ValidationException(sprintf(
                    'Allocating %s to %s, but only %s is outstanding',
                    Money::format($amount, true),
                    $doc['number'],
                    Money::format($outstanding, true)
                ));
            }

            $clean[] = ['doc_id' => $docId, 'amount' => $amount];
        }

        $total = array_sum(array_column($clean, 'amount'));
        if ($total > $paymentAmount) {
            throw new ValidationException(sprintf(
                'The allocations come to %s, more than the payment of %s',
                Money::format($total, true),
                Money::format($paymentAmount, true)
            ));
        }

        return $clean;
    }

    /** Void a payment: reverse its journal and release the documents it paid. */
    public static function void(int $paymentId, string $reason = ''): void
    {
        $payment = Database::first('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$payment) {
            throw new ValidationException('That payment does not exist');
        }
        if ($payment['status'] === 'void') {
            throw new ValidationException('That payment is already void');
        }

        Database::transaction(static function () use ($payment, $paymentId, $reason): void {
            if ($payment['journal_id']) {
                Ledger::reverse(
                    (int) $payment['journal_id'],
                    date('Y-m-d'),
                    'Void of ' . $payment['number'] . ($reason !== '' ? ' — ' . $reason : '')
                );
            }

            $allocations = Database::all(
                'SELECT * FROM payment_allocations WHERE payment_id = ?',
                [$paymentId]
            );

            Database::update('payments', [
                'status' => 'void',
                'voided_at' => date('Y-m-d H:i:s'),
            ], ['id' => $paymentId]);

            foreach ($allocations as $allocation) {
                if ($allocation['doc_type'] === 'sales_invoice') {
                    Sales::refreshPaymentStatus((int) $allocation['doc_id']);
                } else {
                    Purchases::refreshPaymentStatus((int) $allocation['doc_id']);
                }
            }

            AuditLog::record('payment.void', 'payment', $paymentId, [
                'number' => $payment['number'],
                'reason' => $reason,
            ]);
        });
    }

    public static function find(int $paymentId): ?array
    {
        $payment = Database::first(
            'SELECT p.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar,
                    a.code AS account_code, a.name_en AS account_name_en
             FROM payments p
             LEFT JOIN contacts c ON c.id = p.contact_id
             JOIN accounts a ON a.id = p.account_id
             WHERE p.id = ?',
            [$paymentId]
        );
        if (!$payment) {
            return null;
        }

        $payment['allocations'] = Database::all(
            "SELECT pa.*,
                    COALESCE(si.number, pb.number) AS doc_number,
                    COALESCE(si.issue_date, pb.issue_date) AS doc_date,
                    COALESCE(si.total, pb.total) AS doc_total
             FROM payment_allocations pa
             LEFT JOIN sales_invoices si ON pa.doc_type = 'sales_invoice' AND si.id = pa.doc_id
             LEFT JOIN purchase_bills pb ON pa.doc_type = 'purchase_bill' AND pb.id = pa.doc_id
             WHERE pa.payment_id = ?",
            [$paymentId]
        );
        $payment['unallocated'] = (int) $payment['amount'] - (int) $payment['allocated'];

        return $payment;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int} */
    public static function listPayments(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['direction'])) {
            $where[] = 'p.direction = ?';
            $params[] = $filters['direction'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 'p.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'p.method = ?';
            $params[] = $filters['method'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'p.payment_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'p.payment_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.number LIKE ? OR p.reference LIKE ? OR p.cheque_number LIKE ? OR c.name_en LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM payments p LEFT JOIN contacts c ON c.id = p.contact_id WHERE {$clause}",
            $params,
            0
        );

        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            "SELECT p.*, c.name_en AS contact_name_en, c.name_ar AS contact_name_ar, a.name_en AS account_name_en
             FROM payments p
             LEFT JOIN contacts c ON c.id = p.contact_id
             JOIN accounts a ON a.id = p.account_id
             WHERE {$clause}
             ORDER BY p.payment_date DESC, p.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** Cash and bank accounts, for the payment form. */
    public static function cashAccounts(): array
    {
        return Database::all(
            "SELECT * FROM accounts WHERE subtype IN ('cash', 'bank') AND is_active = 1 ORDER BY code"
        );
    }

    /** Cheques dated in the future, which an SME needs to watch for funding. */
    public static function postDatedCheques(): array
    {
        return Database::all(
            "SELECT p.*, c.name_en AS contact_name_en
             FROM payments p
             LEFT JOIN contacts c ON c.id = p.contact_id
             WHERE p.method = 'cheque' AND p.status = 'posted'
               AND p.cheque_date IS NOT NULL AND p.cheque_date > ?
             ORDER BY p.cheque_date",
            [date('Y-m-d')]
        );
    }
}
