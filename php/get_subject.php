<?php
header('Content-Type: application/json');
session_start();
require_once 'db.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

$student_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            s.subject_name, 
            g.grade, 
            g.quarter,
            g.school_year
        FROM grades g 
        JOIN subjects s ON g.subject_id = s.id 
        WHERE g.student_id = ? 
        ORDER BY s.subject_name, g.quarter
    ");
    $stmt->execute([$student_id]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'grades' => $grades
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching grades: ' . $e->getMessage()
    ]);
}
?>