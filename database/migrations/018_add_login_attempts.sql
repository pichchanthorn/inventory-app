-- ================================================
-- Migration 018: add login_attempts (Phase K2-C - login brute-force
-- protection and username-enumeration timing hardening)
--
-- Records ONE ROW PER FAILED LOGIN ATTEMPT rather than keeping a
-- mutable counter column. Two reasons, both load-bearing:
--
--   1. Concurrency. A counter column would be a read-modify-write, so
--      two simultaneous failed attempts could read the same value and
--      one increment would be lost - exactly the class of bug the
--      guarded UPDATEs in includes/stock.php and the FOR UPDATE row
--      lock in nextStockReference() exist to avoid. An INSERT has no
--      such race: eight parallel failures produce eight rows, full
--      stop, with no lock held across the request.
--   2. A rolling window falls out for free. "Five failures in the last
--      ten minutes" is a COUNT over attempted_at; there is no reset
--      moment to get wrong and no scheduled job needed to make the
--      count decay.
--
-- `email` deliberately stores the SUBMITTED address, not a users.id,
-- and carries NO foreign key. A brute-force run mostly targets
-- addresses that do not exist - those attempts are precisely the ones
-- worth counting, and an FK would make them unrecordable. The column is
-- VARCHAR(150) to match users.email exactly, and inherits the schema's
-- utf8mb4_general_ci collation, so comparisons here fold case the same
-- way the login lookup itself does (verified: `WHERE email = ?` already
-- matches regardless of case). includes/login_throttle.php ALSO
-- lowercases the key in PHP before it ever reaches SQL - belt and
-- braces, since a case-sensitive key would hand an attacker a fresh
-- attempt budget for every capitalisation of the same address.
--
-- No ip_address column. K2-C is an account-scoped policy by decision:
-- every member of a shop's staff shares one NAT address, so an IP limit
-- tight enough to matter would lock out the whole shop at once. Adding
-- the column "just in case" would also bake in a trust decision about
-- REMOTE_ADDR behind the Docker/Nginx topology that has not been
-- verified on a real deployment.
--
-- Two indexes, both earning their place:
--   idx_email_time (email, attempted_at) - serves the hot-path window
--     COUNT and the clear-on-success DELETE.
--   idx_attempted_at (attempted_at)      - serves the pruning DELETE,
--     which filters on attempted_at alone and so cannot use the
--     composite index above (email is its leading column). Without
--     this, every prune would be a full table scan.
--
-- Rows are pruned inline by includes/login_throttle.php once they fall
-- OUTSIDE the rolling window, so the table stays proportional to recent
-- activity rather than growing forever. No scheduled job is introduced
-- - this application ships no scheduler.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at),
    INDEX idx_attempted_at (attempted_at)
);
