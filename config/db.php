<?php
// ================================================
// Database connection (PDO + prepared statements)
// Edit these 4 values to match your own XAMPP/Laragon setup
// ================================================
$host    = getenv('DB_HOST') ?: '127.0.0.1';
$db      = getenv('DB_DATABASE') ?: 'inventory_db';
$user    = getenv('DB_USERNAME') ?: 'root';
$pass    = getenv('DB_PASSWORD') ?: '';
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
