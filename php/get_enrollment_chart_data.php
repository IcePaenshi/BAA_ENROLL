<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get parameters
$dataType = isset($_GET['data_type']) ? $_GET['data_type'] : 'enrollees';
$gradeFilter = isset($_GET['grade']) ? $_GET['grade'] : '';
$sectionFilter = isset($_GET['section']) ? $_GET['section'] : '';

try {
    if ($dataType === 'students') {
        // Count registered students from users table
        $sql = "SELECT grade_level, COUNT(*) as count FROM users WHERE role = 'student'";
        $params = [];
    } else {
        // Count enrollment requests from enrollments table
        $sql = "SELECT grade_level, COUNT(*) as count FROM enrollments WHERE 1=1";
        $params = [];
        
    }

    // Apply grade filter if provided
    if (!empty($gradeFilter)) {
        $sql .= " AND grade_level = :grade";
        $params[':grade'] = $gradeFilter;
    }
    // Apply section filter if provided
    if (!empty($sectionFilter)) {
        $sql .= " AND section = :section";
        $params[':section'] = $sectionFilter;
    }

    $sql .= " GROUP BY grade_level ORDER BY FIELD(grade_level, 
              'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12')";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure all grade levels appear in the result
    $allGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    $gradeCounts = [];
    foreach ($data as $row) {
        $gradeCounts[$row['grade_level']] = (int)$row['count'];
    }

    $labels = [];
    $values = [];
    foreach ($allGrades as $grade) {
        $labels[] = $grade;
        $values[] = isset($gradeCounts[$grade]) ? $gradeCounts[$grade] : 0;
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'values' => $values
    ]);

} catch (PDOException $e) {
    error_log("Chart data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}