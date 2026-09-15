# Deploying SmallERP to Hostinger

Two ways to do this. Both end with GitHub as the source of truth, so a deploy
is a `git push` rather than someone dragging files into an FTP client.

| | **A — GitHub Actions** (recommended) | **B — Hostinger's built-in Git** |
|---|---|---|
| Tests run before shipping | Yes | No |
| Works on every Hostinger plan | Yes | Yes |
| Setup | Add 4 secrets to GitHub | Paste repo URL into hPanel |
| Auto-deploys on push | Yes | Only with a webhook |

Option A is already built — `.github/workflows/deploy.yml` is in the repo and
will run as soon as the secrets exist. Start there.

---

## Before either option: prepare the hosting account

### 1. Set the PHP version

hPanel → **Websites** → your site → **Advanced** → **PHP Configuration**.

Choose **PHP 8.2 or 8.3**. SmallERP needs 8.1 as a minimum and will refuse to
start on anything older with a clear message rather than a blank page.

On the same screen, under **PHP extensions**, confirm `mbstring` and either
`pdo_mysql` or `pdo_sqlite` are ticked. Both are on by default.

### 2. Point the document root at `public/`

This is the single most important setting. Only `public/` should be reachable
from the web; everything else — your database, your configuration, the
application code — sits above it.

hPanel → **Websites** → your site → **Website settings** → **Document root**,
and set it to:

```
public_html/public
```

> **If your plan does not let you change the document root**, the app still
> works: the `.htaccess` in the repository root forwards every request into
> `public/` and refuses `app/`, `database/`, `storage/`, `views/` and `tests/`.
> Each of those directories also carries its own `.htaccess` denying access,
> and database files are blocked by extension. It is belt and braces, but
> changing the document root is still cleaner — do it if you can.

### 3. Create the database

**MySQL** (better when more than one person uses the system at once):

hPanel → **Databases** → **MySQL Databases** → create a database and a user,
and note the full prefixed names — Hostinger prepends your account number,
so you end up with something like `u123456789_smallerp`.

**SQLite** needs nothing: the app creates the file in `storage/` on first run.
It is a perfectly sound choice for a single-branch company, which is what the
demo runs on.

### 4. Create the FTP account used for deployment

hPanel → **Files** → **FTP Accounts** → **Create a new FTP account**.

Make a dedicated account for deployment rather than reusing your main one, and
note these four values — they become the GitHub secrets:

| From hPanel | GitHub secret |
|---|---|
| FTP hostname (e.g. `ftp.yourdomain.com`) | `FTP_SERVER` |
| FTP username (e.g. `u123456789.deploy`) | `FTP_USERNAME` |
| The password you set | `FTP_PASSWORD` |
| Directory, **with a trailing slash** — usually `public_html/` | `FTP_SERVER_DIR` |

---

## Option A — GitHub Actions (recommended)

### 1. Add the secrets

In GitHub: **Settings** → **Secrets and variables** → **Actions** →
**New repository secret**. Add all four from the table above.

The workflow checks they exist and fails with a clear message if any is
missing, rather than half-deploying.

### 2. Push

Every push to the default branch now runs the test suite on PHP 8.1–8.4 and,
only if it passes, uploads over FTPS. You can also trigger it by hand from the
**Actions** tab → **Deploy to Hostinger** → **Run workflow**.

### What the deploy deliberately does not touch

This matters more than anything else in this document. The workflow excludes:

```
storage/**      the SQLite database, exported files and logs
config.php      your database credentials and app key
*.sqlite*       any database file, wherever it is
```

Excluded paths are **neither uploaded nor deleted**, so a deploy can never
overwrite your accounting data or your credentials. `tests/`, `docs/`,
`deploy/` and `.github/` are skipped too — they are not needed on a live
server.

### 3. Write `config.php` on the server, once

The deploy cannot create this file: it holds your credentials, so it is
git-ignored by design. Create it once through hPanel → **Files** →
**File Manager**, in the application root, next to `README.md`.

