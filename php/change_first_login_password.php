<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['require_password_change'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or no password change required']);
    exit();
}

$newPassword = $_POST['new_password'] ?? '';

if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long']);
    exit();
}

if (!preg_match('/[A-Z]/', $newPassword)) {
    echo json_encode(['success' => false, 'message' => 'Password must contain at least 1 uppercase letter']);
    exit();
}

if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
    echo json_encode(['success' => false, 'message' => 'Password must contain at least 1 special character']);
    exit();
}

try {
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
    
    $_SESSION['require_password_change'] = false;
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
