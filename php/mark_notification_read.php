<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    require_once 'db.php';
    $userId = $_SESSION['user_id'];
    $notificationId = $_POST['id'] ?? null;

    if ($notificationId === 'all') {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read' WHERE user_id = ?");
        $stmt->execute([$userId]);
    } elseif ($notificationId) {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read' WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
