<?php
// ================================================
// CSRF protection (synchronizer token pattern)
// One token per session; every mutating form includes it as a hidden
// field, and every POST handler verifies it before touching the database.
// Requires an active session — include after session_start().
// ================================================

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

// Call at the top of every POST handler, before any database write.
// Stops the request with a friendly error page if the token is missing,
// stale, or doesn't match the session's — instead of processing the request.
function csrf_verify() {
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Security check failed</title></head>'
           . '<body style="font-family: sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #333;">'
           . '<h3>Security check failed</h3>'
           . '<p>Your form session expired or the request could not be verified. Please go back, refresh the page, and try again.</p>'
           . '<p><a href="javascript:history.back()">Go back</a></p>'
           . '</body></html>';
        exit;
    }
}
