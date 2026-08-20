-- ================================================
-- Migration 001: add 'sale' to stock_transactions.type
--
-- Prerequisite for a future POS module — not part of this migration.
-- Existing rows and their current type values ('in'/'out'/'adjustment')
-- are preserved; this only extends the set of allowed values.
--
-- Run against an EXISTING database that was set up before this change.
-- Fresh installs using the current database/schema.sql already include
-- 'sale' and do not need this file.
-- ================================================

USE inventory_db;

ALTER TABLE stock_transactions
    MODIFY COLUMN type ENUM('in','out','adjustment','sale') NOT NULL;
