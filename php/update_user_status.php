<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$userId = $data['user_id'] ?? 0;
$status = $data['status'] ?? null;

if (!$userId || $status === null || !in_array($status, [0,1])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

if ($userId == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'You cannot change your own status']);
    exit;
}

if ($_SESSION['role'] === 'registrar') {
    $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $roleStmt->execute([$userId]);
    $targetRole = $roleStmt->fetchColumn();
    if ($targetRole === false) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    if ($targetRole === 'admin') {
        echo json_encode(['success' => false, 'message' => 'Registrar cannot modify admin status']);
        exit;
    }
}

$stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
if ($stmt->execute([$status, $userId])) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}