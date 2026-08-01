<?php
require_once __DIR__ . '/../config/base_url.php';
session_start();
session_destroy();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
