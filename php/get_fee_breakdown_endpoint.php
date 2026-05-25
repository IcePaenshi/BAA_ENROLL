<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'get_fee_breakdown.php';

$grade = $_GET['grade'] ?? '';
if (!$grade) {
    echo json_encode(['success' => false, 'message' => 'Grade required']);
    exit;
}

try {
    $breakdown = baa_get_fee_breakdown($pdo, $grade);
    echo json_encode(['success' => true, 'breakdown' => $breakdown]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>