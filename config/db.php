<?php
declare(strict_types=1);

// DEV ONLY — remove this block in production
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

define('DB_HOST', 'localhost');
define('DB_NAME', 'scms');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Set MySQL session timezone (matches PHP side)
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}