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

try {
    $stmt = $pdo->prepare("
        SELECT id, " . baa_full_name_sql() . " AS full_name, role, profile_picture 
        FROM users 
        WHERE profile_picture_status = 'pending' AND profile_picture IS NOT NULL
        ORDER BY id DESC
    ");
    $stmt->execute();
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'photos' => $pending
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
