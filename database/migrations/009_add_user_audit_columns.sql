-- ================================================
-- Migration 009: add updated_at/created_by/updated_by to users
--
-- Same pattern as migrations 006 (categories/units/suppliers) and 008
-- (products) - users already has created_at, so only these three
-- columns are new here. Self-referential (users.created_by ->
-- users.id) since this is the users table itself; MySQL allows this
-- without issue.
--
-- created_by/updated_by are nullable - NULL on every row that predates
-- this column (unknown, not a guess) and ON DELETE SET NULL so losing
-- attribution can never block or cascade-delete the row itself.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE users
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN created_by INT NULL AFTER updated_at,
    ADD COLUMN updated_by INT NULL AFTER created_by,
    ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
