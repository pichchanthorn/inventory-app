<?php
require_once __DIR__ . '/../config/base_url.php';
// Include this at the very top of every page that requires login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}
