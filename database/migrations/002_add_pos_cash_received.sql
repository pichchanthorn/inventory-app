-- ================================================
-- Migration 002: add cash_received to stock_transactions
--
-- Persists the cash amount tendered for a POS sale, so past receipts
-- can show what a customer paid / was given in change (previously
-- display-only, per Phase D's original MVP trade-off). change_due is
-- deliberately NOT stored - it's derived at read time as
-- (SUM(line subtotals) - cash_received), the same way every other
-- total in this app is computed from line items rather than cached.
--
-- NULL for every existing row (in/out/adjustment always; sale rows
-- created before this migration too, since that data was never
-- captured) - the UI must treat NULL as "not recorded," not $0.00.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE stock_transactions
    ADD COLUMN cash_received DECIMAL(10,2) NULL DEFAULT NULL AFTER note,
    ADD CONSTRAINT chk_stock_transactions_cash_received_nonneg CHECK (cash_received IS NULL OR cash_received >= 0);
