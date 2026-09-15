-- SmallERP schema.
--
-- Written to run unchanged on SQLite and MySQL 8, which is why it avoids
-- driver-specific types. The migration runner rewrites the few tokens that do
-- differ (AUTOINCREMENT vs AUTO_INCREMENT) -- see database/Migrator.php.
--
-- Money is stored as INTEGER dirhams (1 QAR = 100 dirhams). Nothing monetary
-- is ever a float: a ledger that drifts by a fraction of a dirham is a ledger
-- an auditor will not sign off.
--
-- Dates are TEXT in YYYY-MM-DD and timestamps TEXT in YYYY-MM-DD HH:MM:SS,
-- which sorts and compares correctly on both drivers.

-- ---------------------------------------------------------------------------
-- Users, settings and audit
-- ---------------------------------------------------------------------------

CREATE TABLE users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        VARCHAR(60)  NOT NULL UNIQUE,
    email           VARCHAR(160) NOT NULL DEFAULT '',
    name            VARCHAR(160) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(20)  NOT NULL DEFAULT 'viewer',
    locale          VARCHAR(5)   NOT NULL DEFAULT 'en',
    is_active       INTEGER      NOT NULL DEFAULT 1,
    failed_attempts INTEGER      NOT NULL DEFAULT 0,
    locked_until    VARCHAR(20)  NULL,
    last_login_at   VARCHAR(20)  NULL,
    last_login_ip   VARCHAR(45)  NULL,
    created_at      VARCHAR(20)  NOT NULL,
    updated_at      VARCHAR(20)  NULL
);

CREATE TABLE settings (
    setting_key   VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    VARCHAR(20) NULL
);

CREATE TABLE audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NULL,
    action      VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NOT NULL DEFAULT '',
    entity_id   INTEGER NULL,
    details     TEXT NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  VARCHAR(20) NOT NULL
);
CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id);
CREATE INDEX idx_audit_created ON audit_log (created_at);

-- Document numbering. Held in its own table so concurrent invoice creation
-- cannot hand two users the same number.
CREATE TABLE number_sequences (
    doc_type   VARCHAR(30) NOT NULL,
    period     VARCHAR(10) NOT NULL DEFAULT '',
    prefix     VARCHAR(20) NOT NULL DEFAULT '',
    next_value INTEGER NOT NULL DEFAULT 1,
    padding    INTEGER NOT NULL DEFAULT 4,
    PRIMARY KEY (doc_type, period)
);

-- ---------------------------------------------------------------------------
-- Chart of accounts and the general ledger
-- ---------------------------------------------------------------------------

CREATE TABLE accounts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    code         VARCHAR(20)  NOT NULL UNIQUE,
    name_en      VARCHAR(160) NOT NULL,
    name_ar      VARCHAR(160) NOT NULL DEFAULT '',
    type         VARCHAR(20)  NOT NULL,  -- asset | liability | equity | income | expense
    subtype      VARCHAR(40)  NOT NULL DEFAULT '',
    parent_id    INTEGER NULL REFERENCES accounts (id),
    is_active    INTEGER NOT NULL DEFAULT 1,
    -- System accounts are referenced by the posting engine and cannot be
    -- deleted, only renamed.
    system_key   VARCHAR(40) NULL UNIQUE,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    created_at   VARCHAR(20) NOT NULL
);
CREATE INDEX idx_accounts_type ON accounts (type);

CREATE TABLE journals (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    number       VARCHAR(30) NOT NULL UNIQUE,
    entry_date   VARCHAR(10) NOT NULL,
    memo         VARCHAR(255) NOT NULL DEFAULT '',
    source_type  VARCHAR(30) NOT NULL DEFAULT 'manual',
    source_id    INTEGER NULL,
    is_posted    INTEGER NOT NULL DEFAULT 1,
    is_reversal  INTEGER NOT NULL DEFAULT 0,
    reverses_id  INTEGER NULL REFERENCES journals (id),
    total_debit  INTEGER NOT NULL DEFAULT 0,
    created_by   INTEGER NULL REFERENCES users (id),
    created_at   VARCHAR(20) NOT NULL
);
CREATE INDEX idx_journals_date ON journals (entry_date);
CREATE INDEX idx_journals_source ON journals (source_type, source_id);

