-- ================================================
-- Migration 015: Low Stock Alert / Reorder Management (Phase L1) -
-- schema addition only.
--
-- Low-stock DETECTION already existed before this migration -
-- products.min_stock (in the base schema since before this project's
-- migration history began) plus the current_stock <= min_stock
-- comparison already used by dashboard.php's KPI, product/index.php's
-- ?filter=low_stock and per-row badge, and stock-report/index.php's By
-- Product tab. This migration adds only the one thing that was
-- genuinely missing: a place to record how much to reorder once a
-- product is flagged.
--
-- reorder_quantity is purely informational/suggestive - a shop owner's
-- own habitual restock amount - never enforced and never read by any
-- Stock In/Out/POS/Adjustment logic. It must never affect whether a
-- product is classified CRITICAL/LOW/NORMAL (see includes/stock_alert.php);
-- it only describes what to do once a product already IS flagged by the
-- existing current_stock <= min_stock condition.
--
-- Nullable, not NOT NULL DEFAULT 0: a reorder quantity of 0 would be a
-- meaningless "reorder zero units" instruction, whereas NULL cleanly
-- means "no suggested quantity configured yet" - the same
-- nullable-means-not-applicable convention product_batches.batch_number/
-- expiry_date/source_transaction_id already use (see migration 014).
--
-- The CHECK constraint follows the same "application code is the
-- primary guard, this is just a backstop" spirit as
-- chk_products_current_stock_nonneg and every other CHECK in this
-- schema - enforced on MySQL 8.0.16+/MariaDB 10.2.1+, silently
-- unenforced on an older engine, no regression either way.
--
-- Also adds the equivalent non-negative CHECK to the existing min_stock
-- column, which had none - a pre-existing gap, not introduced by this
-- migration, closed here because this migration is already touching
-- this exact area of the products table. Verified safe against the
-- current database before this migration was written:
-- SELECT COUNT(*) FROM products WHERE min_stock < 0 returned 0.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include this
-- column and both CHECK constraints.
-- ================================================

USE inventory_db;

ALTER TABLE products
    ADD COLUMN reorder_quantity INT NULL DEFAULT NULL AFTER min_stock,
    ADD CONSTRAINT chk_products_reorder_quantity_nonneg CHECK (reorder_quantity IS NULL OR reorder_quantity >= 0),
    ADD CONSTRAINT chk_products_min_stock_nonneg CHECK (min_stock >= 0);
