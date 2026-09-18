-- ================================================
-- Migration 020: add users.is_active (Phase V2-B3 - Account Deactivation)
--
-- Lets an Admin deactivate a staff account without deleting it - the
-- account, its history (audit_log rows, created_by/updated_by
-- references, stock/debt transactions it authored) all stay intact, but
-- it can no longer log in or use an already-open session. This is a
-- plain boolean, not a status enum: there is exactly one lifecycle
-- transition (active <-> inactive), so an enum would be speculative
-- generality with nothing in this application to justify it.
--
-- NOT NULL DEFAULT 1: every existing row becomes active automatically
-- the moment this ALTER TABLE runs - MySQL/MariaDB applies a NOT NULL
-- column's DEFAULT to every existing row when the column is added, so
-- no separate backfill UPDATE is needed (unlike migration 019's
-- password_changed_at, which was nullable and needed one). No existing
-- account is ever locked out by this migration.
--
-- Placed AFTER must_change_password, grouping it with the other
-- account-state flag rather than after role_id or at the end of the
-- table.
--
-- includes/auth_check.php's existing Phase K2-D privilege-freshness
-- read (one indexed PK SELECT per authenticated request) is extended to
-- also read this column and reuses its existing destroyCurrentSession()
-- teardown when it is 0 - no second session-invalidation mechanism is
-- introduced.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER must_change_password;
