<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $subject_id = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;
    $semester = isset($_GET['semester']) ? (int) $_GET['semester'] : 0;
    
    if (!$subject_id || !$semester) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit;
    }
    
    // Subject details
    $subjStmt = $pdo->prepare("SELECT subject_name, grade_level, section FROM subjects WHERE id = ?");
    $subjStmt->execute([$subject_id]);
    $subject = $subjStmt->fetch(PDO::FETCH_ASSOC);
    
    // Grades
    $gradeStmt = $pdo->prepare("
        SELECT 
            " . baa_full_name_sql('u') . " AS student_name,
            u.lrn,
            tg.quiz_score,
            tg.essay_score,
            tg.recitation_score,
            tg.periodic_test_score,
            tg.attendance_score,
            tg.calculated_grade
        FROM trimester_grades tg
        JOIN users u ON tg.student_id = u.id
        WHERE tg.subject_id = ? AND tg.semester = ? AND tg.is_submitted = 1
        ORDER BY u.last_name, u.first_name
    ");
    $gradeStmt->execute([$subject_id, $semester]);
    $grades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'subject' => $subject, 'grades' => $grades]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
