<?php
session_start();
require_once 'db.php';

// Check permissions
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$enrollmentId = $_POST['enrollment_id'] ?? '';
$userId = $_POST['user_id'] ?? '';

if (!$enrollmentId || !$userId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Update the enrollment status to 'approved'
    $stmt = $pdo->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ?");
    $stmt->execute([$enrollmentId]);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);

} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>