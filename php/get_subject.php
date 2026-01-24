<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'subjects' => [
        ['subject_name' => 'Mathematics', 'schedule' => 'Mon & Wed, 8:00 AM'],
        ['subject_name' => 'Science', 'schedule' => 'Tue & Thu, 9:30 AM'],
        ['subject_name' => 'English', 'schedule' => 'Mon & Wed, 1:00 PM'],
        ['subject_name' => 'History', 'schedule' => 'Tue & Thu, 2:30 PM']
    ]
]);
?>