<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/get_fee_breakdown.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit();
}

$grade = $_POST['grade'] ?? '';
$newFee = $_POST['new_tuition_fee'] ?? '';

if (!$grade || !is_numeric($newFee) || $newFee < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid grade or tuition fee']);
    exit();
}

try {
    baa_ensure_tuition_fees_table($pdo);

    // Update tuition component only (legacy endpoint)
    $upd = $pdo->prepare("UPDATE tuition_fees SET tuition = ?, updated_by = ? WHERE grade_level = ?");
    $upd->execute([(float) $newFee, (int) $_SESSION['user_id'], $grade]);

    echo json_encode([
        'success' => true,
        'message' => "Tuition fee for $grade updated to ₱" . number_format((float) $newFee, 2)
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
