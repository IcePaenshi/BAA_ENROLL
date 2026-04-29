<?php
session_start();

try {
    require_once 'db.php';
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get parameters
$dataType = isset($_GET['data_type']) ? $_GET['data_type'] : 'enrollees';
$gradeFilter = isset($_GET['grade']) ? $_GET['grade'] : '';
$sectionFilter = isset($_GET['section']) ? $_GET['section'] : '';

function normalize_grade_level($gradeLevel) {
    if ($gradeLevel === null) {
        return null;
    }

    $grade = trim((string)$gradeLevel);
    if ($grade === '') {
        return null;
    }

    $map = [
        '7' => 'Grade 7',
        '8' => 'Grade 8',
        '9' => 'Grade 9',
        '10' => 'Grade 10',
        '11' => 'Grade 11',
        '12' => 'Grade 12',
        'Grade 7' => 'Grade 7',
        'Grade 8' => 'Grade 8',
        'Grade 9' => 'Grade 9',
        'Grade 10' => 'Grade 10',
        'Grade 11' => 'Grade 11',
        'Grade 12' => 'Grade 12'
    ];

    if (isset($map[$grade])) {
        return $map[$grade];
    }

    if (preg_match('/^Grade\s*([7-9]|1[0-2])$/i', $grade, $matches)) {
        return 'Grade ' . intval($matches[1]);
    }

    if (preg_match('/^(?:[0-9]|1[0-2])$/', $grade)) {
        return 'Grade ' . intval($grade);
    }

    return null;
}

try {
    $allGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    $gradeCounts = array_fill_keys($allGrades, 0);
    $sections = [];
    $filteredRows = [];

    if ($dataType === 'students') {
        $sql = "SELECT grade_level, section FROM users WHERE role = 'student'";
        $params = [];
        if (!empty($gradeFilter)) {
            $sql .= " AND grade_level = :grade";
            $params[':grade'] = $gradeFilter;
        }
        if (!empty($sectionFilter)) {
            $sql .= " AND section = :section";
            $params[':section'] = $sectionFilter;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $filteredRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT grade_level FROM enrollments WHERE status = 'pending'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $normalizedGrade = normalize_grade_level($row['grade_level']);
            if ($normalizedGrade === null) {
                continue;
            }

            if (!empty($gradeFilter) && $normalizedGrade !== $gradeFilter) {
                continue;
            }

            $filteredRows[] = [
                'grade_level' => $normalizedGrade
            ];
        }
    }

    foreach ($filteredRows as $row) {
        $normalizedGrade = normalize_grade_level($row['grade_level']);
        if ($normalizedGrade === null) {
            continue;
        }

        if (!isset($gradeCounts[$normalizedGrade])) {
            $gradeCounts[$normalizedGrade] = 0;
        }
        $gradeCounts[$normalizedGrade]++;

        if ($dataType === 'students' && !empty($gradeFilter) && $normalizedGrade === $gradeFilter && !empty($row['section'])) {
            $sections[$row['section']] = true;
        }
    }

    $labels = array_values($allGrades);
    $values = [];
    foreach ($allGrades as $grade) {
        $values[] = $gradeCounts[$grade] ?? 0;
    }

    $response = [
        'success' => true,
        'labels' => $labels,
        'values' => $values
    ];

    if (!empty($gradeFilter)) {
        $response['sections'] = array_values(array_keys($sections));
    }

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Chart data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}