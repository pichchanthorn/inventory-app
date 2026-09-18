<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/lang.php';
// Include this at the very top of every page that requires login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/session_lifecycle.php';
// $pdo, for the privilege-freshness read below. Every page that includes
// this file already requires config/db.php a line or two later, and that
// file is require_once - so this pulls the same single connection
// forward rather than opening a second one.
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// ================================================
// Phase K2-D: privilege freshness.
//
// Before this, everything below trusted a snapshot taken once at login.
// $_SESSION['role_id'] was written in exactly one place
// (auth/login.php) and never refreshed, so demoting somebody in the
// Users page changed the database and nothing else: their live session
// kept full Admin rights, and was measurably able to promote another
// account back to Admin from a role_id=2 session. A password reset was
// worse - it replaced the hash while every existing session carried on
// working, so the obvious response to a suspected compromise did
// nothing at all.
//
// One indexed primary-key read per authenticated request fixes all of
// that, and costs about 0.08ms - roughly three thousand times less than
// the bcrypt verify this application already performs at login. It runs
// ONCE here, not at the 63 isAdmin()/canWrite() call sites, which are
// left completely untouched and keep reading $_SESSION.
// ================================================
$freshStmt = $pdo->prepare('SELECT role_id, must_change_password, password_changed_at, is_active FROM users WHERE id = ?');
$freshStmt->execute([$_SESSION['user_id']]);
$freshUser = $freshStmt->fetch();

if (!$freshUser) {
    // The account no longer exists. Previously the session carried on
    // working indefinitely, because the only identity check was
    // isset($_SESSION['user_id']) - the id was never resolved against a
    // row. Treat it as unauthenticated and tear the session down the
    // same way logout does.
    destroyCurrentSession();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// Phase V2-B3: account deactivation. Checked immediately after
// confirming the row exists, and before the password-baseline check
// below - a deactivated account must be fully signed out on its very
// next request, the same as a deleted account, not merely lose
// privileges the way a role demotion does (which deliberately leaves
// the session alive as a lesser role). Reuses the identical
// destroyCurrentSession() teardown - no second session-invalidation
// mechanism.
if (!$freshUser['is_active']) {
    destroyCurrentSession();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// Has the password changed since this session began?
//
// Compared as an equality test on the raw column value, not as an
// ordering test: both sides come from the same TIMESTAMP(6) column read
// through the same driver, so the strings are directly comparable
// without any date parsing, and equality also catches a value being
// moved backwards rather than only forwards. A session that predates
// the column (no baseline recorded) is treated as stale and asked to
// sign in again, which is the safe direction.
$sessionPasswordBaseline = $_SESSION['password_changed_at'] ?? null;
$currentPasswordChangedAt = $freshUser['password_changed_at'];

if (!array_key_exists('password_changed_at', $_SESSION)
    || (string) $sessionPasswordBaseline !== (string) $currentPasswordChangedAt) {
    destroyCurrentSession();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// Authorization state is now whatever the database currently says, for
// this and every other session of the same account - they all read the
// same row, so they converge without any session registry.
$_SESSION['role_id'] = $freshUser['role_id'];
$_SESSION['must_change_password'] = (bool) $freshUser['must_change_password'];

// Admin-created accounts can be flagged to force a password change before
// touching the rest of the app — everything redirects to profile.php until
// they set their own password. Since K2-D this reads the flag as it is in
// the database right now, so an Admin setting it on somebody who is
// already signed in takes effect on that person's next request.
if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'profile.php') {
    header('Location: ' . BASE_URL . '/profile.php');
    exit;
}

function isAdmin() {
    return ($_SESSION['role_id'] ?? null) == 1;
}

function isViewer() {
    return ($_SESSION['role_id'] ?? null) == 3;
}

// Viewers get read-only access everywhere — every create/update action
// should check this before touching the database.
function canWrite() {
    return !isViewer();
}
