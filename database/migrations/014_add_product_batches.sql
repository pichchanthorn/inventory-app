-- ================================================
-- Migration 014: Product Batch & Expiry Management - schema foundation
-- (Phase K1)
--
-- Schema only. No Stock In/Out/POS/Adjustment/reporting/UI code reads or
-- writes any of this yet - see the K1 design specification for the full
-- reasoning. This migration exists purely so the database can hold batch/
-- lot/expiry data once a later phase starts writing to it.
--
-- track_batches (products): the opt-in switch. Defaults to 0, so every
-- existing product - and every new product, unless explicitly changed -
-- keeps its current_stock/Stock In/Stock Out/POS/Adjustment behavior
-- completely unaffected. No existing product is enrolled by this
-- migration; no existing data is modified or backfilled.
--
-- product_batches: one row per physical lot. Identity is (product_id,
-- batch_number, expiry_date) - deliberately NOT including cost, so
-- receiving the same physical batch again at a different price never
-- forks a second row for it (the previous draft of this design mistakenly
-- tied batch identity to cost; this migration corrects that). Cost is not
-- a column here at all - it lives entirely in stock_transaction_item_
-- batches below, one immutable row per receiving/consumption event, so a
-- batch's own cost history is preserved exactly rather than overwritten
-- or averaged into a single misleading number.
--
-- qty_received is a cumulative, increase-only ledger of everything ever
-- formally received into this batch (touched only by a future Stock In
-- implementation); qty_on_hand is the maintained live balance (the
-- batch-level analog of products.current_stock's own caching pattern),
-- touched by Stock In, Stock Out/Sale, and Stock Adjustment alike - it is
-- therefore not bounded above by qty_received (an upward Adjustment can
-- raise it without qty_received changing), so no CHECK relates the two.
--
-- origin + source_transaction_id distinguish a batch created by a real
-- Stock In event ('stock_in', source_transaction_id set) from the one
-- create-once-per-product placeholder a future opt-in migration flow
-- creates when track_batches is switched on for a product with existing,
-- pre-batch-tracking stock ('opening_balance', source_transaction_id
-- NULL - there is no real Stock In to point at). This is provenance
-- metadata only, never a fabricated physical batch_number or expiry_date
-- for stock this database has no real batch history for - this migration
-- itself creates zero product_batches rows; nothing is backfilled.
--
-- uq_product_batches_identity is defense-in-depth only, not the primary
-- concurrency mechanism: MySQL/MariaDB treats every NULL as distinct
-- under a UNIQUE index, so it provides no protection whenever
-- batch_number or expiry_date is NULL (the common case for an unlabeled
-- delivery or an unknown expiry). The actual merge-vs-create decision
-- must be made application-side via SELECT ... FOR UPDATE with NULL-safe
-- (<=>) comparison inside the caller's own transaction - the same "row
-- lock carries the concurrency guarantee" principle nextReferenceSequence()
-- already uses (includes/stock.php) - not implemented by this migration.
--
-- stock_transaction_item_batches: one row per receiving-into-a-batch or
-- consumption-from-a-batch event, linked to the specific stock_
-- transaction_items line that caused it. stock_transaction_items itself
-- is intentionally unchanged (no batch_id column) so a 'sale'/'out' line
-- can split across more than one batch without adding columns that would
-- be meaningless for the common, non-batch-tracked case. Direction
-- (received vs. consumed) is inferred from the parent stock_transactions.
-- type, not stored here. unit_cost exists as the historical-cost field
-- this ledger is FOR, but this migration adds only the column - no
-- application logic here or elsewhere populates it yet (no weighted-
-- average consumption cost, no COGS/full accounting - explicitly deferred
-- to a later phase).
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include all of
-- this.
-- ================================================

USE inventory_db;

ALTER TABLE products
    ADD COLUMN track_batches TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date;

CREATE TABLE IF NOT EXISTS product_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    batch_number VARCHAR(60) NULL,
    expiry_date DATE NULL,
    qty_received INT NOT NULL DEFAULT 0,
    qty_on_hand INT NOT NULL DEFAULT 0,
    origin ENUM('stock_in','opening_balance') NOT NULL DEFAULT 'stock_in',
    source_transaction_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (source_transaction_id) REFERENCES stock_transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_product_batches_identity (product_id, batch_number, expiry_date),
    INDEX idx_product_expiry (product_id, expiry_date),
    CONSTRAINT chk_product_batches_qty_on_hand_nonneg CHECK (qty_on_hand >= 0),
    CONSTRAINT chk_product_batches_qty_received_nonneg CHECK (qty_received >= 0)
);

CREATE TABLE IF NOT EXISTS stock_transaction_item_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_item_id INT NOT NULL,
    batch_id INT NOT NULL,
    qty INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_item_id) REFERENCES stock_transaction_items(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES product_batches(id),
    CONSTRAINT chk_stock_transaction_item_batches_qty_positive CHECK (qty > 0),
    CONSTRAINT chk_stock_transaction_item_batches_unit_cost_nonneg CHECK (unit_cost >= 0)
);
