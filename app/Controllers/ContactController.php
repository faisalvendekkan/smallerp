<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Numbering;
use App\Services\Purchases;
use App\Services\Reports;
use App\Services\Sales;
use App\Support\Money;
use App\Support\Qatar;
use App\Support\ValidationException;

/** Customers and suppliers. */
final class ContactController extends Controller
{
    public function customers(Request $request): Response
    {
        return $this->list($request, 'customer', __('nav.customers'));
    }

    public function suppliers(Request $request): Response
    {
        return $this->list($request, 'supplier', __('nav.suppliers'));
    }

    private function list(Request $request, string $kind, string $title): Response
    {
        $page = $this->page($request);
        $perPage = 25;
        $search = $request->string('search');
        $showArchived = $request->bool('archived');

        // `both` contacts appear under customers and suppliers alike.
        $where = ['(kind = ? OR kind = ?)'];
        $params = [$kind, 'both'];

        if (!$showArchived) {
            $where[] = 'is_active = 1';
        }
        if ($search !== '') {
            $where[] = '(name_en LIKE ? OR name_ar LIKE ? OR code LIKE ? OR cr_number LIKE ? OR phone LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term, $term);
        }

        $clause = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM contacts WHERE {$clause}", $params, 0);
        $pagination = $this->paginate($total, $page, $perPage);
        $offset = ($pagination['page'] - 1) * $perPage;

        $rows = Database::all(
            "SELECT * FROM contacts WHERE {$clause} ORDER BY name_en LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // Outstanding balance per contact, so the list is useful for chasing.
        foreach ($rows as &$row) {
            $row['balance'] = $this->outstandingFor((int) $row['id'], $kind);
        }
        unset($row);

        return $this->view('contacts/index', [
            'title' => $title,
            'kind' => $kind,
            'rows' => $rows,
            'pagination' => $pagination,
            'search' => $search,
            'archived' => $showArchived,
        ]);
    }

    private function outstandingFor(int $contactId, string $kind): int
    {
        $table = $kind === 'supplier' ? 'purchase_bills' : 'sales_invoices';

        return (int) Database::value(
            "SELECT COALESCE(SUM(total - amount_paid), 0) FROM {$table}
             WHERE contact_id = ? AND status IN ('posted', 'partial')",
            [$contactId],
            0
        );
    }

    public function create(Request $request): Response
    {
        return $this->view('contacts/form', [
            'title' => __('action.new') . ' — ' . __('nav.customers'),
            'contact' => null,
            'kind' => $request->string('kind', 'customer'),
        ]);
    }

    public function edit(Request $request): Response
    {
        $contact = $this->findOr404(
            Database::first('SELECT * FROM contacts WHERE id = ?', [$request->routeInt('id')]),
            'contact'
        );

        return $this->view('contacts/form', [
            'title' => __('action.edit') . ' — ' . $this->name($contact),
            'contact' => $contact,
            'kind' => $contact['kind'],
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->save($request, null);

        return $this->redirect('/contacts/' . $id, 'Contact saved.');
    }

    public function update(Request $request): Response
    {
        $id = $request->routeInt('id');
        $this->save($request, $id);

        return $this->redirect('/contacts/' . $id, 'Contact updated.');
    }

    private function save(Request $request, ?int $contactId): int
    {
        $nameEn = $request->required('name_en', 'Name');
        $kind = $request->string('kind', 'customer');
        if (!in_array($kind, ['customer', 'supplier', 'both'], true)) {
            throw new ValidationException('Choose whether this is a customer, a supplier or both', 'kind');
        }

        // Qatari identifiers are validated when supplied but never mandatory:
        // a cash customer may have neither a CR nor a phone number on file.
        $crNumber = $request->string('cr_number');
        if ($crNumber !== '') {
            $crNumber = Qatar::validateCrNumber($crNumber);
        }

        $phone = $request->string('phone');
        if ($phone !== '' && str_starts_with($phone, '+974')) {
            $phone = Qatar::validatePhone($phone);
        }
        $mobile = $request->string('mobile');
        if ($mobile !== '' && str_starts_with($mobile, '+974')) {
            $mobile = Qatar::validatePhone($mobile);
        }

        $email = $request->string('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('That email address is not valid', 'email');
        }

        $data = [
            'kind' => $kind,
            'name_en' => mb_substr($nameEn, 0, 160),
            'name_ar' => mb_substr($request->string('name_ar'), 0, 160),
            'cr_number' => $crNumber,
            'tax_id' => mb_substr($request->string('tax_id'), 0, 30),
            'contact_person' => mb_substr($request->string('contact_person'), 0, 120),
            'email' => $email,
            'phone' => $phone,
            'mobile' => $mobile,
            'address' => mb_substr($request->string('address'), 0, 255),
            'city' => mb_substr($request->string('city', 'Doha'), 0, 80),
            'po_box' => mb_substr($request->string('po_box'), 0, 20),
            'country' => mb_substr($request->string('country', 'Qatar'), 0, 60),
            'payment_terms_days' => max(0, $request->int('payment_terms_days', 30)),
            'credit_limit' => $request->money('credit_limit'),
            'notes' => $request->string('notes') ?: null,
            'is_active' => $request->bool('is_active', true) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($contactId === null) {
            $data['code'] = Numbering::next($kind === 'supplier' ? 'supplier' : 'contact');
            $data['opening_balance'] = $request->money('opening_balance');
            $data['created_at'] = date('Y-m-d H:i:s');
            $contactId = Database::insert('contacts', $data);
            AuditLog::record('contact.create', 'contact', $contactId, ['name' => $nameEn]);
        } else {
            Database::update('contacts', $data, ['id' => $contactId]);
            AuditLog::record('contact.update', 'contact', $contactId);
        }

        return $contactId;
    }

    public function show(Request $request): Response
    {
        $id = $request->routeInt('id');
        $contact = $this->findOr404(
            Database::first('SELECT * FROM contacts WHERE id = ?', [$id]),
            'contact'
        );

        $isSupplier = in_array($contact['kind'], ['supplier', 'both'], true);
        $isCustomer = in_array($contact['kind'], ['customer', 'both'], true);

        return $this->view('contacts/show', [
            'title' => $this->name($contact),
            'contact' => $contact,
            'invoices' => $isCustomer ? Sales::listInvoices(['contact_id' => $id], 1, 10)['rows'] : [],
            'bills' => $isSupplier ? Purchases::listBills(['contact_id' => $id], 1, 10)['rows'] : [],
            'payments' => Database::all(
                "SELECT * FROM payments WHERE contact_id = ? AND status = 'posted'
                 ORDER BY payment_date DESC LIMIT 10",
                [$id]
            ),
            'receivable' => $isCustomer ? $this->outstandingFor($id, 'customer') : 0,
            'payable' => $isSupplier ? $this->outstandingFor($id, 'supplier') : 0,
        ]);
    }

    public function archive(Request $request): Response
    {
        $id = $request->routeInt('id');
        $contact = $this->findOr404(Database::first('SELECT * FROM contacts WHERE id = ?', [$id]), 'contact');

        // Contacts are never deleted: their documents are part of the books.
        $active = (int) $contact['is_active'] === 1 ? 0 : 1;
        Database::update('contacts', ['is_active' => $active], ['id' => $id]);
        AuditLog::record($active ? 'contact.restore' : 'contact.archive', 'contact', $id);

        return $this->redirect(
            '/contacts/' . $id,
            $active ? 'Contact restored.' : 'Contact archived. Its documents are unaffected.'
        );
    }

    public function statement(Request $request): Response
    {
        $id = $request->routeInt('id');
        $range = $this->dateRange($request);
        $statement = Reports::contactStatement($id, $range['from'], $range['to']);

        if ($statement === []) {
            return $this->redirect('/customers', 'That contact does not exist.', 'error');
        }

        return $this->printView('print/statement', [
            'title' => 'Statement — ' . $this->name($statement['contact']),
            'statement' => $statement,
            'backUrl' => url('/contacts/' . $id),
        ]);
    }

    public function export(Request $request): Response
    {
        $kind = $request->string('kind', 'customer');
        $rows = Database::all(
            'SELECT * FROM contacts WHERE (kind = ? OR kind = ?) ORDER BY name_en',
            [$kind, 'both']
        );

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['code'],
                $row['name_en'],
                $row['name_ar'],
                $row['cr_number'],
                $row['contact_person'],
                $row['phone'],
                $row['email'],
                $row['address'],
                $row['payment_terms_days'],
                Money::toDecimalString((int) $row['credit_limit']),
                Money::toDecimalString($this->outstandingFor((int) $row['id'], $kind)),
                (int) $row['is_active'] === 1 ? 'Active' : 'Archived',
            ];
        }

        return $this->csv(
            $kind . 's-' . date('Y-m-d') . '.csv',
            ['Code', 'Name (EN)', 'Name (AR)', 'CR number', 'Contact', 'Phone', 'Email',
             'Address', 'Terms (days)', 'Credit limit', 'Outstanding', 'Status'],
            $data
        );
    }
}
