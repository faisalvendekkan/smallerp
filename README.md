# SmallERP — accounting, stock and payroll for Qatari SMEs

A small, self-contained ERP for the kind of company that actually runs Qatar's
private sector: five to fifty staff, a warehouse or a workshop, a handful of
corporate customers who pay on an LPO, and a payroll that has to clear the Wage
Protection System by the seventh of every month.

It is written in plain PHP 8.1+ with **no Composer dependencies and no build
step**, because it has to deploy the way these companies actually deploy
software — by uploading a folder to a cPanel account.

```
git clone <this repo> smallerp
cd smallerp
php -S localhost:8000 -t public
```

Open <http://localhost:8000>, and the installer will create the database and
your administrator account. Tick "load demo data" to get a worked example: a
Doha trading and maintenance company with a quarter of trading, six employees
and a completed payroll run.

---

## What makes it a Qatar ERP rather than a generic one

Most of the work in an ERP is the same everywhere. These are the parts that
are not, and they are the reason this exists.

### Wage Protection System (WPS)

Since Law No. 1 of 2015 amended Art. 66 of the Labour Law, wages must be paid
through the WPS and a **Salary Information File (SIF)** submitted to your bank
each month. SmallERP produces that file:

- one `EDR` line per employee, then a single `SCR` control line with the
  employer details and the totals the bank reconciles against;
- CRLF line endings and the conventional `<EID><YYYYMM><DDHHMM>.SIF` filename;
- **every record validated before the file is written**, with all the problems
  reported at once — a rejected SIF holds up an entire month's payroll, so
  finding the four bad IBANs in one pass matters;
- the Art. 66 seven-day deadline shown against every run, and flagged red once
  it has passed.

> The layout follows the Ministry of Labour / Qatar Central Bank SIF
> specification. Banks occasionally publish their own column notes, so confirm
> the layout with yours before your first live submission — the SIF version is
> configurable under Settings.

### End-of-service gratuity (Art. 54)

Gratuity accrues from the first day of service even though it is only payable
after a completed year, which makes it the largest liability an established SME
typically has *never booked*. SmallERP calculates it properly:

- daily basic wage (monthly basic ÷ 30) × weeks per year × 7 × years of service;
- **basic salary only** — allowances never inflate it;
- part-years pro rata, unpaid leave excluded from service;
- configurable tiers (3 weeks default, rising after 5 and 10 years) that cannot
  go below the statutory minimum;
- a full settlement screen adding leave encashment and pay in lieu of notice
  (Art. 49: one month under five years, two months after), shown for review
  before anything is posted;
- a liability report showing what you would owe if everyone left today.

### Residence documents

A QID or residence visa that lapses stops an employee working and exposes the
company to a fine, and nobody sends you a reminder. Every employee's QID,
visa, passport, health card and contract expiry is tracked, surfaced on the
dashboard, badged in the sidebar and reported with days remaining.

### Qatari identifiers, validated

- **QID** — 11 digits, century and birth-year decoded, nationality code
  extracted. Qatar publishes no check-digit algorithm, so this validates
  structure only and says so rather than pretending otherwise.
- **IBAN** — the full ISO 13616 mod-97 check, so a transposed digit in a salary
  account is caught at data entry instead of by the bank three days later.
  Bank short names for the SIF are derived from the IBAN.
- **CR number**, **Establishment ID**, and Qatari phone numbers (8 digits,
  mobile prefixes 3/5/6/7, fixed lines 4).

### Tax, honestly handled

**Qatar has no VAT in force.** The GCC Framework Agreement commits members to a
5% standard rate and Qatar's domestic law is drafted but not commenced, so
every tax rate defaults to **zero** — while the machinery, the tax return
report and the input/output tax accounts all sit ready. When VAT commences an
SME flips a switch in Settings rather than changing software.

Corporate income tax (10%, Law No. 24 of 2018) appears as an indicative line on
the P&L, with the caveat that wholly Qatari and GCC-owned entities are
generally exempt.

### Bilingual, properly

English and Arabic throughout, with the whole interface flipping to
right-to-left. Printed invoices, quotations, payslips, receipt vouchers and
statements are bilingual side by side, including the **amount in words in both
languages** — with correct Arabic pluralisation (ألفان for two thousand, not
"2 ألف") — because a Qatari payment department will ask for it.

### Other local details

The Sunday–Thursday working week; National Sports Day computed as the second
Tuesday of February while the two Eids are entered by hand because they follow
the Hijri calendar; a chart of accounts with Kahramaa, visa and immigration
fees, and municipality charges already on it; customer LPO numbers on invoices;
and post-dated cheque tracking, because every SME here is juggling them.

---

## What it does

