-- ================================================
-- Migration 005: add app_settings (USD->KHR display rate)
--
-- Single-row (id=1) settings table - not a generic config framework,
-- just the minimal shape for this one Admin-configurable value. KHR is
-- calculated at render time from this rate; no other table changes, and
-- nothing here is stored on any transaction.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
--
-- Existing installs get no row until an Admin saves a rate once through
-- Settings - KHR display simply stays hidden until then (additive,
-- fails safe rather than showing a wrong/default rate).
-- ================================================

USE inventory_db;

CREATE TABLE IF NOT EXISTS app_settings (
    id INT NOT NULL DEFAULT 1 PRIMARY KEY,
    usd_to_khr_rate DECIMAL(10,2) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
