<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/lang.php';
// Include this at the very top of every page that requires login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// Admin-created accounts can be flagged to force a password change before
// touching the rest of the app — everything redirects to profile.php until
// they set their own password.
if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'profile.php') {
    header('Location: ' . BASE_URL . '/profile.php');
    exit;
}

function isAdmin() {
    return ($_SESSION['role_id'] ?? null) == 1;
}
