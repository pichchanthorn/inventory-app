-- ================================================
-- Migration 003: add agrochemical-specific fields to products
--
-- Adds active_ingredient and expiry_date to products, for the fertilizer
-- (ជី) and rice pesticide (ថ្នាំបាញ់ស្រូវ) catalog. Both nullable - existing
-- rows simply leave them empty, and non-agrochemical products (or ones
-- where a field doesn't apply, e.g. plain organic fertilizer with no
-- single active ingredient) can leave them NULL too.
--
-- No CHECK constraint on expiry_date: stock that has already expired
-- must still be recordable, that's the point of tracking it.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE products
    ADD COLUMN active_ingredient VARCHAR(150) DEFAULT NULL AFTER note,
    ADD COLUMN expiry_date DATE DEFAULT NULL AFTER active_ingredient;
