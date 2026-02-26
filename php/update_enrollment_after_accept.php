<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$enrollmentId = isset($_POST['enrollment_id']) ? intval($_POST['enrollment_id']) : 0;
$userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

if (!$enrollmentId || !$userId) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$stmt = $pdo->prepare("UPDATE enrollments SET student_id = ?, status = 'approved', updated_at = NOW() WHERE id = ?");
$stmt->execute([$userId, $enrollmentId]);

echo json_encode(['success' => true]);