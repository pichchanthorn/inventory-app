-- ================================================
-- Migration 012: add reference_counters (Phase I3-A - reference-number
-- concurrency fix)
--
-- nextStockReference()/nextDebtReference() (includes/stock.php,
-- includes/debt.php) used to compute the next "PREFIX-000123" number via
-- SELECT COUNT(*) FROM <table> + 1 - a plain read with nothing holding a
-- lock between it and the later INSERT, so two concurrent requests could
-- read the same count and race to insert the same reference. The
-- stock_transactions.reference / customer_debts.reference UNIQUE
-- constraints already stopped a duplicate from ever being persisted, but
-- the losing request got an uncaught 1062 error instead of its otherwise-
-- legitimate transaction succeeding.
--
-- This table replaces that COUNT(*) read with one row per counter,
-- advanced via SELECT ... FOR UPDATE + UPDATE inside the caller's own
-- transaction (see includes/stock.php's nextReferenceSequence()) - the
-- row lock serializes concurrent callers instead of letting them race,
-- the same "let the database's own locking carry the concurrency
-- guarantee" principle already used by the guarded UPDATEs and
-- idempotency_keys elsewhere in this schema. A failed/rolled-back caller
-- rolls its counter UPDATE back too, so a failed attempt never
-- permanently burns a number.
--
-- Two rows, matching the exact counters the old COUNT(*) logic drew
-- from - 'stock_transactions' backs the STI/STO/ADJ/SAL prefixes (which
-- have always shared ONE counter, not one each - see
-- nextStockReference()'s own COUNT(*) FROM stock_transactions with no
-- type filter), 'customer_debts' backs DBT. Seeded from the current row
-- counts so numbering picks up exactly where the old COUNT(*)+1 logic
-- would have, with no gap or collision against already-existing
-- references. INSERT IGNORE so re-running this migration never resets an
-- already-advanced counter back to a stale count.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it,
-- seeded at 1 for both counters (a fresh install has no existing rows to
-- count).
-- ================================================

USE inventory_db;

CREATE TABLE IF NOT EXISTS reference_counters (
    counter_key VARCHAR(30) NOT NULL PRIMARY KEY,
    next_value INT NOT NULL
);

INSERT IGNORE INTO reference_counters (counter_key, next_value)
SELECT 'stock_transactions', COUNT(*) + 1 FROM stock_transactions;

INSERT IGNORE INTO reference_counters (counter_key, next_value)
SELECT 'customer_debts', COUNT(*) + 1 FROM customer_debts;
