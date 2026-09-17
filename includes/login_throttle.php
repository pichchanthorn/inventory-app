<?php
// ================================================
// Login brute-force throttle (Phase K2-C).
//
// Small and deliberately unambitious: four operations over one table,
// no generic "security framework". Everything here is keyed by the
// SUBMITTED email address, never by users.id, because the attempts
// worth counting most are the ones against addresses that do not exist.
//
// The store is the database, not $_SESSION and not a file. This is not
// a style preference - it was measured. An attacker who simply omits
// the session cookie gets a brand-new session on every request, so a
// $_SESSION counter never accumulates; and the app runs under multiple
// PHP workers (stock php:8.4-fpm defaults to pm.max_children=5, and
// Apache/XAMPP is likewise multi-process), so per-process memory and
// unlocked files are both wrong. The database is the only store shared
// across workers, browsers, devices and restarts.
//
// Out of scope here by decision, not oversight: IP-based limiting,
// admin unlock UI, audit-log integration, CAPTCHA. Failed logins are
// deliberately NOT written to audit_log - that table's action column is
// ENUM('create','update','delete'), its entity_id is NOT NULL (a failed
// login may target no existing user at all), and includes/audit.php's
// own contract ties a write to the transaction of the mutation it logs.
// A login attack would also bury real business history under thousands
// of rows.
// ================================================

// Policy. Five failures inside a ten-minute rolling window, after which
// further attempts for that address are refused until the window no
// longer contains five. There is no permanent lockout and no manual
// unlock: this application has no password-recovery flow of any kind
// (auth/ holds only login/logout/register, and the sole reset path in
// user/index.php requires an already-authenticated Admin and refuses
// self-reset), so a lock that needed human intervention could take the
// shop's only Admin account out until someone reached the database.
const LOGIN_THROTTLE_MAX_ATTEMPTS = 5;
const LOGIN_THROTTLE_WINDOW_MINUTES = 10;

// How many stale rows one request may prune. Bounded so that a login
// arriving after a large attack does not pay for the whole cleanup at
// once; whatever is left over is simply collected by the next request.
const LOGIN_THROTTLE_PRUNE_LIMIT = 500;

// A real bcrypt hash, generated once (of a random string that was never
// recorded) and pinned here as a literal. It exists so that a login for
// an address with no account still performs one genuine
// password_verify() - see verifyAgainstDummyHash() below.
//
// It must never be regenerated per request: password_hash() costs about
// as much as the verify itself, so computing it on the fly would make
// the unknown-account path roughly twice the known-account path and
// simply invert the timing signal instead of removing it.
//
// Cost 12 matches what PASSWORD_DEFAULT produces on the PHP this
// application targets, so the dummy verify costs what a real one costs.
// If a deployment's stored hashes were produced at a different cost the
// two paths would drift apart again - narrowing a ~210x difference to a
// small one, not re-opening it, but worth knowing before changing the
// cost.
const LOGIN_THROTTLE_DUMMY_HASH = '$2y$12$UenWFkTzjsZ8HA7UCxiX.O9d9dtejpU.MNtVx3jCOtVV0LmkZoHU.';

// The throttle key for a submitted address.
//
// Lowercased, because users.email is utf8mb4_general_ci and the login
// lookup therefore already matches case-insensitively (verified:
// 'a@b.c', 'A@B.C' and 'A@b.C' all resolve to the same account). A
// case-sensitive throttle key would hand an attacker a fresh five-
// attempt budget for every capitalisation of one address - an unlimited
// budget in practice.
//
// trim() mirrors what auth/login.php already does to the submitted
// value, so the key describes the same string the lookup used. Nothing
// else is normalised: stripping dots or +tags would be this file
// inventing account-identity rules the rest of the application does not
// share.
function normalizeLoginEmail(string $email): string {
    return strtolower(trim($email));
}

// Is this address currently refused? Called BEFORE any password work,
// so that a throttled request costs a single indexed COUNT rather than
// a ~0.23s bcrypt verify - otherwise the protection would itself be a
// way to burn the server's PHP workers.
function loginIsThrottled(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([normalizeLoginEmail($email), LOGIN_THROTTLE_WINDOW_MINUTES]);

    return (int) $stmt->fetchColumn() >= LOGIN_THROTTLE_MAX_ATTEMPTS;
}

// One INSERT, one row, no transaction. Nothing is read first, so there
// is nothing to race: eight simultaneous failures record eight rows.
function recordFailedLogin(PDO $pdo, string $email): void {
    $stmt = $pdo->prepare('INSERT INTO login_attempts (email) VALUES (?)');
    $stmt->execute([normalizeLoginEmail($email)]);
}

// Proving the password wipes that address's history, so a member of
// staff who fumbles four times and then gets it right starts clean
// rather than carrying four failures for the next ten minutes.
function clearFailedLogins(PDO $pdo, string $email): void {
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
    $stmt->execute([normalizeLoginEmail($email)]);
}

// Drop rows that have aged out of the window.
//
// The cutoff is the window boundary itself, so this can only ever
// delete attempts that no longer count towards anyone's threshold. A
// row another request inserted a moment ago carries attempted_at =
// NOW(), which is inside the window by the full ten minutes - it is not
// a close call, and there is no ordering between the two statements
// that could make it one.
//
// NOW() is the database's clock, not PHP's, so every worker - and, in
// the Docker topology, a separate container - agrees on where the
// window starts.
function pruneStaleLoginAttempts(PDO $pdo): void {
    // LIMIT takes a code constant, never request input. It is cast and
    // interpolated rather than bound because placeholders in LIMIT are
    // not portable across every MySQL/MariaDB version this application
    // is deployed on; the cast makes the value provably an integer.
    $limit = (int) LOGIN_THROTTLE_PRUNE_LIMIT;

    $stmt = $pdo->prepare(
        'DELETE FROM login_attempts
          WHERE attempted_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
          LIMIT ' . $limit
    );
    $stmt->execute([LOGIN_THROTTLE_WINDOW_MINUTES]);
}

// Burn one genuine bcrypt verify for a submitted address that has no
// account, so the "no such user" path costs what the "wrong password"
// path costs.
//
// Without this the difference is not subtle: measured on the current
// code, an unknown address answers in ~0.0011s and a known one in
// ~0.2324s, because password_verify() is simply never reached when the
// SELECT returns nothing. That ~210x gap enumerates every valid account
// in a shop at one request each.
//
// The return value is always false and is returned only so call sites
// read as a verification rather than as a bare side effect. Using the
// real password_verify() against a real bcrypt hash - rather than a
// sleep or a cheaper algorithm - is what makes the work comparable
// without holding a worker idle.
function verifyAgainstDummyHash(string $password): bool {
    return password_verify($password, LOGIN_THROTTLE_DUMMY_HASH);
}
