<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Badge-only: return count of submitted-but-not-reviewed entries
if (isset($_GET['badge_only'])) {
    try {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT CONCAT(subject_id,'-',semester)) FROM trimester_grades WHERE is_submitted = 1");
        $count = (int)($stmt->fetchColumn() ?: 0);
        echo json_encode(['success' => true, 'pending_count' => $count]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'pending_count' => 0]);
    }
    exit;
}

// Unlock submission so teacher can re-edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unlock_submission') {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);
    if ($subject_id < 1 || $semester < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("UPDATE trimester_grades SET is_submitted = 0 WHERE subject_id = ? AND semester = ?");
        $stmt->execute([$subject_id, $semester]);
        echo json_encode(['success' => true, 'message' => 'Submission unlocked for teacher editing.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

try {
    $semesterFilter  = isset($_GET['semester'])    ? (int) $_GET['semester']    : 0;
    $gradeFilter     = isset($_GET['grade_level']) ? trim($_GET['grade_level']) : '';

    $sql = "
        SELECT 
            tg.subject_id, 
            tg.semester, 
            s.subject_name, 
            s.grade_level, 
            s.section,
            " . baa_full_name_sql('u') . " AS teacher_name,
            COUNT(DISTINCT tg.student_id) AS student_count
        FROM trimester_grades tg
        JOIN subjects s ON tg.subject_id = s.id
        JOIN teacher_subjects ts ON tg.subject_id = ts.subject_id
        JOIN users u ON ts.teacher_id = u.id
        WHERE tg.is_submitted = 1
    ";

    $params = [];
    if ($semesterFilter > 0) {
        $sql .= " AND tg.semester = ?";
        $params[] = $semesterFilter;
    }
    if ($gradeFilter !== '') {
        $sql .= " AND (TRIM(REPLACE(REPLACE(LOWER(s.grade_level), 'grade ', ''), 'grade', '')) = TRIM(REPLACE(REPLACE(LOWER(?), 'grade ', ''), 'grade', '')))";
        $params[] = $gradeFilter;
    }

    $sql .= " GROUP BY tg.subject_id, tg.semester, s.subject_name, s.grade_level, s.section, teacher_name
              ORDER BY s.grade_level, s.section, s.subject_name, tg.semester";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'submissions' => $submissions,
        'debug' => [
            'received_semester' => $semesterFilter,
            'received_grade_filter' => $gradeFilter,
            'row_count' => count($submissions)
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
