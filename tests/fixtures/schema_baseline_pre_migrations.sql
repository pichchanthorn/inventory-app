-- ================================================
-- TEST FIXTURE ONLY - not used by the application, not a real migration.
--
-- Reconstructs the database shape as it existed immediately BEFORE
-- migrations 001-013 were written, by taking database/schema.sql's
-- current table definitions and removing exactly the columns/
-- constraints each of those 13 migrations adds (cross-checked against
-- every migration file's own ALTER/CREATE statements). Only the 7
-- tables any of migrations 001-013 actually touch are included here -
-- app_settings, audit_log, idempotency_keys, reference_counters,
-- customers, customer_debts, and customer_debt_payments don't exist yet
-- at this baseline; migrations 005/007/010/011/012 create them.
--
-- Exists solely so tests/Schema/MigrationIntegrityTest.php can apply
-- migrations 001-013 to a database that actually needs them (every
-- migration file's own header comment says it must run against "an
-- EXISTING database that predates this change" - a truly empty database
-- does not qualify, since these are additive ALTER/CREATE statements
-- against tables that must already exist).
-- ================================================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);
INSERT INTO roles (name) VALUES ('Admin'), ('User'), ('Viewer');

-- Pre-009: no updated_at/created_by/updated_by.
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT DEFAULT 2,
    avatar VARCHAR(255) DEFAULT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Pre-006: unaffected (categories already had created_at).
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Pre-006: no created_at/updated_at/created_by/updated_by.
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    note TEXT
);

-- Pre-006: no created_at/updated_at/created_by/updated_by.
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    email VARCHAR(150),
    address TEXT,
    note TEXT
);

-- Pre-003/004/006/008: no active_ingredient/expiry_date (003), no
-- package_size (004), no updated_at/created_by/updated_by (008).
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL UNIQUE,
    barcode VARCHAR(50),
    category_id INT,
    supplier_id INT,
    unit_id INT,
    note TEXT,
    cost_price DECIMAL(10,2) DEFAULT 0,
    sale_price DECIMAL(10,2) DEFAULT 0,
    min_stock INT DEFAULT 0,
    current_stock INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL,
    CONSTRAINT chk_products_current_stock_nonneg CHECK (current_stock >= 0)
);

-- Pre-001/002: type has no 'sale' value yet, no cash_received column.
CREATE TABLE stock_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    type ENUM('in','out','adjustment') NOT NULL,
    transaction_date DATE NOT NULL,
    note VARCHAR(255),
    supplier_id INT NULL,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transaction_date (transaction_date)
);

-- Unaffected by any migration.
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