Use `deploy/config.production.php` in this repository as the template. Generate
the app key with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Then set `debug` to `false` and `https_only` to `true`.

### 4. Check it

```bash
php deploy/preflight.php
```

Run it from hPanel → **Advanced** → **Terminal** (or SSH). It checks the PHP
version and extensions, that `config.php` exists with debug off and a real app
key, that `storage/` is writable, and that the directory guards are in place.
It exits non-zero and says exactly what to fix if anything is wrong.

### 5. Open the site

The installer appears on first visit. Create the administrator account. **Do
not tick "load demo data"** on a real company — that is the sample Doha trading
business, and you would then be deleting its invoices out of your books.

---

## Option B — Hostinger's built-in Git

hPanel → **Websites** → your site → **Advanced** → **GIT**.

1. **Repository**: `https://github.com/faisalvendekkan/smallerp.git`
   For a private repository, add Hostinger's deploy key to GitHub under
   **Settings** → **Deploy keys** first.
2. **Branch**: your default branch.
3. **Directory**: leave blank for `public_html`.
4. Click **Create**, then **Deploy** whenever you want to pull.

To make it automatic, copy the **webhook URL** Hostinger shows you and add it
in GitHub under **Settings** → **Webhooks**, content type `application/json`,
for the push event.

**The caveat:** this method pulls the repository as-is and runs no tests, so a
broken commit goes straight to production. It also performs a plain checkout —
keep `config.php` and `storage/` out of the repository (they already are) so it
cannot clobber them.

---

## Post-deploy checklist

Run through this the first time, and after any change to the hosting setup.

- [ ] `https://yourdomain.com/` shows the sign-in page
- [ ] `https://yourdomain.com/storage/smallerp.sqlite` returns **403 or 404** —
      never a download. If this file downloads, stop and fix the document root.
- [ ] `https://yourdomain.com/config.php` returns 403 and does not display
- [ ] `https://yourdomain.com/app/` returns 403, not a file listing
- [ ] The padlock shows — hPanel → **Security** → **SSL**, and force HTTPS
- [ ] `php deploy/preflight.php` reports "Ready for production"
- [ ] Sign in, open **Settings**, and fill in the CR number, Establishment ID
      and WPS salary account — printed invoices and the WPS file need them
- [ ] Raise a test invoice, print it, then void it

## Backups

SmallERP is your accounting system: losing it means losing your books.

**SQLite** — the whole database is one file. Download `storage/smallerp.sqlite`
on a schedule, or from the hPanel terminal:

```bash
sqlite3 storage/smallerp.sqlite ".backup storage/backup-$(date +%F).sqlite"
```

**MySQL** — hPanel → **Databases** → **Backups**, or `mysqldump`.

Hostinger takes its own weekly backups on most plans, but weekly is a week of
invoices. Take your own as well, and keep a copy off the server.

## Updating

Push to the default branch; the workflow tests and ships it. Your `config.php`
and `storage/` are untouched, so the database survives the update. If a schema
change is ever needed it will be noted in the release; there is no migration
step today beyond the initial install.

## Troubleshooting

**A blank white page.** Almost always a PHP error with `display_errors` off,
which is correct for production. Look in `storage/logs/error.log`.

**"SmallERP requires PHP 8.1 or newer".** Raise the PHP version in hPanel.

**"Cannot create database directory" or a permissions error.** Set `storage/`
to 755 in the file manager, and check it is owned by your hosting user.

**The installer reappears after it has already run.** The app cannot see its
database. With MySQL, check the credentials in `config.php`; with SQLite, check
that `storage/smallerp.sqlite` exists and is writable.

**The deploy fails with "530 Login authentication failed".** The FTP secrets
are wrong. Re-check them in hPanel — the username usually includes the account
prefix, and `FTP_SERVER_DIR` needs its trailing slash.

**Arabic renders as question marks.** The database is not `utf8mb4`. Recreate
it with that character set; the schema already asks for it on MySQL.
