<?php
$host = 'localhost';
$dbname = 'u411086182_db_J8b94vuN';
$username = 'u411086182_usr_J8b94vuN';
$password = '2R>xj^Vn';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (is_file(__DIR__ . '/user_schema_ensure.php')) {
        require_once __DIR__ . '/user_schema_ensure.php';
        baa_user_schema_ensure($pdo);
    }
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    throw new Exception("Database connection error. Please try again later.");
}
?>