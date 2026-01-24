<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'payables' => [
        ['description' => 'Tuition Fee - Semester 1', 'due_date' => '2026-01-30', 'amount' => '15000.00'],
        ['description' => 'Miscellaneous Fee', 'due_date' => '2026-02-15', 'amount' => '5000.00'],
        ['description' => 'Book Rental', 'due_date' => '2026-01-15', 'amount' => '2000.00'],
        ['description' => 'Library Fee', 'due_date' => '2026-02-28', 'amount' => '1000.00']
    ]
]);
?>