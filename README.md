# Inventory Management System — Starter (Advanced PHP & MySQL)

This is a working starter that follows the same modules the teacher demoed:
Login/Register → Dashboard → Categories → Units → Suppliers → Products →
Stock In → Stock Out → Stock Adjustments → Reports.

**Design:** a dark, terminal/scanner-inspired look (own visual identity — not
a copy of the classroom demo's styling). Tokens live in `assets/style.css`.

**Sample scope:** the schema ships with no seed rows for categories/units/
suppliers/products — add your own when you first run it, so the data in
your submission is yours, not the classroom demo's.

**What's fully built:** Login, Register, Logout, Dashboard, Profile (with
photo upload), and a complete **Categories** module (list, search, create,
edit, delete) — this is your template for every other module.

Note: the `uploads/avatars/` folder must be writable by PHP (on most local
setups like XAMPP/Laragon this works out of the box).

## 1. Setup
1. Install XAMPP or Laragon, start Apache + MySQL.
2. Put this whole `inventory-app` folder inside `htdocs` (XAMPP) or `www` (Laragon).
3. Open phpMyAdmin → Import → select `database/schema.sql`. This creates the
   `inventory_db` database and all tables (categories, units, suppliers,
   products, stock_transactions, stock_transaction_items, users, roles).
4. If your MySQL root user has a password, edit `config/db.php` and set `$pass`.
5. Visit `http://localhost/inventory-app/` in your browser → Register an
   account → Log in.

## 2. How to build the remaining modules
Copy `category/index.php` into a new folder (e.g. `unit/index.php`,
`supplier/index.php`, `product/index.php`) and change 3 things:
1. The table name in the 4 SQL queries (`categories` → `units`, etc.)
2. The column names in the form fields to match that table
3. `$activePage = 'category'` → `$activePage = 'unit'` (so the sidebar
   highlights the right link)

That's the entire pattern: **list (SELECT) → create (INSERT) → edit
(UPDATE) → delete (DELETE)**, all through the same `$pdo->prepare(...)`
style so you're protected from SQL injection.

## 3. Stock In / Stock Out / Adjustments (next step, more advanced)
These are one level up from plain CRUD because one form submission writes
to **two tables at once** inside a transaction:
```php
$pdo->beginTransaction();
$stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,?,?)');
$stmt->execute([$reference, 'in', $date, $note, $supplierId, $_SESSION['user_id']]);
$transactionId = $pdo->lastInsertId();

foreach ($items as $item) {
    $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,?,?)');
    $stmt->execute([$transactionId, $item['product_id'], $item['qty'], $item['unit_price'], $item['qty'] * $item['unit_price']]);

    // Stock In increases stock, Stock Out decreases it
    $pdo->prepare('UPDATE products SET current_stock = current_stock + ? WHERE id = ?')
        ->execute([$item['qty'], $item['product_id']]);
}
$pdo->commit();
```
Use `current_stock - ?` for Stock Out, and a direct `SET current_stock = ?`
for Adjustments.

## 4. Roles / Permissions (later step)
`users.role_id` already links to the `roles` table (Admin/User/Viewer).
A simple way to gate a page:
```php
if ($_SESSION['role_id'] != 1) { // 1 = Admin
    die('You do not have permission to view this page.');
}
```
