<?php
session_start();

try {
    require_once 'db.php';
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
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