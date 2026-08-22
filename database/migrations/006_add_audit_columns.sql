-- ================================================
-- Migration 006: add created_at/updated_at/created_by/updated_by to
-- categories, units, suppliers
--
-- Scoped to exactly the 3 tables wired into audit logging in this
-- batch (Categories/Units/Suppliers) - Products and Users get their own
-- created_by/updated_by columns via their own later migrations when
-- their audit-logging batches land, not here.
--
-- categories already has created_at (unaffected); units and suppliers
-- don't, so they pick up all four columns here.
--
-- created_by/updated_by are nullable - NULL on every row that predates
-- this column (unknown, not a guess) and ON DELETE SET NULL so losing
-- attribution can never block or cascade-delete the row itself.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE categories
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN created_by INT NULL AFTER updated_at,
    ADD COLUMN updated_by INT NULL AFTER created_by,
    ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE units
    ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN created_by INT NULL AFTER updated_at,
    ADD COLUMN updated_by INT NULL AFTER created_by,
    ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE suppliers
    ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN created_by INT NULL AFTER updated_at,
    ADD COLUMN updated_by INT NULL AFTER created_by,
    ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
