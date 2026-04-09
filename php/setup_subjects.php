<?php
header('Content-Type: application/json');
session_start();
require_once 'db.php';

// Check permissions - admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

try {
    // Check existing subjects
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM subjects");
    $result = $stmt->fetch();
    $existingCount = $result['count'];

    if ($existingCount === 0) {
        // Add sample subjects for each grade level
        $subjects = [
            // Grade 7
            ['subject_name' => 'English', 'grade_level' => 'Grade 7'],
            ['subject_name' => 'Mathematics', 'grade_level' => 'Grade 7'],
            ['subject_name' => 'Science', 'grade_level' => 'Grade 7'],
            ['subject_name' => 'Social Studies', 'grade_level' => 'Grade 7'],
            ['subject_name' => 'Filipino', 'grade_level' => 'Grade 7'],
            
            // Grade 8
            ['subject_name' => 'English', 'grade_level' => 'Grade 8'],
            ['subject_name' => 'Mathematics', 'grade_level' => 'Grade 8'],
            ['subject_name' => 'Science', 'grade_level' => 'Grade 8'],
            ['subject_name' => 'Social Studies', 'grade_level' => 'Grade 8'],
            ['subject_name' => 'Filipino', 'grade_level' => 'Grade 8'],
            
            // Grade 9
            ['subject_name' => 'English', 'grade_level' => 'Grade 9'],
            ['subject_name' => 'Mathematics', 'grade_level' => 'Grade 9'],
            ['subject_name' => 'Science', 'grade_level' => 'Grade 9'],
            ['subject_name' => 'Social Studies', 'grade_level' => 'Grade 9'],
            ['subject_name' => 'Filipino', 'grade_level' => 'Grade 9'],
            
            // Grade 10
            ['subject_name' => 'English', 'grade_level' => 'Grade 10'],
            ['subject_name' => 'Mathematics', 'grade_level' => 'Grade 10'],
            ['subject_name' => 'Science', 'grade_level' => 'Grade 10'],
            ['subject_name' => 'Social Studies', 'grade_level' => 'Grade 10'],
            ['subject_name' => 'Filipino', 'grade_level' => 'Grade 10'],
            
            // Grade 11
            ['subject_name' => 'English', 'grade_level' => 'Grade 11'],
            ['subject_name' => 'Mathematics', 'grade_level' => 'Grade 11'],
            ['subject_name' => 'Science', 'grade_level' => 'Grade 11'],
            ['subject_name' => 'Social Studies', 'grade_level' => 'Grade 11'],
            ['subject_name' => 'Filipino', 'grade_level' => 'Grade 11'],
            ['subject_name' => 'Electives', 'grade_level' => 'Grade 11'],
            
            // Grade 12
            ['subject_name' => 'English', 'grade_level' => 'Grade 12'],
            ['subject_name' => 'Mathematics', 'grade_level' => 'Grade 12'],
            ['subject_name' => 'Science', 'grade_level' => 'Grade 12'],
            ['subject_name' => 'Social Studies', 'grade_level' => 'Grade 12'],
            ['subject_name' => 'Filipino', 'grade_level' => 'Grade 12'],
            ['subject_name' => 'Electives', 'grade_level' => 'Grade 12'],
        ];

        $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, grade_level) VALUES (?, ?)");
        
        $pdo->beginTransaction();
        foreach ($subjects as $subject) {
            $stmt->execute([$subject['subject_name'], $subject['grade_level']]);
        }
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => count($subjects) . ' subjects added successfully',
            'added' => count($subjects)
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Subjects already exist in the database (' . $existingCount . ' subjects found)',
            'existing_count' => $existingCount
        ]);
    }

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
