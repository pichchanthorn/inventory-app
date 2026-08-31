<img src="assets/bbu-logo.png" width="70" align="right" alt="Build Bright University" />

**🌐 Language:** English · [ភាសាខ្មែរ](README.km.md)

# 📦 Inventory Management System

**University:** Build Bright University (BBU)
**Course:** Advanced PHP & MySQL
**Business:** PCTN Fertilizer Shop, Cambodia — built for real day-to-day shop use, not just as a coursework demo
**Stack:** PHP (PDO) · MySQL/MariaDB · Bootstrap 5 · Vanilla JS

A full-stack inventory management system for tracking agrochemical products
(fertilizers, pesticides), suppliers, customers, and stock movements. It
started as an Advanced PHP & MySQL university project and was deliberately
carried further than a typical coursework submission — real business
concerns like customer debt tracking, concurrency-safe stock updates, and
an audit trail were added because a real shop would actually need them, not
because a rubric asked for them. It's plain PHP and MySQL, no framework,
with prepared statements used throughout for SQL-injection safety.

---

## ✨ Features

| Module | What it does |
|---|---|
| **Auth** | Login (hashed passwords), logout, session-based access control. Public self-registration exists but is **disabled by default** — see the Security & Data Integrity section below. |
| **Roles** | Admin / User / Viewer — delete actions across Categories, Units, Suppliers, and Products are Admin-only; Viewers are read-only everywhere. |
| **User management** | Admin-only page to create staff accounts (temporary password, chosen role), and change any user's role. |
| **Forced password reset** | Admin-created accounts can require a password change on first login before the rest of the app is accessible. |
| **Audit Trail** | Every create/update/delete on Categories, Units, Suppliers, Products, Users, and profile changes is logged with a before/after snapshot, actor, and timestamp — viewable on an Admin-only Audit Log page. Password values are structurally excluded from every snapshot by design (allowlist-based, not just hidden). |
| **Dashboard** | Live totals: products, units in stock, inventory value, low-stock alerts. |
| **Categories** | Full CRUD with search, mobile-friendly card layout. |
| **Units** | Full CRUD with search, mobile-friendly card layout. |
| **Suppliers** | Full CRUD with search (phone, email, address), mobile-friendly card layout. |
| **Products** | Full CRUD — linked to category/supplier/unit, tracks separate `cost_price` and `sale_price`, auto-calculated margin %, low-stock badge, mobile-friendly card layout, USD/KHR price entry toggle. |
| **Stock In** | Multi-line receiving form; increases stock and logs a transaction inside a DB transaction; searchable product picker; **barcode scanner** (camera-based, matches against product barcode or SKU); unit price defaults to the product's **cost price**; USD/KHR cost entry toggle. |
| **Stock Out** | Multi-line issuing form for non-sale stock movements (damaged, lost, internal use, transfer, etc.); decreases stock with a concurrency-safe availability check; searchable product picker; unit price defaults to the product's **cost price**, not the POS sale price. |
| **Stock Adjustments** | Sets an exact stock count with a required reason (for physical counts / corrections), using an optimistic-locking guard against concurrent changes. |
| **Point of Sale (POS)** | Cart-based checkout — add products at **sale price**, cash received / change due, records a `sale` transaction (same stock-safety guarantees as Stock Out, plus a server-side idempotency token so a double-submit or refresh can't double-charge the stock), printable receipt with KHR display at the configured exchange rate. |
| **Customers & Debts** | Customer directory with phone/address; records credit sales as debts linked to the originating POS sale, tracks partial payments, computes outstanding balance and overdue status automatically (database-generated columns, not app-computed caches). |
| **Stock Reports** | Overview, full transaction log (filterable), by-product stock levels, CSV export — includes POS sales alongside Stock In/Out/Adjustments. |
| **Settings** | Admin-only page for the global USD→KHR exchange rate, and an on-demand **database backup download** (streams a `.sql` dump of the live database). |
| **Profile** | Update name/email, change password, upload a profile photo, view role and member-since date. |
| **Theme** | Light/Dark toggle in the sidebar, saved per browser via `localStorage`. |
| **Localization** | Full bilingual English/Khmer interface — sidebar, forms, errors, dates, and toasts all switch instantly via a session-based language toggle. |
| **Mobile-responsive** | Collapsible off-canvas sidebar, and every major listing/form page adapts to a stacked card layout below 768px — usable directly from a phone. |

---

## 📷 Barcode Scanner

Stock In supports scanning a product's printed barcode with a device camera
instead of using the searchable dropdown:

- Uses the [html5-qrcode](https://github.com/mebjas/html5-qrcode) library — no server component, matching happens client-side against already-loaded product data.
- Matches against a product's `barcode` field first, falling back to `sku` if no barcode match is found.
- If no product matches the scanned code, an inline error is shown and the user can rescan or cancel — no blank row is ever added.
- The manual searchable dropdown remains fully independent and always works, even if the camera is denied, unavailable, or the library fails to load.
- Currently available on **Stock In only** — see the Future Roadmap section below.

> **Browser requirement:** camera access requires a *secure context* —
> `https://` or `localhost`. It will **not** work when the app is accessed
> over plain `http://` on a LAN IP (e.g. `http://192.168.x.x/...`), which is
> a browser security restriction, not an app bug. This resolves itself
> automatically once the app is deployed with HTTPS.

---

## 🏗️ Architecture

This is a traditional server-rendered PHP web application — **not** a REST
API, **not** a Laravel or React application, and **not** a SaaS/multi-tenant
product. There is exactly one PHP application, one MySQL/MariaDB database,
and every page is rendered server-side and returned as HTML.

```
Browser
  │  (HTML forms, fetch() for live search / CSV export)
  ▼
Apache (or Nginx in the Docker setup) + PHP
  │
  ▼
PHP Application (plain PHP, no framework)
  │  includes/auth_check.php   – session + role gate on every protected page
  │  includes/stock.php        – shared stock-mutation logic (Stock In/Out/Adjustment/POS)
  │  includes/audit.php        – shared audit_log writer
  │  includes/csrf.php         – CSRF token issue/verify
  │
  ▼
PDO (prepared statements, no raw string concatenation)
  │
  ▼
MySQL / MariaDB
```

There is no separate API layer or frontend build step: each page's PHP file
handles its own POST actions (read → validate → mutate inside a DB
transaction → redirect) and renders its own HTML. JavaScript is vanilla
(no React/Vue/build tooling) and is used for client-side UX — live search,
the product picker, receipt printing, the barcode scanner — never for
business logic that the server doesn't also enforce.

### Business flow

```
Supplier
  │
  ▼
Stock In            (receives inventory at cost_price)
  │
  ▼
Inventory            (products.current_stock)
  │
  ├──▶ POS                 (sells at sale_price)
  ├──▶ Stock Out            (non-sale movements, at cost_price)
  └──▶ Stock Adjustment     (corrects to a physical count)
  │
  ▼
Stock Transactions   (stock_transactions / stock_transaction_items — one row per movement)
  │
  ▼
Reports / Audit Trail (Dashboard, Stock Reports, Audit Log)
```

A sale that isn't paid in full also creates a row in `customer_debts`,
linked to the same `stock_transactions` row the sale itself created — a
credit sale is still a sale (stock still leaves through the same
concurrency-safe path), just with payment tracked separately.

---

## 🔁 Core Business Workflow

| Module | Purpose | Default unit price |
|---|---|---|
| **Stock In** | Receiving inventory from a supplier | `cost_price` |
| **POS** | Selling to a customer | `sale_price` |
| **Stock Out** | Non-sale stock movements — damaged, lost, internal use, transfer | `cost_price` |
| **Stock Adjustment** | Correcting inventory to a physical count | N/A — sets a quantity, not a price |
| **Customers & Debts** | Tracking credit sales and partial payments | N/A — uses the linked sale's own total |
| **Stock Reports** | Monitoring stock movements and sales over time | — |
| **Audit Log** | Tracking administrative/data changes (who changed what, and what it was before/after) | — |

**Price rule, stated explicitly because it's easy to get backwards:**

- `cost_price` = what the shop paid the supplier.
- `sale_price` = what the shop charges a customer.
- **POS** always uses `sale_price`.
- **Stock In** always uses `cost_price` (it's a purchase).
- **Stock Out** defaults its unit price field to `cost_price` — it exists
  for non-sale movements (damage, loss, internal use, transfers between
  locations), so the relevant number is what the shop lost, not what a
  customer would have paid.

Both Stock In and Stock Out let staff edit the pre-filled price on a given
line before submitting; the field above is only the **default**, not a
locked value.

---

## 🖼️ Screenshots

> **Note on these screenshots:** they were captured from an earlier build
> and predate several current features — the interface shown does not yet
> have the Khmer/English language toggle, the Point of Sale module, or the
> Customers & Debts module described above. They're kept here because
> they still accurately show the overall layout and design system (sidebar
> navigation, dark theme, card-based tables), just not the full current
> feature set. Updated screenshots are a good first contribution if you're
> looking for one.

| Login | Dashboard |
|---|---|
| ![Login](screenshots/login.png) | ![Dashboard](screenshots/dashboard.png) |

| Categories | Products |
|---|---|
| ![Categories](screenshots/categories.png) | ![Products](screenshots/products.png) |

| Stock In | Stock Out |
|---|---|
| ![Stock In](screenshots/stock-in.png) | ![Stock Out](screenshots/stock-out.png) |

| Stock Reports | Profile (Admin) |
|---|---|
| ![Stock Reports](screenshots/stock-report.png) | ![Profile](screenshots/chanthorn_admin.png) |

| Profile (Staff) | User Management (Admin) |
|---|---|
| ![Staff Profile](screenshots/chandara_user.png) | ![User Management](screenshots/User_Administration.png) |

---

## 🗄️ Database Schema

```
roles                    (id, name)
users                    (id, name, email, password, role_id, avatar,
                           must_change_password, created_at, updated_at,
                           created_by, updated_by)
categories               (id, name, slug, note, created_at, updated_at,
                           created_by, updated_by)
units                    (id, name, note)
suppliers                (id, name, phone, email, address, note)
products                 (id, name, sku, barcode, category_id, supplier_id, unit_id,
                           package_size, note, active_ingredient, expiry_date,
                           cost_price, sale_price, min_stock, current_stock,
                           created_at, updated_at, created_by, updated_by)
stock_transactions       (id, reference, type, transaction_date, note,
                           cash_received, supplier_id, user_id, created_at)
stock_transaction_items  (id, transaction_id, product_id, qty, unit_price, subtotal)
customers                (id, name, phone, address, note, created_at, updated_at,
                           created_by, updated_by)
customer_debts           (id, reference, customer_id, stock_transaction_id,
                           total_amount, paid_amount, balance*, status*,
                           due_date, note, created_at, updated_at,
                           created_by, updated_by)
customer_debt_payments   (id, debt_id, amount, payment_date, note,
                           created_at, created_by)
app_settings             (id, usd_to_khr_rate, updated_at)
audit_log                (id, user_id, action, entity_type, entity_id,
                           before_snapshot, after_snapshot, created_at)
idempotency_keys         (id, token, user_id, created_at)
reference_counters       (counter_key, next_value)
```
<sub>* `balance` and `status` on `customer_debts` are database-computed
`GENERATED ALWAYS ... STORED` columns, not values the application sets.</sub>

`stock_transactions.type` is one of `in` / `out` / `adjustment` / `sale` —
every stock change (in, out, manual correction, or a POS sale) is logged
here for a full audit trail. `audit_log` separately tracks create/update/
delete actions on Categories, Units, Suppliers, Products, and Users/
profiles — this is a distinct, page-content audit trail, not to be confused
with stock movement logging. `idempotency_keys` and `reference_counters`
back the concurrency/duplicate-submission protections described in
the Security & Data Integrity section below.

> **Existing installations:** if your database predates any of the migrations
> in `database/migrations/`, run the ones you're missing, in numeric order,
> against your existing database. Fresh installs using the current
> `schema.sql` already include everything.

---

## 📂 Project Structure

```
inventory-app/
├── auth/               Login, register (disabled by default), logout
├── audit/              Admin-only audit log viewer
├── category/           Categories CRUD
├── unit/                Units CRUD
├── supplier/            Suppliers CRUD
├── product/             Products CRUD
├── customer/            Customers & Debts — directory, detail view, payments
├── stock-in/            Stock In form + logic + barcode scanner
├── stock-out/           Stock Out form + logic
├── stock-adjustment/    Stock Adjustments form + logic
├── pos/                 Point of Sale (cart, checkout, receipts)
├── stock-report/        Reports (overview / log / by-product) + CSV export
├── user/                Admin-only user management (create staff, change roles)
├── settings/            Admin-only exchange rate + database backup download
├── includes/            Shared header, footer, auth guard, stock.php, audit.php, csrf.php
├── config/              DB connection + base-URL helper
├── database/            schema.sql, seed.sql, migrations/
├── docker/              Dockerfile + Nginx config for the Docker setup
├── assets/              style.css (design system)
├── lang/                en.php / km.php translation strings
├── uploads/avatars/       Profile photo uploads
├── profile.php
├── dashboard.php
└── index.php
```

---

## ⚙️ Setup & Installation

There are **two ways** to run this project. You only need to pick **one** —
you don't need to do both. If you're not sure which one to choose, read the
"Which one should I pick?" box below.

> **Which one should I pick?**
> - **Never touched a local server before, want the simplest path** → go with
>   **Option 1 (XAMPP)**.
> - **Already have Docker Desktop installed, or want a cleaner one-command
>   setup** → go with **Option 2 (Docker)**.

---

### 🔹 Option 1 — Run with XAMPP (easiest for beginners)

This option uses **XAMPP**, a free all-in-one package that gives you Apache
(web server), MySQL (database), and PHP together, with a simple control
panel — no command line needed.

**Step 1 — Download and install XAMPP**

1. Go to [https://www.apachefriends.org](https://www.apachefriends.org) and
   download the version for your operating system (Windows/Mac/Linux).
2. Run the installer and accept the default options. When it finishes,
   it installs to `C:\xampp` (Windows) by default — keep that default path.
3. Open **XAMPP Control Panel** from your Start Menu / Applications folder.
4. Click **Start** next to both **Apache** and **MySQL**. Both rows should
   turn green. If a row turns red instead, see the Troubleshooting section
   below.

**Step 2 — Download this project**

1. On this GitHub page, click the green **Code** button → **Download ZIP**.
2. Extract the ZIP file. You'll get a folder — rename it to `inventory-app`
   if it isn't already named that.
3. Move that whole `inventory-app` folder into XAMPP's `htdocs` folder:
   - Windows: `C:\xampp\htdocs\inventory-app`
   - Mac: `/Applications/XAMPP/htdocs/inventory-app`
   - Linux: `/opt/lampp/htdocs/inventory-app`

**Step 3 — Create the database**

1. Open your browser and go to `http://localhost/phpmyadmin`.
2. Click the **Import** tab at the top.
3. Click **Choose File**, then select `database/schema.sql` from inside the
   `inventory-app` folder you just copied.
4. Scroll down and click the **Go** button. You should see a success
   message — this creates the `inventory_db` database and all its tables.
5. *(Optional but recommended)* Repeat the same Import steps with
   `database/seed.sql` to load sample PCTN-style categories, suppliers, and
   agrochemical products, so the app isn't completely empty when you first
   log in. `seed.sql` does **not** include a user account — see Step 5.

**Step 4 — Adjust the database password (only if needed)**

Most fresh XAMPP installs have **no password** on the MySQL `root` user, so
you can usually skip this step. If phpMyAdmin asked you for a password when
you logged in, open `config/db.php` in a text editor and change this line:

```php
$pass    = getenv('DB_PASSWORD') ?: '';   // put your MySQL password between the quotes
```

**Step 5 — Create your first account (Admin)**

Public self-registration is **disabled by default**, so there's no account
to log in with yet. The simplest way to get one is to insert an Admin row
directly via phpMyAdmin's **SQL** tab:

1. In phpMyAdmin, select the `inventory_db` database, then open the **SQL**
   tab and run:
   ```sql
   INSERT INTO users (name, email, password, role_id)
   VALUES ('Admin', 'admin@example.com',
     '$2y$12$lvjQx8qdHJktq7R3MyUGh.HjPLQdrbylJuMjYasxN6Yxz7JfnBDHu', 1);
   ```
   This creates an Admin account with email `admin@example.com` and
   password `ChangeMe123!` (the long string is that password already
   hashed with PHP's `password_hash()` — you're not expected to type or
   read it, just paste the statement as-is).
2. Go to `http://localhost/inventory-app/` and log in with those
   credentials.
3. **Change the password immediately** from the Profile page, or create a
   proper account for yourself from the Users page and stop using this one.

Once you have an Admin account, day-to-day staff accounts should be created
from the in-app **Users** page (Admin-only), not by inserting more rows by
hand — that path also sets `must_change_password`, so new staff set their
own password on first login instead of using the one you typed for them.

**XAMPP Troubleshooting**

| Problem | Likely cause & fix |
|---|---|
| Apache row turns red / won't start | Something else on your computer (often Skype, IIS, or another web server) is already using port 80. Close it, or change Apache's port in XAMPP's config. |
| MySQL row turns red / won't start | Another MySQL/MariaDB service (e.g. from Laragon or WAMP) is already running. Stop that other service first, then start XAMPP's MySQL. |
| Page shows "Database connection failed" | MySQL isn't running — go back to XAMPP Control Panel and check it's green. |
| Blank white page | Open XAMPP Control Panel → Apache row → **Logs** → **PHP error log**, to see the actual error message. |
| Barcode scanner says "Camera unavailable" | Expected if you're accessing the app over a LAN IP (`http://192.168.x.x/...`) instead of `http://localhost/...` — camera access requires a secure context. Use `localhost` on the machine with the camera, or wait until the app is deployed with HTTPS. |

---

### 🔹 Option 2 — Run with Docker (fastest, no XAMPP install)

This option uses **Docker**, which packages the web server, PHP, and MySQL
into ready-made containers, so you don't install or configure any of them
by hand — one command starts everything.

**Step 1 — Install Docker Desktop**

1. Go to [https://www.docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop)
   and download it for your operating system.
2. Install it, then open **Docker Desktop** and wait until it says it's
   running (the whale icon in your system tray/menu bar should be steady,
   not animating).

**Step 2 — Download this project**

1. On this GitHub page, click the green **Code** button → **Download ZIP**.
2. Extract the ZIP anywhere you like (it does **not** need to go inside
   XAMPP's `htdocs` for this option — Docker doesn't use XAMPP at all).

**Step 3 — Start the app**

- **Windows:** open the extracted `inventory-app` folder and double-click
  `docker-up.cmd`. A black terminal window will open and do everything for
  you — just wait for it to finish.
- **Mac / Linux:** open a terminal, `cd` into the `inventory-app` folder,
  and run:
  ```
  docker-compose up -d
  ```

The first time you run this, Docker needs to download some images and set
up the database, so it can take a minute or two. Every time after that, it
starts in just a few seconds.

**Step 4 — Create your first account (Admin)**

Same situation as the XAMPP path: public self-registration is disabled by
default, so there's no account yet. Connect a MySQL client (phpMyAdmin,
TablePlus, DBeaver, or the `mysql` CLI) to `127.0.0.1:3307` (see
`docker-compose.yml`), user `root`, password `1234`, database
`inventory_db`, and run the same statement as Step 5 of the XAMPP option
above to create an Admin account (`admin@example.com` /
`ChangeMe123!`), then change the password after logging in.

**Step 5 — Open the app**

1. Go to `http://localhost:9091` in your browser.
2. Log in with the account you just created.

**Stopping / restarting Docker**

- To stop the app: run `docker-compose down` from the project folder.
- To start it again later: run `docker-compose up -d` (or `docker-up.cmd`
  on Windows) again — your data is kept between restarts.

**Docker Troubleshooting**

| Problem | Likely cause & fix |
|---|---|
| `http://localhost:9091` doesn't load | Docker Desktop isn't running, or the containers haven't finished starting — check Docker Desktop's dashboard for a red/error status. |
| "Port is already allocated" error | Something else on your computer is already using port `9091` or `3307`. Close that other program, or edit the port numbers in `docker-compose.yml`. |
| Site loads but shows a database error | MySQL was still initializing when you opened the page — wait 20–30 seconds and refresh. |
| Changes to `.php` files don't show up | Make sure you edited the files inside the same folder you ran `docker-compose up -d` from — the container mounts that folder directly. |

---

## 🔒 Security & Data Integrity

**Application security**

- All queries use **PDO prepared statements** — no raw string concatenation.
- Passwords are hashed with `password_hash()` / verified with `password_verify()`.
- Every protected page checks `$_SESSION['user_id']` via `includes/auth_check.php`.
- Every state-changing `POST` request is verified against a **CSRF token**
  (`includes/csrf.php`) before anything else runs.
- Delete actions and role-gated write actions are checked **server-side**,
  not just hidden in the UI — a Viewer cannot bypass the UI and submit a
  form directly.
- New staff accounts are only created by an Admin (via the Users page) with
  a hashed temporary password; public self-registration
  (`auth/register.php`) is **disabled by default** and redirects to the
  login page instead of creating an account, gated behind a
  `SELF_REGISTRATION_ENABLED` env var. If ever turned back on, self-
  registered accounts default to **Viewer** (read-only), never a
  write-capable role.
- Uploaded profile photos are validated by MIME type and size before saving.

**Data integrity**

- Every create/update/delete on Categories, Units, Suppliers, Products, and
  Users/profiles is written to `audit_log` inside the same database
  transaction as the change itself, so a mutation and its audit record
  either both commit or both roll back together.
- User audit snapshots use an **explicit field allowlist**, not a
  blocklist — the `password` column is structurally impossible to include
  in any snapshot, for any write path, rather than relying on remembering
  to `unset()` it.
- Stock-mutating operations (Stock In/Out/Adjustment, POS) go through a
  shared `includes/stock.php` built on **atomic guarded `UPDATE`
  statements** — e.g. Stock Out only decrements when
  `current_stock >= qty` in the same statement, and Stock Adjustment uses
  an optimistic lock — rather than a read-then-write, so two simultaneous
  requests can't both succeed against stock that only exists once. A
  losing request detects it via `rowCount() === 0` and fails cleanly
  instead of corrupting the stock count. A database-level
  `CHECK (current_stock >= 0)` constraint backs this up as a last resort.
- POS sale submissions carry a server-side **idempotency token**
  (`idempotency_keys`, enforced by a `UNIQUE` constraint claimed inside the
  sale's own transaction) so a double-click, browser refresh, or retried
  request can't record the same sale twice.
- Reference numbers (`STI-…`, `STO-…`, `DBT-…`, etc.) are generated from a
  `reference_counters` table advanced with `SELECT ... FOR UPDATE` inside
  the caller's transaction, avoiding the race condition of a plain
  `SELECT COUNT(*) + 1` under concurrent requests.
- `customer_debts.balance` and `.status` are database-generated columns
  computed from `total_amount`/`paid_amount`, removing an entire class of
  "cache drifted from the real numbers" bug.

---

## 🚀 Production Readiness / Deployment Considerations

This project currently runs in a **local development environment** —
XAMPP, or the included Docker Compose setup (`docker-compose.yml`, an
Nginx + PHP + MySQL stack under `docker/`). Neither is a production
deployment as-is. If this were to run for the actual shop beyond local
testing, the following would need attention:

**Already in place**

- Admin-triggered **database backup download** (Settings → downloads a
  `.sql` dump of the live database on demand).
- A migrations folder (`database/migrations/`) for evolving an existing
  database's schema without a fresh import.
- Environment-variable-driven DB connection (`config/db.php`) and a
  feature flag (`SELF_REGISTRATION_ENABLED`) — configuration is already
  externalized rather than hardcoded, which production deployment
  builds on rather than needing to retrofit.

**Would need to be added for production**

- **HTTPS** — required in practice, not just recommended: the barcode
  scanner's camera access needs a secure context, and cookies/credentials
  should never travel over plain HTTP outside local development.
- **Production hosting** — a real host/VPS instead of a local XAMPP/Docker
  install, with PHP and MySQL/MariaDB versions pinned deliberately rather
  than whatever a local dev machine happens to have.
- **Automated, scheduled backups with restore testing** — the current
  backup feature is a manual, on-demand export; production needs a
  scheduled job and, just as importantly, a periodically *verified*
  restore, since an untested backup is not a reliable one.
- **Secrets/environment configuration** — DB credentials and any future
  API keys kept out of version control (an env file or host-level
  secrets), not the placeholder values used in local development.
- **Monitoring and logging** — centralized application/error logging and
  basic uptime monitoring; currently errors go to PHP's local error log
  only, which doesn't scale to a real deployment.
- **Secure production configuration** — `display_errors` off, current
  security headers reviewed for a production origin, and the database
  user scoped to only what the app needs rather than a broad/root account.

None of the above is implemented today — they're listed here as the gap
between "runs correctly on a development machine" and "ready to run
unattended for a real shop," not as claims about the current deployment.

---

## 🛣️ Future Roadmap (planned, not implemented)

Everything in this section is a **future idea**, not a committed feature
and not something currently in the codebase. It exists to record direction,
not to imply timeline or certainty.

### Near-term / known gaps

- **Barcode scanning** is currently on Stock In only; extending the same
  pattern to Stock Out and POS is a natural next step.
- **Stock Reports** does not yet have the mobile card-layout treatment that
  Products/Categories/Units/Suppliers/Stock In/Stock Out/Customers already
  have.
- Updated **screenshots** reflecting the current bilingual/POS/Customers UI
  (see the note in the Screenshots section above).

### Business Analytics

- Sales analytics (trends over time, by product/category)
- Gross profit / margin analytics
- Inventory analytics (turnover, dead stock)
- Fast-moving vs. slow-moving product identification
- Debt analytics (aging, collection rate)
- Daily/monthly business summaries

### Remote Owner Management

- An owner-facing dashboard for checking the shop remotely
- Alerts for low stock and overdue customer debts
- Daily business summary (e.g. delivered by email or a similar channel)

### Productization

- Multi-shop / multi-branch support
- Multi-tenant architecture, if this were ever offered to other shops
- Configurable business settings beyond the current exchange rate
- SaaS-style deployment, and subscription/billing, **only if** the project
  ever actually becomes a commercial product — not a current goal

**On technology choices for any of the above:** none of this roadmap
implies a rewrite in a different framework or stack. If a future
requirement genuinely needs something this architecture can't reasonably
provide — real-time push updates at a scale plain polling can't handle, for
example — that would be evaluated against the actual requirement at the
time. Migrating technology because a newer option exists, without a
concrete scalability or maintenance problem driving it, is not the plan.

---

## 👤 Author

Built by **[Pich Chan Thorn]** — BBU, Year 3 Semester 1, Advanced PHP & MySQL.
Class: **Monday**
