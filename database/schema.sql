-- ================================================
-- Inventory Management System - Database Schema
-- Course: Advanced PHP and MySQL
-- ================================================

CREATE DATABASE IF NOT EXISTS inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_db;

-- Roles (used for permission levels: Admin / User / Viewer)
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);

INSERT INTO roles (name) VALUES ('Admin'), ('User'), ('Viewer');

-- Users (login accounts)
-- created_by/updated_by: same nullable/ON DELETE SET NULL pattern as
-- categories/units/suppliers/products - see those tables' comments
-- above for why losing attribution must never block or cascade a user
-- delete. Self-referential (users.created_by -> users.id) since this
-- is the users table itself - MySQL allows this without issue.
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT DEFAULT 2,
    avatar VARCHAR(255) DEFAULT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Categories (Laptop, PC, TV, ...)
-- created_by/updated_by are nullable FKs to users(id) - NULL on existing
-- rows predating this column (unknown, not a guess) and on any future
-- row saved by code that doesn't set them. ON DELETE SET NULL rather
-- than blocking a user delete or cascading: losing the attribution
-- ("who" made a change) must never be allowed to block or destroy the
-- change record itself - see audit_log below for the actual history.
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Units (pcs, box, ream, kg, ltr, btr, set ...)
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Suppliers
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    email VARCHAR(150),
    address TEXT,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Products
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL UNIQUE,
    barcode VARCHAR(50),
    category_id INT,
    supplier_id INT,
    unit_id INT,
    -- What one unit actually contains (e.g. "50kg", "500ml") - a product
    -- attribute, distinct from unit_id (what it's sold AS: bag/bottle/
    -- packet). Nullable and free-text since it's descriptive, not used in
    -- any calculation.
    package_size VARCHAR(50) DEFAULT NULL,
    note TEXT,
    -- Agrochemical-specific, both nullable so non-agrochemical rows (or
    -- products where it doesn't apply, e.g. plain organic fertilizer)
    -- simply leave them empty. No CHECK constraint on expiry_date - stock
    -- that has already expired must still be recordable, that's the point
    -- of tracking it.
    active_ingredient VARCHAR(150) DEFAULT NULL,
    expiry_date DATE DEFAULT NULL,
    cost_price DECIMAL(10,2) DEFAULT 0,
    sale_price DECIMAL(10,2) DEFAULT 0,
    min_stock INT DEFAULT 0,
    current_stock INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- created_by/updated_by: same nullable/ON DELETE SET NULL pattern as
    -- categories/units/suppliers - see those tables' comment above for
    -- why losing attribution must never block or cascade a user delete.
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    -- Final safety net: application code (includes/stock.php) is the
    -- primary guard against negative stock via atomic/optimistic-locked
    -- UPDATEs; this CHECK just ensures the invariant holds even if some
    -- future code path forgets to use it. Enforced on MySQL 8.0.16+ and
    -- MariaDB 10.2.1+ (this project's Docker image is MySQL 8.4.10); on
    -- an older MySQL it is parsed but silently not enforced, same as not
    -- having it at all — no regression either way.
    CONSTRAINT chk_products_current_stock_nonneg CHECK (current_stock >= 0)
);

-- Stock transactions (Stock In / Stock Out / Adjustment / Sale headers)
CREATE TABLE stock_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    type ENUM('in','out','adjustment','sale') NOT NULL,
    transaction_date DATE NOT NULL,
    note VARCHAR(255),
    -- Cash tendered for a POS sale; NULL for every non-'sale' row, and for
    -- 'sale' rows created before this column existed (never captured, so
    -- there is nothing to backfill - the UI must show "not recorded" for
    -- those, not $0.00). change_due is deliberately not stored - it's
    -- derived at read time as (SUM(line subtotals) - cash_received), the
    -- same way every other total in this app comes from line items rather
    -- than a cached column.
    cash_received DECIMAL(10,2) NULL DEFAULT NULL,
    supplier_id INT NULL,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transaction_date (transaction_date),
    -- Defense-in-depth, same spirit as chk_products_current_stock_nonneg -
    -- the application (POS's cash_received < total rejection) already
    -- guarantees this in practice; this is just a backstop.
    CONSTRAINT chk_stock_transactions_cash_received_nonneg CHECK (cash_received IS NULL OR cash_received >= 0)
);

-- Stock transaction line items
CREATE TABLE stock_transaction_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (transaction_id) REFERENCES stock_transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Customers (credit/debt-tracking counterparties - farmers who buy on
-- credit and pay later). Structurally mirrors suppliers (name/phone/
-- address/note + audit columns) since a customer is the same kind of
-- "counterparty" entity, just on the sales side rather than the
-- purchasing side. No email column - unlike suppliers (B2B
-- correspondence), rural farmer customers realistically won't have
-- one; trivial to add later via a migration if that changes.
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    address TEXT,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Customer debts (one row per credit sale - the "IOU" header).
--
-- stock_transaction_id links to the actual sale (stock_transactions.
-- type='sale') that moved the product out of stock - a credit sale is
-- not a different kind of stock movement, just a payment-timing
-- difference layered on top of the existing sale flow, so no new
-- stock_transactions.type value was added here. Nullable and UNIQUE:
-- nullable to allow a manually-entered opening balance (a debt that
-- predates this feature, or was never a POS sale to begin with, so
-- there's no stock transaction to link to); UNIQUE so a real sale can
-- never be double-linked to two separate debt rows.
--
-- total_amount is stored rather than derived from
-- stock_transaction_items, because stock_transaction_id can be NULL -
-- there may be no line items at all to sum for an opening-balance
-- debt. paid_amount is a running cache updated by future application
-- code as payments are recorded (see customer_debt_payments below) -
-- the same "cache it, don't recompute from full history on every
-- read" philosophy already used for products.current_stock, since
-- balance/status here are read far more often (every POS credit-sale
-- check, every customer list row, a future dashboard card) than
-- written (a handful of payments over a debt's lifetime).
--
-- balance and status are GENERATED ALWAYS ... STORED columns rather
-- than plain cached columns like current_stock, though: unlike
-- current_stock, which aggregates many stock_transaction_items rows
-- over time and structurally can't be a generated column, balance/
-- status here are pure functions of this same row's own total_amount/
-- paid_amount - making them generated columns removes the entire
-- "forgot to keep the cache in sync" bug class for free, while still
-- being indexable for fast filtering.
--
-- customer_id deliberately has NO "ON DELETE SET NULL" (unlike
-- categories/suppliers on products) - a debt losing its customer link
-- would mean "who owes this money?" becomes unanswerable, which must
-- never be allowed to happen silently. Default RESTRICT (no ON DELETE
-- clause) blocks deleting a customer who has any debt rows at all.
CREATE TABLE customer_debts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    stock_transaction_id INT NULL UNIQUE,
    total_amount DECIMAL(10,2) NOT NULL,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    balance DECIMAL(10,2) GENERATED ALWAYS AS (total_amount - paid_amount) STORED,
    status ENUM('open','partially_paid','paid') GENERATED ALWAYS AS (
        CASE WHEN paid_amount <= 0 THEN 'open'
             WHEN paid_amount >= total_amount THEN 'paid'
             ELSE 'partially_paid' END
    ) STORED,
    -- Approximate/optional - farmers often can't commit to an exact
    -- date, just a rough expectation ("after harvest"). A NULL due_date
    -- naturally never matches "overdue" (due_date < CURDATE()), so no
    -- special-casing is needed anywhere that queries for overdue debts.
    due_date DATE NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (stock_transaction_id) REFERENCES stock_transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    -- Serves "how much does customer X owe right now" (WHERE
    -- customer_id=? AND status!='paid') and general per-customer debt
    -- listing.
    INDEX idx_customer_status (customer_id, status),
    -- Serves "which debts are overdue" (WHERE status!='paid' AND
    -- due_date < CURDATE()).
    INDEX idx_status_due_date (status, due_date),
    -- Defense-in-depth, same spirit as products'
    -- chk_products_current_stock_nonneg - future application code
    -- (not written in this batch) is the primary guard against an
    -- overpayment via a guarded UPDATE, same pattern as
    -- includes/stock.php; this is just a backstop.
    CONSTRAINT chk_customer_debts_paid_amount_range CHECK (paid_amount >= 0 AND paid_amount <= total_amount),
    CONSTRAINT chk_customer_debts_total_amount_positive CHECK (total_amount > 0)
);

-- Customer debt payments (partial-payment ledger, one row per
-- installment) - append-only history under a customer_debts row, the
-- same parent/child relationship as stock_transactions/
-- stock_transaction_items, and named to match that convention.
--
-- No updated_at/updated_by - like stock_transaction_items, a recorded
-- payment is never edited after the fact, only ever appended.
-- payment_date is separate from created_at, mirroring
-- stock_transactions.transaction_date vs created_at (the
-- business-relevant date a payment was actually made, vs. the row's
-- creation timestamp).
--
-- debt_id has no ON DELETE clause (default RESTRICT), same reasoning
-- as customer_debts.customer_id above - a debt with payment history
-- must never be allowed to lose that history.
CREATE TABLE customer_debt_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    debt_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    FOREIGN KEY (debt_id) REFERENCES customer_debts(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_debt (debt_id),
    CONSTRAINT chk_customer_debt_payments_amount_positive CHECK (amount > 0)
);

-- App-wide settings. Currently just the USD->KHR display rate - not a
-- generic key-value config framework, just the minimal shape this one
-- Admin-configurable value needs. Always exactly one row (id=1); the app
-- always reads/writes WHERE id=1 rather than enforcing singularity with
-- a CHECK, same "trust the one call site" spirit as the rest of this
-- schema. KHR is calculated at render time from this rate and is never
-- stored on any transaction - underlying data stays USD-only.
CREATE TABLE app_settings (
    id INT NOT NULL DEFAULT 1 PRIMARY KEY,
    usd_to_khr_rate DECIMAL(10,2) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Accountability Foundation: append-only audit trail for CREATE/UPDATE/
-- DELETE on the catalog/config tables (Products and Users are separate,
-- later batches; stock_transactions is out of scope entirely - it's
-- already append-only/permanent by design, nothing to audit there).
--
-- entity_id deliberately has NO foreign key: one column can't reference
-- five different tables, and a 'delete' row's entity_id must remain
-- valid (and meaningful) after the referenced row is gone - that's the
-- whole point of that row existing. entity_type + entity_id together
-- identify what changed; the application is the only thing that
-- enforces which (type, id) pairs are valid, same as any polymorphic
-- reference.
--
-- before_snapshot/after_snapshot are entity-level (the whole row as the
-- app read/wrote it), not field-level diffs - Phase 1 scope. Snapshots
-- must never contain users.password - that redaction is the caller's
-- responsibility (see includes/audit.php), not enforced by this table.
--
-- CRITICAL: this table must never become editable through the app, not
-- even for Admin - no UPDATE/DELETE code path is expected to exist
-- against it, ever. It is written to (via includes/audit.php) and read
-- from (the Admin-only audit log page) - nothing else.
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action ENUM('create','update','delete') NOT NULL,
    entity_type VARCHAR(30) NOT NULL,
    entity_id INT NOT NULL,
    before_snapshot JSON NULL,
    after_snapshot JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
);

-- Server-side idempotency for POS sale submissions (Phase I2-B1). A
-- UNIQUE constraint on `token` is the entire mechanism - claiming a
-- token is one INSERT, done as the first statement inside
-- recordStockOut()/recordCreditSale()'s own transaction (see
-- includes/stock.php's claimIdempotencyToken()), so InnoDB's own
-- uniqueness enforcement under the row lock is what makes the claim
-- atomic across genuinely concurrent requests - the same "let the
-- database's own constraint do the concurrency-safety work" principle
-- already used by the guarded UPDATEs elsewhere in that file. Because
-- the claim shares the sale's own transaction, a failed/rolled-back
-- attempt takes its claim back out with it, leaving the token claimable
-- again for a legitimate retry; only a committed sale permanently
-- consumes it. No expiry/cleanup job - this grows at the same rate as
-- stock_transactions itself, which nothing in this schema prunes either.
CREATE TABLE idempotency_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Concurrency-safe backing store for reference-number generation (Phase
-- I3-A). One row per counter, advanced via SELECT ... FOR UPDATE +
-- UPDATE inside the caller's own transaction (see includes/stock.php's
-- nextReferenceSequence()) rather than the old SELECT COUNT(*) + 1,
-- which raced under concurrent requests. 'stock_transactions' backs the
-- STI/STO/ADJ/SAL prefixes (one shared counter, matching
-- nextStockReference()'s original un-filtered COUNT(*) FROM
-- stock_transactions), 'customer_debts' backs DBT. Seeded at 1 for both
-- here since a fresh install has no existing rows to count yet - see
-- database/migrations/012_add_reference_counters.sql for the equivalent
-- seed against an existing, already-populated database.
CREATE TABLE reference_counters (
    counter_key VARCHAR(30) NOT NULL PRIMARY KEY,
    next_value INT NOT NULL
);

INSERT INTO reference_counters (counter_key, next_value) VALUES
    ('stock_transactions', 1),
    ('customer_debts', 1);
