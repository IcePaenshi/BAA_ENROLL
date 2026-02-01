<?php
header('Content-Type: application/json');
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(item_name, 'Tuition Fee') as description, 
            due_date, 
            amount,
            COALESCE(status, 'pending') as status
        FROM payables 
        WHERE student_id = ? 
        ORDER BY due_date ASC, status ASC
    ");
    $stmt->execute([$userId]);
    $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'payables' => $payables]);
} catch(PDOException $e) {
    error_log("Error in get_payables.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>