| Module | What you get |
|---|---|
| **Sales** | Quotations → invoices → receipts. Bilingual printable tax invoice, customer LPO reference, credit limits, statement of account. |
| **Purchases** | Supplier bills with duplicate-invoice detection, expense coding straight to an account (rent, Kahramaa, fuel), payments with allocation. |
| **Inventory** | Items and services, multiple warehouses, weighted-average costing, stock adjustments that post to the ledger, reorder alerts. |
| **Accounting** | Full double-entry ledger, chart of accounts, manual journals, account ledgers with running balances, trial balance, P&L, balance sheet. |
| **HR** | Employees with residence-document tracking, leave requests and balances, end-of-service settlements. |
| **Payroll** | Monthly runs pre-filled from contracts and pro-rated for joiners and leavers, overtime at the Art. 74 multipliers, WPS SIF export, bilingual payslips. |
| **Reports** | Trial balance, P&L, balance sheet, AR/AP ageing on 30/60/90 buckets, sales by customer and item with margin, tax summary, document expiries, gratuity liability. CSV export throughout. |
| **Admin** | Five roles, audit trail, company settings, public holidays, document numbering. |

---

## Design decisions worth knowing

**Money is never a float.** Every amount is an integer number of dirhams
(1 QAR = 100 dirhams), rounded half-up, which is what an accountant in Doha
expects — not PHP's banker's rounding. A ledger out by a fraction of a dirham
is a ledger nobody signs off.

**Nothing bypasses the posting engine.** Every financial document — invoice,
bill, payment, payroll run, settlement — becomes a journal through one method
that refuses anything unbalanced. That single rule is what makes the trial
balance trustworthy.

**Posted documents are immutable.** A mistake is corrected by voiding, which
reverses the journal and leaves both entries visible. Editing history is what
an auditor is looking for, and they should not find it here.

**Roles are coarse on purpose.** A five-person company in Doha does not want a
permission matrix; it wants "the accountant can post journals and the salesman
cannot". Payroll is deliberately walled off from the sales role.

---

## Installation

### Local or a LAN server

```bash
php -S 0.0.0.0:8000 -t public
```

### Shared hosting (cPanel, Plesk)

1. Upload the folder.
2. Point the domain's document root at `public/`. If you cannot, the root
   `.htaccess` forwards requests into it and blocks `app/`, `database/`,
   `storage/` and `views/`.
3. Copy `config.example.php` to `config.php` and set your database and app key:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
4. Make `storage/` writable (`chmod 775`).
5. Open the site; the installer does the rest.

### MySQL instead of SQLite

Set `'driver' => 'mysql'` in `config.php` with your credentials, and create an
empty database. The schema is written once and translated for MySQL by the
migrator, so there is one source of truth for the tables.

SQLite is a perfectly sound choice for a single-branch company — it is what the
demo runs on — but MySQL is the safer bet on shared hosting with several
concurrent users.

### Requirements

PHP 8.1 or newer with `pdo_sqlite` (or `pdo_mysql`) and `mbstring`. That is all.

---

## Running the tests

```bash
php tests/run.php          # everything
php tests/run.php Wps      # just the WPS suite
```

278 assertions covering money arithmetic and rounding, Qatari identifier
validation, gratuity and leave under the Labour Law, SIF generation and
validation, the ledger's balancing rules, and the invoice → stock → payment
cycle end to end.

The harness is 200 lines of plain PHP for the same reason the app has no
framework: the tests should run wherever the application does.

---

## Project layout

```
public/            document root — front controller, CSS, JS
app/
  Core/            router, PDO wrapper, auth, views, i18n, CSRF, audit log
  Support/         Money, Qatar, LabourLaw, Wps — the domain rules, no database
  Services/        Ledger, Sales, Purchases, Payments, Inventory, Payroll, Hr
  Controllers/     one per module
  lang/            en.php, ar.php
database/          schema.sql, Seeder.php
views/             plain PHP templates, including the printable documents
tests/             dependency-free test suite
```

`app/Support/` is where every rule anchored in Qatari law lives, with the
article cited in the docstring. When the law moves there is one file to edit.

---

## Legal references

- Labour Law No. 14 of 2004, as amended (notably by Law No. 17 of 2020) —
  Art. 49 notice, Art. 54 gratuity, Art. 66 wage payment and the WPS, Art. 73
  weekly rest, Art. 74 overtime, Art. 79 annual leave.
- Law No. 1 of 2015 — introduced the Wage Protection System.
- Law No. 24 of 2018 — Income Tax.
- Law No. 25 of 2018 — Excise Tax.
- GCC VAT Framework Agreement — not yet commenced in Qatar.

**This is software, not advice.** The calculations follow the articles cited
above and are covered by tests, but a company's own contracts and circumstances
vary. Have your accountant or PRO check the figures before you rely on them,
and confirm the SIF layout with your bank before your first live submission.
