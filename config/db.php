<?php
// ================================================
// Database connection (PDO + prepared statements)
// Edit these 4 values to match your own XAMPP/Laragon setup
// ================================================
$host    = '127.0.0.1';
$db      = 'inventory_db';
$user    = 'root';
$pass    = '';        // default XAMPP password is usually empty
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw real errors instead of silent failure
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements = safe from SQL injection
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
