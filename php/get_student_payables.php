<?php
header('Content-Type: application/json');
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$studentId = $_GET['student_id'] ?? '';

if (!$studentId || !is_numeric($studentId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, item_name, amount, due_date, status FROM payables WHERE student_id = ? ORDER BY due_date");
    $stmt->execute([$studentId]);
    $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'payables' => $payables]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>