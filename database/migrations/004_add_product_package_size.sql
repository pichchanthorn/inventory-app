-- ================================================
-- Migration 004: add package_size to products
--
-- What one unit actually contains (e.g. "50kg", "500ml") - a product
-- attribute, distinct from unit_id (what it's sold AS: bag/bottle/
-- packet). Nullable free-text; existing rows simply leave it empty.
-- Display-only - not used in any stock-mutation calculation.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE products
    ADD COLUMN package_size VARCHAR(50) DEFAULT NULL AFTER unit_id;
