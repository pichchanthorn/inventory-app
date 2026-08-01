<img src="assets/bbu-logo.png" width="70" align="right" alt="Build Bright University" />

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
| **Stock Reports** | Overview, full transaction log (filterable), by-product stock levels, CSV export |
| **Profile** | Update name/email, change password, upload a profile photo, view role and member-since date |
| **Theme** | Light/Dark toggle in the sidebar, saved per browser via `localStorage` |

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

`stock_transactions.type` is one of `in` / `out` / `adjustment` — every stock
change (in, out, or manual correction) is logged here for a full audit trail.

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

1. Install **XAMPP** (or a similar Apache + MySQL + PHP stack) and start
   **Apache** and **MySQL**.
2. Copy the `inventory-app` folder into `C:\xampp\htdocs\`.
3. Open `http://localhost/phpmyadmin` → **Import** → select
   `database/schema.sql` → **Go**. This creates the `inventory_db` database
   and all tables.
4. *(Optional)* Import `database/seed.sql` the same way to load sample data.
5. If your MySQL root user has a password, edit `config/db.php` and set `$pass`.
6. Visit `http://localhost/inventory-app/` → **Register** an account → **Log in**.

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
