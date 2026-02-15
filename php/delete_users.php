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

// Get the input data (could be JSON or FormData)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// Check if we're deleting a single user or multiple
if (isset($input['user_id']) && !empty($input['user_id'])) {
    // Single user deletion
    $userIds = [$input['user_id']];
} elseif (isset($input['user_ids']) && !empty($input['user_ids'])) {
    // Multiple user deletion - decode if it's a JSON string
    $userIds = is_string($input['user_ids']) ? json_decode($input['user_ids'], true) : $input['user_ids'];
} else {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'No user ID(s) provided']);
    exit();
}

// Ensure we have an array
if (!is_array($userIds)) {
    $userIds = [$userIds];
}

// Sanitize user IDs
$userIds = array_map('intval', $userIds);
$userIds = array_filter($userIds);

if (empty($userIds)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid user ID(s)']);
    exit();
}

// Prevent deleting yourself
if (in_array($_SESSION['user_id'], $userIds)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Get user info for all selected users
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id IN ($placeholders)");
    $stmt->execute($userIds);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        $pdo->rollBack();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No users found']);
        exit();
    }
    
    // Check permissions
    $currentRole = $_SESSION['role'];
    $deletableIds = [];
    $blockedUsers = [];
    
    foreach ($users as $user) {
        if ($currentRole === 'admin' && in_array($user['role'], ['admin', 'super_admin'])) {
            $blockedUsers[] = $user['full_name'] . ' (' . $user['role'] . ')';
        } else {
            $deletableIds[] = $user['id'];
        }
    }
    
    if (!empty($blockedUsers)) {
        $pdo->rollBack();
        ob_end_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Insufficient permissions to delete: ' . implode(', ', $blockedUsers)
        ]);
        exit();
    }
    
    if (empty($deletableIds)) {
        $pdo->rollBack();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No users to delete']);
        exit();
    }
    
    // Delete the users
    $deletePlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
    $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id IN ($deletePlaceholders)");
    $deleteStmt->execute($deletableIds);
    
    $deletedCount = $deleteStmt->rowCount();
    
    $pdo->commit();
    
    ob_end_clean();
    echo json_encode([
        'success' => true, 
        'message' => "$deletedCount user(s) deleted successfully",
        'deleted_count' => $deletedCount
    ]);
    exit();
    
} catch(Exception $e) {
    $pdo->rollBack();
    error_log("Error deleting user: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Error deleting user: ' . $e->getMessage()]);
    exit();
}
?>