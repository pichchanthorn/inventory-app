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
