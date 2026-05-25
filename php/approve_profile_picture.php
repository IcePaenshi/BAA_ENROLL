<?php
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($userId < 1 || !in_array($role, ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$targetUserId = (int) ($_POST['target_user_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($targetUserId < 1 || !in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $stmt = $pdo->prepare("UPDATE users SET profile_picture_status = ? WHERE id = ?");
    $stmt->execute([$status, $targetUserId]);
    
    // Add notification for the student
    try {
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'photo', ?, 'profile')");
        $notifStmt->execute([$targetUserId, "Your profile picture has been $status."]);
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture successfully ' . ($action === 'approve' ? 'approved.' : 'rejected.')
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