CREATE TABLE journal_lines (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    journal_id  INTEGER NOT NULL REFERENCES journals (id) ON DELETE CASCADE,
    account_id  INTEGER NOT NULL REFERENCES accounts (id),
    debit       INTEGER NOT NULL DEFAULT 0,
    credit      INTEGER NOT NULL DEFAULT 0,
    memo        VARCHAR(255) NOT NULL DEFAULT '',
    contact_id  INTEGER NULL,
    line_no     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_jl_journal ON journal_lines (journal_id);
CREATE INDEX idx_jl_account ON journal_lines (account_id);

-- ---------------------------------------------------------------------------
-- Contacts: customers and suppliers
-- ---------------------------------------------------------------------------

CREATE TABLE contacts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    code               VARCHAR(20)  NOT NULL UNIQUE,
    kind               VARCHAR(10)  NOT NULL DEFAULT 'customer', -- customer | supplier | both
    name_en            VARCHAR(160) NOT NULL,
    name_ar            VARCHAR(160) NOT NULL DEFAULT '',
    cr_number          VARCHAR(20)  NOT NULL DEFAULT '',
    tax_id             VARCHAR(30)  NOT NULL DEFAULT '',
    contact_person     VARCHAR(120) NOT NULL DEFAULT '',
    email              VARCHAR(160) NOT NULL DEFAULT '',
    phone              VARCHAR(30)  NOT NULL DEFAULT '',
    mobile             VARCHAR(30)  NOT NULL DEFAULT '',
    address            VARCHAR(255) NOT NULL DEFAULT '',
    city               VARCHAR(80)  NOT NULL DEFAULT 'Doha',
    po_box             VARCHAR(20)  NOT NULL DEFAULT '',
    country            VARCHAR(60)  NOT NULL DEFAULT 'Qatar',
    payment_terms_days INTEGER      NOT NULL DEFAULT 30,
    credit_limit       INTEGER      NOT NULL DEFAULT 0,
    opening_balance    INTEGER      NOT NULL DEFAULT 0,
    notes              TEXT NULL,
    is_active          INTEGER      NOT NULL DEFAULT 1,
    created_at         VARCHAR(20)  NOT NULL,
    updated_at         VARCHAR(20)  NULL
);
CREATE INDEX idx_contacts_kind ON contacts (kind, is_active);
CREATE INDEX idx_contacts_name ON contacts (name_en);

-- ---------------------------------------------------------------------------
-- Items, warehouses and stock
-- ---------------------------------------------------------------------------

CREATE TABLE warehouses (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    code      VARCHAR(20)  NOT NULL UNIQUE,
    name_en   VARCHAR(120) NOT NULL,
    name_ar   VARCHAR(120) NOT NULL DEFAULT '',
    address   VARCHAR(255) NOT NULL DEFAULT '',
    is_default INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE items (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    sku            VARCHAR(40)  NOT NULL UNIQUE,
    barcode        VARCHAR(40)  NOT NULL DEFAULT '',
    name_en        VARCHAR(160) NOT NULL,
    name_ar        VARCHAR(160) NOT NULL DEFAULT '',
    description    VARCHAR(255) NOT NULL DEFAULT '',
    kind           VARCHAR(10)  NOT NULL DEFAULT 'goods', -- goods | service
    uom            VARCHAR(20)  NOT NULL DEFAULT 'PCS',
    sale_price     INTEGER      NOT NULL DEFAULT 0,
    cost_price     INTEGER      NOT NULL DEFAULT 0,
    tax_rate       REAL         NOT NULL DEFAULT 0,
    track_stock    INTEGER      NOT NULL DEFAULT 1,
    reorder_level  REAL         NOT NULL DEFAULT 0,
    opening_qty    REAL         NOT NULL DEFAULT 0,
    income_account_id    INTEGER NULL REFERENCES accounts (id),
    expense_account_id   INTEGER NULL REFERENCES accounts (id),
    inventory_account_id INTEGER NULL REFERENCES accounts (id),
    is_active      INTEGER NOT NULL DEFAULT 1,
    created_at     VARCHAR(20) NOT NULL,
    updated_at     VARCHAR(20) NULL
);
CREATE INDEX idx_items_active ON items (is_active);

-- Every movement of stock, in or out, with the cost it moved at. Quantity is
-- signed: positive in, negative out.
CREATE TABLE stock_moves (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    move_date     VARCHAR(10) NOT NULL,
    item_id       INTEGER NOT NULL REFERENCES items (id),
    warehouse_id  INTEGER NOT NULL REFERENCES warehouses (id),
    quantity      REAL    NOT NULL,
    unit_cost     INTEGER NOT NULL DEFAULT 0,
    source_type   VARCHAR(30) NOT NULL DEFAULT 'adjustment',
    source_id     INTEGER NULL,
    note          VARCHAR(255) NOT NULL DEFAULT '',
    created_by    INTEGER NULL REFERENCES users (id),
    created_at    VARCHAR(20) NOT NULL
);
CREATE INDEX idx_moves_item ON stock_moves (item_id, move_date);
CREATE INDEX idx_moves_source ON stock_moves (source_type, source_id);

-- ---------------------------------------------------------------------------
-- Sales: quotations and invoices
-- ---------------------------------------------------------------------------

CREATE TABLE quotations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    number        VARCHAR(30) NOT NULL UNIQUE,
    contact_id    INTEGER NOT NULL REFERENCES contacts (id),
    issue_date    VARCHAR(10) NOT NULL,
    valid_until   VARCHAR(10) NULL,
    status        VARCHAR(15) NOT NULL DEFAULT 'draft', -- draft|sent|accepted|rejected|expired|invoiced
    subject       VARCHAR(200) NOT NULL DEFAULT '',
    subtotal      INTEGER NOT NULL DEFAULT 0,
    discount_total INTEGER NOT NULL DEFAULT 0,
    tax_total     INTEGER NOT NULL DEFAULT 0,
    total         INTEGER NOT NULL DEFAULT 0,
    notes         TEXT NULL,
    terms         TEXT NULL,
    invoice_id    INTEGER NULL,
    created_by    INTEGER NULL REFERENCES users (id),
    created_at    VARCHAR(20) NOT NULL,
    updated_at    VARCHAR(20) NULL
);
CREATE INDEX idx_quotes_contact ON quotations (contact_id);

