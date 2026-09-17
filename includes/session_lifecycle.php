<?php
// ================================================
// Shared session teardown (Phase K2-D).
//
// This is the K2-A/K2-B logout sequence, lifted verbatim out of
// auth/logout.php so that includes/auth_check.php can end a session the
// SAME way rather than growing a second, slightly-different copy of it.
// Two copies of cookie-expiry logic is exactly the kind of drift the
// K2-B comments warn about: the deletion cookie only removes the real
// one when every attribute matches, so a copy that fell out of step
// with config/session.php would silently stop working.
//
// Behaviour is unchanged from what K2-A shipped and
// tests/Http/SessionLifecycleTest.php pins - this file moves the code,
// it does not alter it.
// ================================================

// Ends the current session completely: data, cookie, and the
// server-side record. Requires an already-started session.
//
// Order matters: clear the data, expire the cookie while the session
// name/params are still available, then destroy the server-side record.
function destroyCurrentSession(): void {
    $_SESSION = [];

    // Expire the cookie using the session's OWN current cookie
    // parameters, so the expiry matches the cookie that was actually set
    // (path/domain/secure/httponly must match or the browser keeps the
    // original). Since K2-B those parameters come from
    // config/session.php, so this automatically carries HttpOnly,
    // SameSite and the request-derived Secure flag.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $options = [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
        ];
        // samesite is only accepted by setcookie() when non-empty; PHP's
        // default is an empty string (unset), which must not be passed on.
        if (!empty($params['samesite'])) {
            $options['samesite'] = $params['samesite'];
        }
        setcookie(session_name(), '', $options);
    }

    session_destroy();
}
