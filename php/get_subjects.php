<?php
/**
 * Admin endpoint to fetch all subjects for the Sections & Subjects manager.
 * Returns subjects grouped with their schedules, sections, and teacher assignments.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

try {
    $gradeFilter = $_GET['grade_level'] ?? '';
    $sectionFilter = $_GET['section'] ?? '';

    $conditions = [];
    $params = [];

    if ($gradeFilter !== '') {
        $conditions[] = "s.grade_level = ?";
        $params[] = $gradeFilter;
    }
    if ($sectionFilter !== '') {
        $conditions[] = "s.section = ?";
        $params[] = $sectionFilter;
    }

    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name, s.subject_code, s.grade_level, s.section,
               s.day_of_week, s.start_time, s.end_time, s.semester
        FROM subjects s
        $whereClause
        ORDER BY s.grade_level, s.section, s.subject_name, s.day_of_week, s.start_time
    ");
    $stmt->execute($params);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get available sections
    if ($gradeFilter !== '') {
        $sectionsStmt = $pdo->prepare("SELECT DISTINCT section FROM subjects WHERE section IS NOT NULL AND section != '' AND grade_level = ? ORDER BY section");
        $sectionsStmt->execute([$gradeFilter]);
        $sections = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $sectionsStmt = $pdo->query("SELECT DISTINCT section FROM subjects WHERE section IS NOT NULL AND section != '' ORDER BY section");
        $sections = $sectionsStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Get available grade levels
    $gradesStmt = $pdo->query("SELECT DISTINCT grade_level FROM subjects WHERE grade_level IS NOT NULL ORDER BY grade_level");
    $grades = $gradesStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'subjects' => $subjects,
        'sections' => $sections,
        'grades' => $grades
    ]);
} catch (PDOException $e) {
    error_log('get_subjects error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
