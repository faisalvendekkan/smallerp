<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Payments;
use App\Services\Purchases;
use App\Services\Sales;
use App\Services\Settings;
use App\Support\ValidationException;

/** Receipts from customers and payments to suppliers. */
final class PaymentController extends Controller
{
    public function receipts(Request $request): Response
    {
        return $this->list($request, Payments::IN, __('nav.receipts'));
    }

    public function payments(Request $request): Response
    {
        return $this->list($request, Payments::OUT, __('nav.payments'));
    }

    private function list(Request $request, string $direction, string $title): Response
    {
        $page = $this->page($request);
        $filters = [
            'direction' => $direction,
            'contact_id' => $request->int('contact_id'),
            'method' => $request->string('method'),
            'from' => $request->date('from'),
            'to' => $request->date('to'),
            'search' => $request->string('search'),
        ];

        $result = Payments::listPayments($filters, $page);
        $total = 0;
        foreach (Payments::listPayments($filters, 1, 100000)['rows'] as $row) {
            if ($row['status'] !== 'void') {
                $total += (int) $row['amount'];
            }
        }

        return $this->view('payments/index', [
            'title' => $title,
            'direction' => $direction,
            'rows' => $result['rows'],
            'pagination' => $this->paginate($result['total'], $page),
            'filters' => $filters,
            'contacts' => $this->contacts($direction),
            'total' => $total,
        ]);
    }

    public function createReceipt(Request $request): Response
    {
        return $this->view('payments/form', $this->formData($request, Payments::IN));
    }

    public function createPayment(Request $request): Response
    {
        return $this->view('payments/form', $this->formData($request, Payments::OUT));
    }

    private function formData(Request $request, string $direction): array
    {
        $contactId = $request->int('contact_id');
        $documents = [];
        if ($contactId > 0) {
            $documents = $direction === Payments::IN
                ? Sales::openInvoices($contactId)
                : Purchases::openBills($contactId);
        }

        // Landing here from an invoice pre-fills the customer and the amount.
        $docId = $request->int('doc_id');
        $prefill = 0;
        if ($docId > 0) {
            foreach ($documents as $document) {
                if ((int) $document['id'] === $docId) {
                    $prefill = (int) $document['balance_due'];
                }
            }
        }

        return [
            'title' => $direction === Payments::IN
                ? __('action.new') . ' ' . __('nav.receipts')
                : __('action.new') . ' ' . __('nav.payments'),
            'direction' => $direction,
            'contacts' => $this->contacts($direction),
            'contactId' => $contactId,
            'documents' => $documents,
            'accounts' => Payments::cashAccounts(),
            'methods' => Payments::METHODS,
            'docId' => $docId,
            'prefillAmount' => $prefill,
        ];
    }

    public function store(Request $request): Response
    {
        $direction = $request->string('direction') === Payments::OUT ? Payments::OUT : Payments::IN;

        $allocations = [];
        foreach ($request->rows('allocations') as $row) {
            $amount = trim((string) ($row['amount'] ?? ''));
            if ($amount === '' || (float) $amount == 0.0) {
                continue;
            }
            $allocations[] = ['doc_id' => (int) ($row['doc_id'] ?? 0), 'amount' => $amount];
        }

        $paymentId = Payments::record([
            'direction' => $direction,
            'contact_id' => $request->int('contact_id'),
            'payment_date' => $request->date('payment_date') ?? date('Y-m-d'),
            'method' => $request->string('method', 'bank'),
            'account_id' => $request->int('account_id'),
            'amount' => $request->money('amount'),
            'reference' => $request->string('reference'),
            'cheque_number' => $request->string('cheque_number'),
            'cheque_date' => $request->date('cheque_date'),
            'bank_name' => $request->string('bank_name'),
            'notes' => $request->string('notes') ?: null,
        ], $allocations);

        return $this->redirect(
            '/payments/' . $paymentId,
            $direction === Payments::IN ? 'Receipt recorded.' : 'Payment recorded.'
        );
    }

    public function show(Request $request): Response
    {
        $payment = $this->findOr404(Payments::find($request->routeInt('id')), 'payment');

        return $this->view('payments/show', [
            'title' => $payment['number'],
            'payment' => $payment,
            'journal' => $payment['journal_id']
                ? \App\Services\Ledger::journal((int) $payment['journal_id'])
                : null,
        ]);
    }

    public function void(Request $request): Response
    {
        $id = $request->routeInt('id');
        Payments::void($id, $request->string('void_reason'));

        return $this->redirect('/payments/' . $id, 'Payment voided and its journal reversed.');
    }

    public function printReceipt(Request $request): Response
    {
        $payment = $this->findOr404(Payments::find($request->routeInt('id')), 'payment');

        return $this->printView('print/receipt', [
            'title' => $payment['number'],
            'payment' => $payment,
            'settings' => Settings::all(),
            'backUrl' => url('/payments/' . $payment['id']),
        ]);
    }

    /**
     * Open documents for a contact, fetched by the payment form when the user
     * picks a customer or supplier.
     */
    public function openDocuments(Request $request): Response
    {
        $contactId = $request->int('contact_id');
        $direction = $request->string('direction', Payments::IN);
        if ($contactId <= 0) {
            throw new ValidationException('Choose a contact first', 'contact_id');
        }

        $documents = $direction === Payments::OUT
            ? Purchases::openBills($contactId)
            : Sales::openInvoices($contactId);

        return Response::json(['documents' => $documents]);
    }

    private function contacts(string $direction): array
    {
        $kinds = $direction === Payments::IN ? ['customer', 'both'] : ['supplier', 'both'];

        return Database::all(
            "SELECT id, code, name_en, name_ar FROM contacts
             WHERE kind IN (?, ?) AND is_active = 1 ORDER BY name_en",
            $kinds
        );
    }
}
