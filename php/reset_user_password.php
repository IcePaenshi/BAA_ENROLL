<?php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);
if ($userId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid user']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || ($target['role'] ?? '') !== 'student') {
        echo json_encode(['success' => false, 'message' => 'Only student passwords can be reset here']);
        exit;
    }

    $defaultPassword = 'baa123';
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $up->execute([$hash, $userId]);

    echo json_encode(['success' => true, 'message' => 'Password reset to default (baa123). Student will be prompted to change it on login.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

