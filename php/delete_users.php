<?php
ob_clean();
ob_start();
header('Content-Type: application/json');
session_start();
require_once 'db.php';

// ... (same permission checks) ...

try {
    $pdo->beginTransaction();
    
    // Get user info for all selected users (include first_name, etc.)
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name, suffix, role FROM users WHERE id IN ($placeholders)");
    $stmt->execute($userIds);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        $pdo->rollBack();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No users found']);
        exit();
    }
    
    // Construct full name for error messages
    $currentRole = $_SESSION['role'];
    $deletableIds = [];
    $blockedUsers = [];
    
    foreach ($users as $user) {
        $fullName = trim($user['first_name'] . ' ' .
            (!empty($user['middle_name']) ? $user['middle_name'] . ' ' : '') .
            $user['last_name'] .
            (!empty($user['suffix']) ? ' ' . $user['suffix'] : ''));
        
        if ($currentRole === 'registrar' && !in_array($user['role'], ['student', 'teacher'])) {
            $blockedUsers[] = $fullName . ' (' . $user['role'] . ')';
        } elseif ($currentRole === 'admin' && $user['role'] === 'admin') {
            $blockedUsers[] = $fullName . ' (' . $user['role'] . ')';
        } else {
            $deletableIds[] = $user['id'];
        }
    }
    
    // ... rest of the script (same) ...
    
} catch(Exception $e) {
    // ... error handling ...
}
?>