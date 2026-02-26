<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM enrollments WHERE id = ?");
$stmt->execute([$id]);
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($enrollment) {
    echo json_encode(['success' => true, 'data' => $enrollment]);
} else {
    echo json_encode(['success' => false, 'message' => 'Not found']);
}