CREATE TABLE quotation_lines (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    quotation_id  INTEGER NOT NULL REFERENCES quotations (id) ON DELETE CASCADE,
    item_id       INTEGER NULL REFERENCES items (id),
    description   VARCHAR(255) NOT NULL DEFAULT '',
    quantity      REAL    NOT NULL DEFAULT 1,
    uom           VARCHAR(20) NOT NULL DEFAULT 'PCS',
    unit_price    INTEGER NOT NULL DEFAULT 0,
    discount_pct  REAL    NOT NULL DEFAULT 0,
    tax_rate      REAL    NOT NULL DEFAULT 0,
    line_subtotal INTEGER NOT NULL DEFAULT 0,
    line_tax      INTEGER NOT NULL DEFAULT 0,
    line_total    INTEGER NOT NULL DEFAULT 0,
    line_no       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_qlines_quote ON quotation_lines (quotation_id);

CREATE TABLE sales_invoices (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    number         VARCHAR(30) NOT NULL UNIQUE,
    contact_id     INTEGER NOT NULL REFERENCES contacts (id),
    issue_date     VARCHAR(10) NOT NULL,
    due_date       VARCHAR(10) NOT NULL,
    status         VARCHAR(15) NOT NULL DEFAULT 'draft', -- draft|posted|partial|paid|void
    lpo_number     VARCHAR(60) NOT NULL DEFAULT '',  -- the customer's purchase order
    project        VARCHAR(120) NOT NULL DEFAULT '',
    warehouse_id   INTEGER NULL REFERENCES warehouses (id),
    subtotal       INTEGER NOT NULL DEFAULT 0,
    discount_total INTEGER NOT NULL DEFAULT 0,
    tax_total      INTEGER NOT NULL DEFAULT 0,
    total          INTEGER NOT NULL DEFAULT 0,
    amount_paid    INTEGER NOT NULL DEFAULT 0,
    notes          TEXT NULL,
    terms          TEXT NULL,
    quotation_id   INTEGER NULL REFERENCES quotations (id),
    journal_id     INTEGER NULL REFERENCES journals (id),
    posted_at      VARCHAR(20) NULL,
    voided_at      VARCHAR(20) NULL,
    void_reason    VARCHAR(255) NOT NULL DEFAULT '',
    created_by     INTEGER NULL REFERENCES users (id),
    created_at     VARCHAR(20) NOT NULL,
    updated_at     VARCHAR(20) NULL
);
CREATE INDEX idx_inv_contact ON sales_invoices (contact_id);
CREATE INDEX idx_inv_status ON sales_invoices (status, due_date);
CREATE INDEX idx_inv_date ON sales_invoices (issue_date);

CREATE TABLE sales_invoice_lines (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id    INTEGER NOT NULL REFERENCES sales_invoices (id) ON DELETE CASCADE,
    item_id       INTEGER NULL REFERENCES items (id),
    description   VARCHAR(255) NOT NULL DEFAULT '',
    quantity      REAL    NOT NULL DEFAULT 1,
    uom           VARCHAR(20) NOT NULL DEFAULT 'PCS',
    unit_price    INTEGER NOT NULL DEFAULT 0,
    discount_pct  REAL    NOT NULL DEFAULT 0,
    tax_rate      REAL    NOT NULL DEFAULT 0,
    line_subtotal INTEGER NOT NULL DEFAULT 0,
    line_tax      INTEGER NOT NULL DEFAULT 0,
    line_total    INTEGER NOT NULL DEFAULT 0,
    line_no       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_ilines_invoice ON sales_invoice_lines (invoice_id);

-- ---------------------------------------------------------------------------
-- Purchases
-- ---------------------------------------------------------------------------

CREATE TABLE purchase_bills (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    number             VARCHAR(30) NOT NULL UNIQUE,
    contact_id         INTEGER NOT NULL REFERENCES contacts (id),
    supplier_invoice_no VARCHAR(60) NOT NULL DEFAULT '',
    issue_date         VARCHAR(10) NOT NULL,
    due_date           VARCHAR(10) NOT NULL,
    status             VARCHAR(15) NOT NULL DEFAULT 'draft',
    warehouse_id       INTEGER NULL REFERENCES warehouses (id),
    subtotal           INTEGER NOT NULL DEFAULT 0,
    discount_total     INTEGER NOT NULL DEFAULT 0,
    tax_total          INTEGER NOT NULL DEFAULT 0,
    total              INTEGER NOT NULL DEFAULT 0,
    amount_paid        INTEGER NOT NULL DEFAULT 0,
    notes              TEXT NULL,
    journal_id         INTEGER NULL REFERENCES journals (id),
    posted_at          VARCHAR(20) NULL,
    voided_at          VARCHAR(20) NULL,
    void_reason        VARCHAR(255) NOT NULL DEFAULT '',
    created_by         INTEGER NULL REFERENCES users (id),
    created_at         VARCHAR(20) NOT NULL,
    updated_at         VARCHAR(20) NULL
);
CREATE INDEX idx_bill_contact ON purchase_bills (contact_id);
CREATE INDEX idx_bill_status ON purchase_bills (status, due_date);

CREATE TABLE purchase_bill_lines (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    bill_id       INTEGER NOT NULL REFERENCES purchase_bills (id) ON DELETE CASCADE,
    item_id       INTEGER NULL REFERENCES items (id),
    account_id    INTEGER NULL REFERENCES accounts (id),
    description   VARCHAR(255) NOT NULL DEFAULT '',
    quantity      REAL    NOT NULL DEFAULT 1,
    uom           VARCHAR(20) NOT NULL DEFAULT 'PCS',
    unit_price    INTEGER NOT NULL DEFAULT 0,
    discount_pct  REAL    NOT NULL DEFAULT 0,
    tax_rate      REAL    NOT NULL DEFAULT 0,
    line_subtotal INTEGER NOT NULL DEFAULT 0,
    line_tax      INTEGER NOT NULL DEFAULT 0,
    line_total    INTEGER NOT NULL DEFAULT 0,
    line_no       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_blines_bill ON purchase_bill_lines (bill_id);

-- ---------------------------------------------------------------------------
-- Payments (money in and money out)
-- ---------------------------------------------------------------------------

CREATE TABLE payments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    number        VARCHAR(30) NOT NULL UNIQUE,
    direction     VARCHAR(4)  NOT NULL, -- in | out
    contact_id    INTEGER NULL REFERENCES contacts (id),
    payment_date  VARCHAR(10) NOT NULL,
    method        VARCHAR(20) NOT NULL DEFAULT 'bank', -- cash|bank|cheque|card|online
    account_id    INTEGER NOT NULL REFERENCES accounts (id), -- the cash/bank account
    amount        INTEGER NOT NULL DEFAULT 0,
    allocated     INTEGER NOT NULL DEFAULT 0,
    reference     VARCHAR(80) NOT NULL DEFAULT '',
    cheque_number VARCHAR(40) NOT NULL DEFAULT '',
    cheque_date   VARCHAR(10) NULL,
    bank_name     VARCHAR(120) NOT NULL DEFAULT '',
    notes         TEXT NULL,
    status        VARCHAR(15) NOT NULL DEFAULT 'posted', -- posted | void
    journal_id    INTEGER NULL REFERENCES journals (id),
    voided_at     VARCHAR(20) NULL,
    created_by    INTEGER NULL REFERENCES users (id),
    created_at    VARCHAR(20) NOT NULL
);
CREATE INDEX idx_pay_contact ON payments (contact_id, direction);
CREATE INDEX idx_pay_date ON payments (payment_date);

-- Which documents a payment settles. A single cheque often clears several
-- invoices, which is exactly how Qatari customers tend to pay.
CREATE TABLE payment_allocations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id   INTEGER NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    doc_type     VARCHAR(20) NOT NULL, -- sales_invoice | purchase_bill
    doc_id       INTEGER NOT NULL,
    amount       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_alloc_doc ON payment_allocations (doc_type, doc_id);
CREATE INDEX idx_alloc_payment ON payment_allocations (payment_id);

-- ---------------------------------------------------------------------------
-- HR and payroll
-- ---------------------------------------------------------------------------

CREATE TABLE employees (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    code               VARCHAR(20)  NOT NULL UNIQUE,
    name_en            VARCHAR(160) NOT NULL,
    name_ar            VARCHAR(160) NOT NULL DEFAULT '',
    qid                VARCHAR(11)  NOT NULL DEFAULT '',
    qid_expiry         VARCHAR(10)  NULL,
    passport_number    VARCHAR(30)  NOT NULL DEFAULT '',
    passport_expiry    VARCHAR(10)  NULL,
    visa_number        VARCHAR(30)  NOT NULL DEFAULT '',
    visa_expiry        VARCHAR(10)  NULL,
    health_card_expiry VARCHAR(10)  NULL,
    contract_expiry    VARCHAR(10)  NULL,
    nationality        VARCHAR(60)  NOT NULL DEFAULT '',
    date_of_birth      VARCHAR(10)  NULL,
    gender             VARCHAR(10)  NOT NULL DEFAULT '',
    designation        VARCHAR(120) NOT NULL DEFAULT '',
    department         VARCHAR(120) NOT NULL DEFAULT '',
    sponsor            VARCHAR(160) NOT NULL DEFAULT '',
    join_date          VARCHAR(10)  NOT NULL,
    end_date           VARCHAR(10)  NULL,
    status             VARCHAR(15)  NOT NULL DEFAULT 'active', -- active | on_leave | terminated
    -- Wage components. Gratuity is computed on basic only (Art. 54); the WPS
    -- file reports basic and net separately, so they are stored apart.
    basic_salary       INTEGER NOT NULL DEFAULT 0,
    housing_allowance  INTEGER NOT NULL DEFAULT 0,
    transport_allowance INTEGER NOT NULL DEFAULT 0,
    food_allowance     INTEGER NOT NULL DEFAULT 0,
    other_allowance    INTEGER NOT NULL DEFAULT 0,
    -- WPS payment details
    bank_name          VARCHAR(120) NOT NULL DEFAULT '',
    bank_short_name    VARCHAR(20)  NOT NULL DEFAULT '',
    iban               VARCHAR(34)  NOT NULL DEFAULT '',
    salary_frequency   VARCHAR(2)   NOT NULL DEFAULT 'M',
    contract_hours_month INTEGER NOT NULL DEFAULT 208,
    leave_carried_forward REAL NOT NULL DEFAULT 0,
    email              VARCHAR(160) NOT NULL DEFAULT '',
    phone              VARCHAR(30)  NOT NULL DEFAULT '',
    address            VARCHAR(255) NOT NULL DEFAULT '',
    notes              TEXT NULL,
    created_at         VARCHAR(20) NOT NULL,
    updated_at         VARCHAR(20) NULL
);
CREATE INDEX idx_emp_status ON employees (status);
CREATE INDEX idx_emp_qid ON employees (qid);

CREATE TABLE payroll_runs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    period_year   INTEGER NOT NULL,
    period_month  INTEGER NOT NULL,
    pay_date      VARCHAR(10) NOT NULL,
    status        VARCHAR(15) NOT NULL DEFAULT 'draft', -- draft | approved | paid
    working_days  INTEGER NOT NULL DEFAULT 0,
    total_gross   INTEGER NOT NULL DEFAULT 0,
    total_deductions INTEGER NOT NULL DEFAULT 0,
    total_net     INTEGER NOT NULL DEFAULT 0,
    employee_count INTEGER NOT NULL DEFAULT 0,
    notes         TEXT NULL,
    journal_id    INTEGER NULL REFERENCES journals (id),
    sif_generated_at VARCHAR(20) NULL,
    approved_at   VARCHAR(20) NULL,
    paid_at       VARCHAR(20) NULL,
    created_by    INTEGER NULL REFERENCES users (id),
    created_at    VARCHAR(20) NOT NULL,
    UNIQUE (period_year, period_month)
);

CREATE TABLE payslips (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id            INTEGER NOT NULL REFERENCES payroll_runs (id) ON DELETE CASCADE,
    employee_id       INTEGER NOT NULL REFERENCES employees (id),
    working_days      INTEGER NOT NULL DEFAULT 0,
    basic             INTEGER NOT NULL DEFAULT 0,
    housing           INTEGER NOT NULL DEFAULT 0,
    transport         INTEGER NOT NULL DEFAULT 0,
    food              INTEGER NOT NULL DEFAULT 0,
    other_allowance   INTEGER NOT NULL DEFAULT 0,
    overtime_hours    REAL    NOT NULL DEFAULT 0,
    overtime_amount   INTEGER NOT NULL DEFAULT 0,
    bonus             INTEGER NOT NULL DEFAULT 0,
    absence_days      REAL    NOT NULL DEFAULT 0,
    absence_deduction INTEGER NOT NULL DEFAULT 0,
    loan_deduction    INTEGER NOT NULL DEFAULT 0,
    other_deduction   INTEGER NOT NULL DEFAULT 0,
    gross             INTEGER NOT NULL DEFAULT 0,
    total_deductions  INTEGER NOT NULL DEFAULT 0,
    net               INTEGER NOT NULL DEFAULT 0,
    notes             VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE (run_id, employee_id)
);
CREATE INDEX idx_payslip_emp ON payslips (employee_id);

CREATE TABLE leave_requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id  INTEGER NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
    leave_type   VARCHAR(20) NOT NULL DEFAULT 'annual', -- annual|sick|unpaid|maternity|paternity|hajj|compassionate
    start_date   VARCHAR(10) NOT NULL,
    end_date     VARCHAR(10) NOT NULL,
    days         REAL    NOT NULL DEFAULT 0,
    status       VARCHAR(15) NOT NULL DEFAULT 'pending', -- pending|approved|rejected|cancelled
    reason       VARCHAR(255) NOT NULL DEFAULT '',
    approved_by  INTEGER NULL REFERENCES users (id),
    approved_at  VARCHAR(20) NULL,
    created_at   VARCHAR(20) NOT NULL
);
CREATE INDEX idx_leave_emp ON leave_requests (employee_id, status);

