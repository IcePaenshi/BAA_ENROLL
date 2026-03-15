<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

$stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
if ($stmt->execute([$status, $userId])) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}