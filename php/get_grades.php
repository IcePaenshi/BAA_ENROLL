<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'grades' => [
        ['subject_name' => 'Mathematics', 'grade' => '92'],
        ['subject_name' => 'Science', 'grade' => '88'],
        ['subject_name' => 'English', 'grade' => '95'],
        ['subject_name' => 'History', 'grade' => '90']
    ]
]);
?>