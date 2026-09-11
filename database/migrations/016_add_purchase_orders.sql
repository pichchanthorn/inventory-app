-- ================================================
-- Migration 016: Purchase / Supplier Order Management (Phase P1) -
-- Draft Purchase Order schema core.
--
-- Scope is strictly "schema + draft-only PO core" per the approved P1
-- audit/design. purchase_orders.status carries the FULL lifecycle enum
-- (draft/ordered/partially_received/received/cancelled) even though P1
-- application code only ever writes 'draft' - adding the full enum now
-- avoids a later destructive ALTER ... MODIFY ENUM in P2/P3.
-- purchase_order_items.received_qty exists now, defaulted 0, for the
-- identical reason: P3's receiving flow needs the column already
-- present, not retrofitted.
--
-- purchase_order_receipts (the future PO-item <-> stock_transaction_item
-- linkage table) is explicitly NOT created here - it belongs to P3, once
-- the receiving transaction shape it must support actually exists to
-- validate its design against. Creating it now would be pure schema
-- with zero P1 code ever writing to it.
--
-- Reference numbering reuses the existing reference_counters mechanism
-- (migration 012) via a NEW, independent counter key 'purchase_orders' -
-- deliberately not shared with 'stock_transactions' (which already
-- backs STI/STO/ADJ/SAL only because those are literally
-- stock_transactions rows; a PO is a structurally different entity).
-- nextReferenceSequence($pdo, 'purchase_orders') (includes/stock.php)
-- is reused unmodified - no second reference-generation mechanism.
--
-- supplier_id is NOT NULL here, unlike stock_transactions.supplier_id
-- (nullable there only because Stock In predates suppliers being
-- mandatory for a direct receipt) - a PO is inherently about exactly
-- one supplier.
--
-- purchase_order_items.product_id has no ON DELETE clause (defaults to
-- RESTRICT), matching stock_transaction_items.product_id exactly: a
-- product with PO history must not be deletable out from under it -
-- the existing 1451-catch convention (see product/index.php's delete
-- handler) covers this the same way it already covers Stock In/Out/
-- Adjustment/Sale history.
--
-- subtotal is a GENERATED ALWAYS AS (ordered_qty * unit_cost) STORED
-- column, not application-maintained - MariaDB has supported STORED
-- generated columns since 10.2, and this exact engine (10.11.14, this
-- environment's current version) already runs one successfully today
-- (customer_debts.balance/.status, database/schema.sql) - no new
-- compatibility concern. A generated column removes any risk of
-- subtotal drifting from ordered_qty*unit_cost on a future edit path,
-- since MariaDB recomputes it automatically on every row write. The PO
-- header total is never stored - always SUM(purchase_order_items.
-- subtotal), derived at read time, the same "store the line, derive the
-- aggregate" pattern stock_transaction_items.subtotal / stock_
-- transactions' own (absent) total column already establishes.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include these
-- two tables and the seeded counter row.
-- ================================================

USE inventory_db;

CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,
    status ENUM('draft','ordered','partially_received','received','cancelled')
        NOT NULL DEFAULT 'draft',
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_supplier_status (supplier_id, status),
    INDEX idx_status_order_date (status, order_date)
);

CREATE TABLE purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    ordered_qty INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) GENERATED ALWAYS AS (ordered_qty * unit_cost) STORED,
    received_qty INT NOT NULL DEFAULT 0,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_purchase_order (purchase_order_id),
    CONSTRAINT chk_po_items_ordered_qty_positive CHECK (ordered_qty > 0),
    CONSTRAINT chk_po_items_unit_cost_nonneg CHECK (unit_cost >= 0),
    CONSTRAINT chk_po_items_received_qty_nonneg CHECK (received_qty >= 0),
    CONSTRAINT chk_po_items_received_not_over_ordered CHECK (received_qty <= ordered_qty)
);

INSERT IGNORE INTO reference_counters (counter_key, next_value)
SELECT 'purchase_orders', COUNT(*) + 1 FROM purchase_orders;
