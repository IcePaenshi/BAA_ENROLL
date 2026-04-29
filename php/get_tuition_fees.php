<?php
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/get_fee_breakdown.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $fees = baa_get_all_fee_breakdowns($pdo);
    echo json_encode([
        'success' => true,
        'fees' => $fees,
        'defaults' => baa_default_fee_breakdowns(),
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

