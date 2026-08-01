<?php
require_once __DIR__ . '/config/base_url.php';
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/auth/login.php');
}
exit;
