<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

try {
    define('SKIP_SCHEMA_ENSURE', true);
    require_once 'db.php';
    
    // Check if user still exists and is active
    $stmt = $pdo->prepare("
        SELECT id, status, role FROM users WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // User doesn't exist anymore
        session_destroy();
        echo json_encode(['success' => false, 'message' => 'User not found', 'action' => 'logout']);
        exit();
    }
    
    if ($user['status'] == 0) {
        // User is inactive
        session_destroy();
        echo json_encode(['success' => false, 'message' => 'Your account has been deactivated', 'action' => 'logout']);
        exit();
    }
    
    // User is active
    echo json_encode(['success' => true, 'message' => 'User is active']);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit();
}
?>
