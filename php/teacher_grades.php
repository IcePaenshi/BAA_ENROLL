<?php
require_once 'db.php';

// Only teachers can access
if ($_SESSION['role'] !== 'teacher') {
    header('Location: ../dashboard.php');
    exit();
}

// Handle grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = $_POST['student_id'];
    $subjectId = $_POST['subject_id'];
    $grade = $_POST['grade'];
    $quarter = $_POST['quarter'];
    
    // Update or insert grade
    $stmt = $pdo->prepare("
        INSERT INTO grades (student_id, subject_id, grade, quarter, school_year) 
        VALUES (?, ?, ?, ?, '2025-2026')
        ON DUPLICATE KEY UPDATE grade = VALUES(grade)
    ");
    $stmt->execute([$studentId, $subjectId, $grade, $quarter]);
    
    echo json_encode(['success' => true]);
    exit();
}
?>