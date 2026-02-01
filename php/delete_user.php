<?php
ob_clean();
ob_start();
header('Content-Type: application/json');
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$userId = $_POST['user_id'] ?? '';

if (!$userId || !is_numeric($userId)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Prevent deleting yourself
if ($userId == $_SESSION['user_id']) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
    exit();
}

try {
    // Get user info
    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    // Check permissions: super_admin can delete anyone, admin can only delete students/teachers
    $currentRole = $_SESSION['role'];
    if ($currentRole === 'admin' && in_array($user['role'], ['admin', 'super_admin'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions to delete this user']);
        exit();
    }
    
    // Delete user from database
    $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $deleteStmt->execute([$userId]);
    
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
    exit();
    
} catch(Exception $e) {
    error_log("Error deleting user: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Error deleting user']);
    exit();
}
?>