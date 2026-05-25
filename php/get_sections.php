<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$grade = trim((string) ($_GET['grade'] ?? ''));

try {
    $params = [];
    $where = "role = 'student' AND grade_level IN ('Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12')";
    if ($grade !== '') {
        $where .= " AND grade_level = ?";
        $params[] = $grade;
    }

    $stmt = $pdo->prepare("SELECT DISTINCT section FROM users WHERE $where AND section IS NOT NULL AND section <> ''");
    $stmt->execute($params);
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $sections = array_values(array_filter(array_map('trim', $sections), fn($s) => $s !== ''));
    sort($sections, SORT_NATURAL | SORT_FLAG_CASE);

    echo json_encode(['success' => true, 'sections' => $sections]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

