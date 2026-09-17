<?php
require_once __DIR__ . '/../config/base_url.php';
// Phase K2-B: configure the session cookie before the session opens, so
// that session_get_cookie_params() below returns the SAME attributes the
// cookie was actually set with and the deletion cookie matches it.
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/session_lifecycle.php';
session_start();

// Phase K2-A: complete the logout.
//
// session_destroy() alone removes the server-side session data but
// leaves two things behind: the $_SESSION array in this request, and -
// more importantly - the session cookie in the browser, which keeps
// pointing at the now-destroyed ID. That stale ID would otherwise be
// presented again on the next visit.
//
// Phase K2-D moved the sequence itself into
// includes/session_lifecycle.php so that auth_check.php can end a
// stale-privilege session in exactly the same way, with exactly the
// same cookie parameters. The code moved; it did not change.
destroyCurrentSession();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
