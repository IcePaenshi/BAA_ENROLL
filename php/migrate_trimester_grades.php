<?php
require_once __DIR__ . '/db.php';

try {
    $pdo->beginTransaction();

    // Drop old grades table if it exists
    $pdo->exec("DROP TABLE IF EXISTS grades");

    // Create new trimester_grades table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trimester_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            semester TINYINT NOT NULL COMMENT '1, 2, or 3',
            quiz_score DECIMAL(5,2) NULL,
            essay_score DECIMAL(5,2) NULL,
            recitation_score DECIMAL(5,2) NULL,
            periodic_test_score DECIMAL(5,2) NULL,
            attendance_score DECIMAL(5,2) NULL,
            calculated_grade DECIMAL(5,2) NULL,
            is_submitted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY student_subject_sem (student_id, subject_id, semester),
            INDEX idx_subject_semester (subject_id, semester)
        )
    ");

    $pdo->commit();
    echo "Database schema successfully updated for Trimester Grades.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error updating schema: " . $e->getMessage() . "\n";
}
