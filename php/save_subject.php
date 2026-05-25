<?php
/**
 * Admin endpoint to save (insert or update) a subject.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

require_once 'db.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$subjectName = trim($_POST['subject_name'] ?? '');
$subjectCode = trim($_POST['subject_code'] ?? '');
$gradeLevel = trim($_POST['grade_level'] ?? '');
$section = trim($_POST['section'] ?? '');
$dayOfWeek = trim($_POST['day_of_week'] ?? '');
$startTime = trim($_POST['start_time'] ?? '');
$endTime = trim($_POST['end_time'] ?? '');
$semester = trim($_POST['semester'] ?? '');

if ($subjectName === '' || $gradeLevel === '') {
    echo json_encode(['success' => false, 'message' => 'Subject name and grade level are required']);
    exit();
}

try {
    if ($id > 0) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE subjects 
            SET subject_name = ?, subject_code = ?, grade_level = ?, section = ?,
                day_of_week = ?, start_time = ?, end_time = ?, semester = ?
            WHERE id = ?
        ");
        $stmt->execute([$subjectName, $subjectCode, $gradeLevel, $section, $dayOfWeek, $startTime ?: null, $endTime ?: null, $semester, $id]);
        echo json_encode(['success' => true, 'message' => 'Subject updated successfully']);
    } else {
        // Insert new
        $stmt = $pdo->prepare("
            INSERT INTO subjects (subject_name, subject_code, grade_level, section, day_of_week, start_time, end_time, semester)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$subjectName, $subjectCode, $gradeLevel, $section, $dayOfWeek, $startTime ?: null, $endTime ?: null, $semester]);
        echo json_encode(['success' => true, 'message' => 'Subject added successfully', 'id' => $pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    error_log('save_subject error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
