<?php
require_once __DIR__ . '/../config/base_url.php';
session_start();

// Phase K2-A: complete the logout.
//
// session_destroy() alone removes the server-side session data but
// leaves two things behind: the $_SESSION array in this request, and -
// more importantly - the session cookie in the browser, which keeps
// pointing at the now-destroyed ID. Since PHP's session.use_strict_mode
// defaults to 0 and this project ships no session configuration, that
// stale ID would simply be adopted again on the next visit.
//
// Order matters: clear the data, expire the cookie while the session
// name/params are still available, then destroy the server-side record.
$_SESSION = [];

// Expire the cookie using the session's OWN current cookie parameters,
// so the expiry matches the cookie that was actually set (path/domain/
// secure/httponly must match or the browser keeps the original).
// This deliberately only MIRRORS the current configuration - setting
// httponly/secure/samesite defaults is Phase K2-B, not this batch.
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
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