-- End-of-service settlements, kept so the figure that was actually paid is on
-- record even if the salary or the law changes afterwards.
CREATE TABLE gratuity_settlements (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id      INTEGER NOT NULL REFERENCES employees (id),
    calculated_on    VARCHAR(10) NOT NULL,
    last_working_day VARCHAR(10) NOT NULL,
    reason           VARCHAR(40) NOT NULL DEFAULT 'resignation',
    service_days     INTEGER NOT NULL DEFAULT 0,
    service_years    REAL    NOT NULL DEFAULT 0,
    weeks_per_year   REAL    NOT NULL DEFAULT 3,
    basic_salary     INTEGER NOT NULL DEFAULT 0,
    gratuity_amount  INTEGER NOT NULL DEFAULT 0,
    leave_days       REAL    NOT NULL DEFAULT 0,
    leave_encashment INTEGER NOT NULL DEFAULT 0,
    notice_pay       INTEGER NOT NULL DEFAULT 0,
    other_dues       INTEGER NOT NULL DEFAULT 0,
    deductions       INTEGER NOT NULL DEFAULT 0,
    net_payable      INTEGER NOT NULL DEFAULT 0,
    status           VARCHAR(15) NOT NULL DEFAULT 'draft', -- draft | approved | paid
    notes            TEXT NULL,
    journal_id       INTEGER NULL REFERENCES journals (id),
    created_by       INTEGER NULL REFERENCES users (id),
    created_at       VARCHAR(20) NOT NULL
);
CREATE INDEX idx_gratuity_emp ON gratuity_settlements (employee_id);

-- Eid dates move each year with the Hijri calendar and are announced by the
-- Amiri Diwan, so holidays are entered rather than computed.
CREATE TABLE holidays (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    holiday_date VARCHAR(10) NOT NULL,
    name_en  VARCHAR(120) NOT NULL,
    name_ar  VARCHAR(120) NOT NULL DEFAULT '',
    is_paid  INTEGER NOT NULL DEFAULT 1,
    UNIQUE (holiday_date, name_en)
);
