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
    // Phase K2-E: the application still cannot continue without a
    // database, so this still stops the request - but the real
    // exception (which carries the DB host, username, database name and
    // SQLSTATE) must never reach the browser. It goes to error_log()
    // instead, which XAMPP/Laragon surface in their own PHP error log
    // for local debugging, and which the Docker image (see
    // docker/php/php.ini) sends to container stderr for `docker logs`.
    // The visitor gets a single generic sentence with no diagnostic
    // content at all.
    error_log('Database connection failed: ' . $e->getMessage());
    die('A required service is temporarily unavailable. Please try again shortly.');
}
