<?php
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$enrollmentId = $_POST['enrollment_id'] ?? '';
$status = $_POST['status'] ?? '';

// Validate status
$validStatuses = ['pending', 'approved', 'rejected', 'needs_docs'];
if (!in_array($status, $validStatuses)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE enrollments 
        SET status = ? 
        WHERE id = ?
    ");
    $stmt->execute([$status, $enrollmentId]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Enrollment status updated successfully'
    ]);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
