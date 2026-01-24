<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'announcements' => [
        ['title' => 'Important: Enrollment Schedule', 'content' => 'Enrollment for next school year will begin on March 1, 2026. Please prepare all necessary documents.', 'created_at' => '2026-01-10'],
        ['title' => 'Sports Festival Updates', 'content' => 'The annual sports festival has been rescheduled to January 25, 2026. All students are required to participate.', 'created_at' => '2026-01-08'],
        ['title' => 'Parent-Teacher Conference', 'content' => 'The next parent-teacher conference will be held on February 15, 2026. Please coordinate with your class advisers.', 'created_at' => '2026-01-05']
    ]
]);
?>