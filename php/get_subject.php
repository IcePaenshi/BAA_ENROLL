<?php
header('Content-Type: application/json');
session_start();

try {
    require_once 'db.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

$userRole = $_SESSION['role'];

// Get request data - handle both JSON and POST data
$requestData = [];
if ($_SERVER['CONTENT_TYPE'] === 'application/json' || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    $requestData = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $requestData = $_POST;
}

$action = $requestData['action'] ?? 'get_student_grades';

switch ($action) {
    case 'get_student_grades':
        if ($userRole != 'student') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            exit();
        }
        getStudentGrades();
        break;
    case 'get_all_subjects':
        if (!in_array($userRole, ['admin', 'registrar', 'teacher'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            exit();
        }
        getAllSubjects();
        break;
    case 'get_teacher_subjects':
        if (!in_array($userRole, ['admin', 'registrar', 'teacher'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            exit();
        }
        getTeacherSubjects($requestData);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}

function getStudentGrades() {
    global $pdo;
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
}

function getAllSubjects() {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT MIN(id) AS id, subject_name, grade_level FROM subjects GROUP BY subject_name, grade_level ORDER BY grade_level, subject_name");
        $stmt->execute();
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'subjects' => $subjects
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching subjects: ' . $e->getMessage()
        ]);
    }
}

function getTeacherSubjects($requestData) {
    global $pdo;
    $teacher_id = $requestData['teacher_id'] ?? '';

    if (empty($teacher_id)) {
        echo json_encode(['success' => false, 'message' => 'Teacher ID required']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT MIN(s.id) AS id, s.subject_name, s.grade_level
            FROM teacher_subjects ts
            JOIN subjects s ON ts.subject_id = s.id
            WHERE ts.teacher_id = ?
            GROUP BY s.subject_name, s.grade_level
            ORDER BY s.grade_level, s.subject_name
        ");
        $stmt->execute([$teacher_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'subjects' => $subjects
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching teacher subjects: ' . $e->getMessage()
        ]);
    }
}
?>