-- ================================================
-- Migration 010: add customer debt/credit tracking
--
-- Schema only - no application code, UI, or audit-log wiring in this
-- batch (POS integration, a Customers page, a Dashboard card, and
-- audit_log wiring are separate, later batches). See the per-table
-- comments below for the reasoning behind each design decision.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

-- Customers (credit/debt-tracking counterparties - farmers who buy on
-- credit and pay later). Structurally mirrors suppliers (name/phone/
-- address/note + audit columns) since a customer is the same kind of
-- "counterparty" entity, just on the sales side rather than the
-- purchasing side. No email column - unlike suppliers (B2B
-- correspondence), rural farmer customers realistically won't have
-- one; trivial to add later via a further migration if that changes.
CREATE TABLE IF NOT EXISTS customers (
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
CREATE TABLE IF NOT EXISTS customer_debts (
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
CREATE TABLE IF NOT EXISTS customer_debt_payments (
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
