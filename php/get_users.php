<?php
session_start();
require_once 'db.php';

// Check permissions – now include cashier
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Fetch all users, constructing full_name from separate fields, and include status
    $stmt = $pdo->query("
        SELECT 
            id,
            username,
            email,
            role,
            grade_level,
            section,
            lrn,
            status,
            created_at,
            CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name
        FROM users
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>