<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$grade = $_GET['grade'] ?? '';
if (!$grade) {
    echo json_encode(['success' => false, 'message' => 'Grade required']);
    exit();
}

// Define fee breakdown based on grade
$breakdowns = [
    'Grade 7' => [
        'tuition' => 21175.00,
        'misc' => 14927.50,
        'aircon' => 3000,
        'hsa' => 0,
        'books' => 0
    ],
    'Grade 8' => [
        'tuition' => 21795.73,
        'misc' => 14927.50,
        'aircon' => 3000,
        'hsa' => 0,
        'books' => 0
    ],
    'Grade 9' => [
        'tuition' => 23298.55,
        'misc' => 14927.50,
        'aircon' => 3000,
        'hsa' => 0,
        'books' => 0
    ],
    'Grade 10' => [
        'tuition' => 25159.53,
        'misc' => 16427.50,
        'aircon' => 3000,
        'hsa' => 0,
        'books' => 0
    ],
    'Grade 11' => [
        'tuition' => 27225.00,
        'misc' => 14602.50,
        'aircon' => 3000,
        'hsa' => 0,
        'books' => 0
    ],
    'Grade 12' => [
        'tuition' => 27225.00,
        'misc' => 16452.50,
        'aircon' => 3000,
        'hsa' => 0,
        'books' => 0
    ]
];

if (isset($breakdowns[$grade])) {
    echo json_encode(['success' => true, 'breakdown' => $breakdowns[$grade]]);
} else {
    echo json_encode(['success' => false, 'message' => 'No breakdown for this grade']);
}
?>