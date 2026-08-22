-- ================================================
-- Migration 007: add audit_log
--
-- Append-only audit trail for CREATE/UPDATE/DELETE on the catalog/config
-- tables. Products and Users are separate, later batches;
-- stock_transactions is out of scope entirely - already append-only/
-- permanent by design.
--
-- entity_id deliberately has NO foreign key: one column can't reference
-- five different tables, and a 'delete' row's entity_id must remain
-- valid after the referenced row is gone - that's the point of the row.
--
-- before_snapshot/after_snapshot are entity-level (the whole row), not
-- field-level diffs - Phase 1 scope. Must never contain users.password -
-- that redaction is the caller's responsibility (includes/audit.php),
-- not enforced by this table.
--
-- CRITICAL: this table must never become editable through the app, not
-- even for Admin - no UPDATE/DELETE code path is expected to exist
-- against it, ever.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action ENUM('create','update','delete') NOT NULL,
    entity_type VARCHAR(30) NOT NULL,
    entity_id INT NOT NULL,
    before_snapshot JSON NULL,
    after_snapshot JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
);
