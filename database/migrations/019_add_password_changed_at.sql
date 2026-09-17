-- ================================================
-- Migration 019: add users.password_changed_at (Phase K2-D - privilege
-- freshness and session invalidation)
--
-- The session baseline that lets an authenticated request notice its
-- own credentials have been replaced. includes/auth_check.php compares
-- the value stored in $_SESSION at login against the current value in
-- this column on every authenticated request; when they differ, that
-- session is torn down and sent back to the login page. That is what
-- makes an Admin password reset actually end the sessions it was meant
-- to end, including sessions on other devices - previously a reset
-- changed the hash and nothing else, so the old sessions kept working.
--
-- TIMESTAMP(6), not TIMESTAMP. The comparison is "is this the same
-- value the session started with", so the column needs enough
-- resolution that two password changes can never share a value. Plain
-- TIMESTAMP has one-second resolution, which would silently skip
-- invalidation whenever a reset landed in the same second as the
-- previous change - rare in a shop, routine in a test suite. Verified:
-- three consecutive CURRENT_TIMESTAMP(6) writes produce three distinct
-- values.
--
-- NULL DEFAULT CURRENT_TIMESTAMP(6), and deliberately WITHOUT
-- ON UPDATE. This was verified rather than assumed: with ON UPDATE the
-- column would move on every unrelated UPDATE to the row, so changing
-- somebody's role would log them out - a bug that would look like a
-- security feature. Confirmed that an unrelated UPDATE leaves the value
-- untouched.
--
-- Nullable so that the backfill below is the only thing that decides
-- existing rows' values, and so a row that somehow never receives one
-- still behaves correctly: NULL at login and NULL on the next request
-- compare equal, so the session stays valid, and the first password
-- change gives it a real value.
--
-- The backfill uses each row's own created_at, which states the truth
-- about an existing account: its password has not been changed since
-- the account was made. It touches only this new column - no existing
-- user data is read back, rewritten or destroyed, and every existing
-- row survives with a usable login baseline.
--
-- Run against an EXISTING database that predates this change. Fresh
-- installs using the current database/schema.sql already include it.
-- ================================================

USE inventory_db;

ALTER TABLE users
    ADD COLUMN password_changed_at TIMESTAMP(6) NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER must_change_password;

UPDATE users SET password_changed_at = created_at WHERE password_changed_at IS NULL;
