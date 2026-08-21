<img src="assets/bbu-logo.png" width="70" align="right" alt="Build Bright University" />

**🌐 Language:** English · [ភាសាខ្មែរ](README.km.md)

# 📦 Inventory Management System

**University:** Build Bright University (BBU)
**Course:** Advanced PHP & MySQL
**Stack:** PHP (PDO) · MySQL · Bootstrap 5 · Vanilla JS

A full-stack inventory management system for tracking products, suppliers,
and stock movements — built with plain PHP and MySQL (no framework), using
prepared statements throughout for SQL-injection safety.

---

## ✨ Features

| Module | What it does |
|---|---|
| **Auth** | Register, login (hashed passwords), logout, session-based access control |
| **Roles** | Admin / User / Viewer — delete actions across Categories, Units, Suppliers, and Products are Admin-only |
| **User management** | Admin-only page to create staff accounts (temporary password, chosen role), and change any user's role |
| **Forced password reset** | Admin-created accounts can require a password change on first login before the rest of the app is accessible |
| **Dashboard** | Live totals: products, units in stock, inventory value, low-stock alerts |
| **Categories** | Full CRUD with search |
| **Units** | Full CRUD with search |
| **Suppliers** | Full CRUD with search (phone, email, address) |
| **Products** | Full CRUD — linked to category/supplier/unit, auto-calculated margin %, low-stock badge |
| **Stock In** | Multi-line receiving form; increases stock and logs a transaction inside a DB transaction |
| **Stock Out** | Multi-line issuing form; decreases stock with an availability check |
| **Stock Adjustments** | Sets an exact stock count with a required reason (for physical counts / corrections) |
| **Point of Sale (POS)** | Cart-based checkout — add products, cash received / change due, records a `sale` transaction (same stock-safety guarantees as Stock Out), printable receipt |
| **Stock Reports** | Overview, full transaction log (filterable), by-product stock levels, CSV export — includes POS sales alongside Stock In/Out/Adjustments |
| **Profile** | Update name/email, change password, upload a profile photo, view role and member-since date |
| **Theme** | Light/Dark toggle in the sidebar, saved per browser via `localStorage` |
| **Localization** | Full bilingual English/Khmer interface — sidebar, forms, errors, dates, and toasts all switch instantly via a session-based language toggle |

---

## 🖼️ Screenshots

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
roles              (id, name)
users              (id, name, email, password, role_id, avatar,
                     must_change_password, created_at)
categories         (id, name, slug, note, created_at)
units              (id, name, note)
suppliers          (id, name, phone, email, address, note)
products           (id, name, sku, barcode, category_id, supplier_id, unit_id,
                     note, cost_price, sale_price, min_stock, current_stock, created_at)
stock_transactions       (id, reference, type, transaction_date, note, supplier_id, user_id, created_at)
stock_transaction_items  (id, transaction_id, product_id, qty, unit_price, subtotal)
```

`stock_transactions.type` is one of `in` / `out` / `adjustment` / `sale` — every
stock change (in, out, manual correction, or a POS sale) is logged here for a
full audit trail.

> **Existing installations:** if your database was set up before
> `database/schema.sql` included the `sale` transaction type (needed by the
> Point of Sale module), run
> `database/migrations/001_add_sale_transaction_type.sql` once against it.
> Fresh installs using the current `schema.sql` already include it.

---

## 📂 Project Structure

```
inventory-app/
├── auth/                 Login, register, logout
├── category/             Categories CRUD
├── unit/                 Units CRUD
├── supplier/             Suppliers CRUD
├── product/               Products CRUD
├── stock-in/             Stock In form + logic
├── stock-out/            Stock Out form + logic
├── stock-adjustment/     Stock Adjustments form + logic
├── stock-report/         Reports (overview / log / by-product) + CSV export
├── user/                 Admin-only user management (create staff, change roles)
├── includes/             Shared header, footer, auth guard
├── config/                DB connection + base-URL helper
├── database/             schema.sql (tables) + seed.sql (sample data)
├── assets/               style.css (design system)
├── uploads/avatars/      Profile photo uploads
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
   `database/seed.sql` to load some sample categories, suppliers, and
   products, so the app isn't completely empty when you first log in.

**Step 4 — Adjust the database password (only if needed)**

Most fresh XAMPP installs have **no password** on the MySQL `root` user, so
you can usually skip this step. If phpMyAdmin asked you for a password when
you logged in, open `config/db.php` in a text editor and change this line:

```php
$pass    = getenv('DB_PASSWORD') ?: '';   // put your MySQL password between the quotes
```

**Step 5 — Open the app**

1. Go to `http://localhost/inventory-app/` in your browser.
2. Click **Register** and create your first account.
3. Log in with that account and start exploring.

> **Note:** the very first account you register through the public
> **Register** page is a regular **User**, not an **Admin**. To unlock
> Admin-only features (like the Users page), open phpMyAdmin, go to the
> `users` table, find your row, and change `role_id` to `1` (Admin).

**XAMPP Troubleshooting**

| Problem | Likely cause & fix |
|---|---|
| Apache row turns red / won't start | Something else on your computer (often Skype, IIS, or another web server) is already using port 80. Close it, or change Apache's port in XAMPP's config. |
| MySQL row turns red / won't start | Another MySQL/MariaDB service (e.g. from Laragon or WAMP) is already running. Stop that other service first, then start XAMPP's MySQL. |
| Page shows "Database connection failed" | MySQL isn't running — go back to XAMPP Control Panel and check it's green. |
| Blank white page | Open XAMPP Control Panel → Apache row → **Logs** → **PHP error log**, to see the actual error message. |

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

**Step 4 — Open the app**

1. Go to `http://localhost:9091` in your browser.
2. Click **Register** and create your first account.
3. Log in with that account and start exploring.

> **Note:** just like with XAMPP, the first account you register is a
> regular **User**. To make yourself an Admin, you'll need a MySQL client
> (like phpMyAdmin, TablePlus, or DBeaver) connected to `127.0.0.1:3307`
> (see `docker-compose.yml`), user `root`, password `1234` — then update
> `role_id` to `1` in the `users` table. If you have `database/seed.sql`
> in the project, it may already include a ready-made Admin account —
> check that file first before doing this manually.

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

## 🔒 Security notes

- All queries use **PDO prepared statements** — no raw string concatenation.
- Passwords are hashed with `password_hash()` / verified with `password_verify()`.
- Every protected page checks `$_SESSION['user_id']` via `includes/auth_check.php`.
- Delete actions (Categories, Units, Suppliers, Products) are gated server-side
  by role, not just hidden in the UI — an `isAdmin()` check runs before every
  delete query, so a non-admin can't delete by guessing the URL.
- New staff accounts are only created by an Admin (via the Users page) with a
  hashed temporary password; public self-registration (`auth/register.php`)
  is still open for now but should be restricted once Admin-created accounts
  are in use.
- Uploaded profile photos are validated by MIME type and size before saving.

---

## 👤 Author

Built by **[Pich Chan Thorn]** — BBU, Year 3 Semester 1, Advanced PHP & MySQL.
Class: **Monday**
