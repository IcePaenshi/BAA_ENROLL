<?php
session_start();
require_once 'db.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Get all enrollments with their documents
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            e.full_name,
            e.age,
            e.gender,
            e.birthdate,
            e.email,
            e.phone,
            e.status,
            e.created_at,
            COUNT(ed.id) as document_count
        FROM enrollments e
        LEFT JOIN enrollment_documents ed ON e.id = ed.enrollment_id
        GROUP BY e.id
        ORDER BY e.created_at DESC
    ");
    $stmt->execute();
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'enrollments' => $enrollments
    ]);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
