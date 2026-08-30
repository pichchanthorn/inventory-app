-- ================================================
-- Migration 011: add idempotency_keys (Phase I2-B1 - POS sale
-- idempotency fix)
--
-- A single, small, general-purpose table backing server-side
-- idempotency for POS sale submissions: a UNIQUE constraint on `token`
-- is the entire mechanism - claiming a token is one INSERT, and InnoDB's
-- own uniqueness enforcement under the row lock is what makes the claim
-- atomic across genuinely concurrent requests, the same "let the
-- database's own constraint do the concurrency-safety work, don't try
-- to check-then-act in PHP" principle already used throughout
-- includes/stock.php's guarded UPDATEs.
--
-- Deliberately NOT linked to stock_transactions/customer_debts via a
-- foreign key - the claim happens as the very first statement inside
-- recordStockOut()/recordCreditSale()'s own transaction, before the
-- resulting transaction's own id exists yet, and the row's mere
-- presence (only ever committed alongside a successful sale, since it
-- shares that same transaction) is sufficient: a genuine duplicate
-- submission is rejected by the UNIQUE constraint before any sale-side
-- work happens, and a failed/rolled-back attempt takes its claim row
-- back out with it, leaving the token claimable again for a legitimate
-- retry. See includes/stock.php's claimIdempotencyToken() for the
-- application-side half of this.
--
-- No expiry/cleanup job - at this app's actual scale (a single small
-- shop, one row per completed sale attempt) this table grows at the
-- same rate as stock_transactions itself, which nothing in this schema
-- prunes either.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

CREATE TABLE IF NOT EXISTS idempotency_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
