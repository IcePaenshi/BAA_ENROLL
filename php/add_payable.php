<?php
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$studentId = $_POST['student_id'] ?? '';
$description = trim($_POST['description'] ?? '');
$amount = $_POST['amount'] ?? '';
$dueDate = $_POST['due_date'] ?? '';

if (!$studentId || !is_numeric($studentId)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit();
}

if (empty($description)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Description is required']);
    exit();
}

if (!is_numeric($amount) || $amount <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid amount']);
    exit();
}

try {
    $stmt = $pdo->prepare("INSERT INTO payables (student_id, item_name, amount, due_date) VALUES (?, ?, ?, ?)");
    $stmt->execute([$studentId, $description, $amount, $dueDate]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Payable added successfully']);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>