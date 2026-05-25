<?php
session_start();
header('Content-Type: application/json');

try {
    require_once 'db.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$section_name = trim($_POST['section_name'] ?? '');
$grade_level  = trim($_POST['grade_level'] ?? '');

if (empty($section_name)) {
    echo json_encode(['success' => false, 'message' => 'Section name is required.']);
    exit();
}
if (empty($grade_level)) {
    echo json_encode(['success' => false, 'message' => 'Grade level is required.']);
    exit();
}

try {
    // Ensure sections table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section_name VARCHAR(100) NOT NULL,
            grade_level VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_section_grade (section_name, grade_level)
        )
    ");

    // Check for duplicate
    $check = $pdo->prepare("SELECT id FROM sections WHERE section_name = :name AND grade_level = :grade");
    $check->execute([':name' => $section_name, ':grade' => $grade_level]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => "Section \"$section_name\" for $grade_level already exists."]);
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO sections (section_name, grade_level) VALUES (:name, :grade)");
    $stmt->execute([':name' => $section_name, ':grade' => $grade_level]);

    echo json_encode(['success' => true, 'message' => 'Section added successfully.', 'id' => $pdo->lastInsertId()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
