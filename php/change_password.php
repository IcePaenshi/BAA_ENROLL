<?php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$oldPassword = (string) ($_POST['old_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}
if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
    exit;
}
if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
    echo json_encode(['success' => false, 'message' => 'Password must include letters, numbers, and special characters (min 8 chars)']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $hash = (string) ($stmt->fetchColumn() ?: '');
    if ($hash === '' || !password_verify($oldPassword, $hash)) {
        echo json_encode(['success' => false, 'message' => 'Old password is incorrect']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $up->execute([$newHash, (int) $_SESSION['user_id']]);
    $_SESSION['require_password_change'] = false;
    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

