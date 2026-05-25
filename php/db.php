<?php
$host = 'localhost';
$dbname = 'u411086182_db_J8b94vuN';
$username = 'u411086182_usr_J8b94vuN';
$password = '2R>xj^Vn';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (is_file(__DIR__ . '/user_schema_ensure.php') && !defined('SKIP_SCHEMA_ENSURE')) {
        require_once __DIR__ . '/user_schema_ensure.php';
        baa_user_schema_ensure($pdo);
    }
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    throw new Exception("Database connection error. Please try again later.");
}

function baa_normalize_name_part(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $upper = strtoupper($value);
    if ($upper === 'N/A' || $upper === 'NA') {
        return '';
    }
    return $value;
}

function baa_build_full_name(array $parts): string {
    $parts = array_map('baa_normalize_name_part', $parts);
    $parts = array_filter($parts, fn($part) => $part !== '');
    return implode(' ', $parts);
}

function baa_full_name_sql(string $prefix = ''): string {
    $prefix = trim($prefix);
    if ($prefix !== '' && substr($prefix, -1) !== '.') {
        $prefix .= '.';
    }
    return "CONCAT_WS(' ', NULLIF(CASE WHEN TRIM(UPPER({$prefix}first_name)) IN ('N/A','NA') THEN '' ELSE TRIM({$prefix}first_name) END, ''), NULLIF(CASE WHEN TRIM(UPPER({$prefix}middle_name)) IN ('N/A','NA') THEN '' ELSE TRIM({$prefix}middle_name) END, ''), NULLIF(CASE WHEN TRIM(UPPER({$prefix}last_name)) IN ('N/A','NA') THEN '' ELSE TRIM({$prefix}last_name) END, ''), NULLIF(CASE WHEN TRIM(UPPER({$prefix}suffix)) IN ('N/A','NA') THEN '' ELSE TRIM({$prefix}suffix) END, ''))";
}
?>